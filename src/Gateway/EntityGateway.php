<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use BackedEnum;
use DateTimeImmutable;
use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Context;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\OptimisticLockException;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Identity\Identity;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Metadata\RelationMeta;
use Pixiaoka\Orm\Observability\Clock;
use Pixiaoka\Orm\Observability\ContextInfo;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\EventListener;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\SystemClock;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Observability\SessionEvent;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\Expression;
use Pixiaoka\Orm\Relation\Loader;
use Pixiaoka\Orm\Session;

final class EntityGateway
{
    private readonly RowCache $cache;

    /** @var \WeakMap<Context, bool> 对象身份避免 Fiber 销毁后的数字 ID 重用。 */
    private \WeakMap $inTransactionByContext;

    /** @var \WeakMap<Context, \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>> */
    private \WeakMap $txnStatesByContext;

    /** @var \WeakMap<Context, array<string, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>> 同主键共享最外层基线，禁止中途状态覆盖。 */
    private \WeakMap $txnIdentityStates;

    /** @var \WeakMap<Context, array<string, list<array{new: bool, original: array<string, mixed>, values: array<string, mixed>}|null>>> 同身份插入历史，回滚后按删除关系决定保留或失效。 */
    private \WeakMap $txnNewIdentities;

    /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>|null 插入前身份不完整时的对象快照。 */
    private ?\WeakMap $pendingInsertStates = null;

    /** @var \WeakMap<Context, array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, keys: list<string>, entities: list<Entity>}> */
    private \WeakMap $txnCacheSnapshots;

    /** @var \WeakMap<Context, bool>|null discard 返回后读取的回滚确认，下一次事务前清理。 */
    private ?\WeakMap $confirmedRollbacks = null;

    /** @var \WeakMap<Context, float> 仅用于物理事务开始事件的耗时。 */
    private \WeakMap $txnStartedAt;

    private readonly EventDispatcher $events;

    private readonly Clock $clock;

    private readonly Loader $relations;

    /** @var \WeakMap<Connection, string> 开始、提交或回滚结果未知后，连接及所有 Gateway 都不可复用。 */
    private static ?\WeakMap $failedConnections = null;

    /** @var \WeakMap<Connection, int> 观测回调不是查询或业务写入入口，嵌套事件保留外层保护。 */
    private static ?\WeakMap $observingConnections = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataReader $metadata = new MetadataReader(),
        private readonly Caster $caster = new Caster(),
        ?Clock $clock = null,
    ) {
        $this->cache = new RowCache();
        $this->inTransactionByContext = new \WeakMap();
        $this->txnStatesByContext = new \WeakMap();
        $this->txnIdentityStates = new \WeakMap();
        $this->txnNewIdentities = new \WeakMap();
        $this->pendingInsertStates ??= new \WeakMap();
        $this->txnCacheSnapshots = new \WeakMap();
        $this->txnStartedAt = new \WeakMap();
        $this->events = new EventDispatcher();
        $this->clock = $clock ?? new SystemClock();
        $this->relations = new Loader($this);
    }

    /** 无监听器时只保留一次空列表判断；返回的闭包可幂等取消订阅。 */
    public function subscribe(EventListener|callable $listener): \Closure
    {
        return $this->events->subscribe($listener);
    }

    /** @internal 不公开 Context，避免把进程内身份变成持久化或分布式追踪协议。 */
    public function observeSession(string $action, Context $context, ?\Throwable $exception = null): void
    {
        $this->dispatchObserved(fn () => $this->events->dispatch(new SessionEvent(
            $action,
            new ContextInfo($context->type(), spl_object_id($context)),
            $exception === null,
            $exception === null ? null : $exception::class,
        )));
    }

    public function connection(): Connection { return $this->connection; }

    /** @param class-string<Entity> $class */
    public function clearCache(string $class): void
    {
        $this->cache->clearClass($class);
    }

    /** @param class-string<Entity> $class */
    public function meta(string $class): ModelMeta
    {
        return $this->metadata->read($class);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    public function relation(string $class, string $name): RelationMeta
    {
        return $this->metadata->relation($class, $name);
    }

    /** @internal @return array<string, RelationMeta> */
    public function relationDefinitions(string $class): array
    {
        return $this->metadata->relations($class);
    }

    /** @internal 禁止把一个 Gateway 的实体交给另一个连接隐式查询。 */
    public function assertSame(EntityGateway $gateway, string $operation): void
    {
        if ($this !== $gateway) {
            throw new \InvalidArgumentException($operation . '必须使用同一个 EntityGateway');
        }
    }

    /** @internal 关系图优先复用本次主查询对象，其次复用 canonical 缓存对象。 */
    public function relationTarget(string $class, array $identity): ?Entity
    {
        return $this->cache->get($class, $identity);
    }

    /** @internal 将 Loader 已按主键顺序转换的值映射为 RowCache 的列身份。 */
    public function relationIdentity(string $class, array $values): array
    {
        $identity = [];
        $primary = $this->meta($class)->primaryKey;
        if (count($primary) !== count($values)) {
            throw new \LogicException($class . ' 的关系目标主键不完整');
        }
        foreach ($primary as $index => $field) {
            if (!array_key_exists($index, $values) || (!is_scalar($values[$index]) && $values[$index] !== null)) {
                throw new \LogicException($class . ' 的关系目标主键必须是完整标量元组');
            }
            $identity[$field->column] = $values[$index];
        }

        return $identity;
    }

    /** @internal 只为失败恢复创建快照，不因此引入普通查询的 identity map。 */
    public function relationCacheSnapshot(): array
    {
        return $this->cache->transactionSnapshot();
    }

    /** @internal 整棵关系树成功后才允许发布目标，子查询本身不修改缓存。 */
    public function loadRelations(array $entities, string|array $relations): void
    {
        if ($entities !== []) {
            $this->metadata->assertRelational($entities[0]::class);
        }
        foreach ($entities as $entity) {
            $this->forEntity($entity);
        }
        $this->relations->load($entities, $relations);
    }

    /** @internal 在任何字段或关系合并前登记事务基线，而不是登记已经合并的新状态。 */
    public function recordRelationState(Entity $entity): void
    {
        $this->rememberEntity($entity);
    }

    /** @internal 成功时登记旧状态供事务回滚，再把关系目标设为 canonical 缓存对象。 */
    public function publishRelationTarget(Entity $entity): void
    {
        $this->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    /** @internal 首次接触实体时建立弱归属，后续禁止跨连接或过期隐式换绑。 */
    public function forEntity(Entity $entity): self
    {
        if (!$entity->hasGatewayBinding()) {
            $entity->bindGateway($this);
        } else {
            $bound = $entity->boundGateway()
                ?? throw new \LogicException($entity::class . ' 原 Gateway 已销毁，请在新 Gateway 上显式重查');
            $this->assertSame($bound, '实体操作');
        }

        return $this;
    }

    /** @internal 失败关系图只恢复快照，不能把查询失败的实体当成新数据发布。 */
    public function restoreRelationCache(array $snapshot): void
    {
        $this->cache->restoreSnapshot($snapshot);
    }

    /** @internal 关系条件作为隔离 SQL 片段参与 Builder 的正常参数重编号。 */
    public function constrainRelation(array $fields, array $values): Expression
    {
        if (count($fields) !== count($values)) {
            throw new \LogicException('关系键和值数量不一致');
        }
        $parts = [];
        $params = [];
        foreach ($fields as $index => $field) {
            $name = 'relation_' . $index;
            $parts[] = $this->quote($field->column) . ' = :' . $name;
            $params[$name] = $values[$index];
        }

        return new Expression(implode(' AND ', $parts), $params);
    }

    /** @internal 元组条件仅由受控列标识符和命名占位符组成。 */
    public function constrainRelationTuples(array $fields, array $tuples): Expression
    {
        $params = [];
        $groups = [];
        $index = 0;
        foreach ($tuples as $tuple) {
            $parts = [];
            foreach ($fields as $offset => $field) {
                $name = 'relation_' . $index++;
                $parts[] = $this->quote($field->column) . ' = :' . $name;
                $params[$name] = $tuple[$offset];
            }
            $groups[] = '(' . implode(' AND ', $parts) . ')';
        }

        return new Expression('(' . implode(' OR ', $groups) . ')', $params);
    }

    /** 只有方言相关操作才要求连接显式提供驱动，保持旧 Connection 装饰器兼容。 */
    public function driver(): string
    {
        if (!$this->connection instanceof DriverAwareConnection) {
            throw new \LogicException('该操作需要连接实现 DriverAwareConnection，以选择正确的 SQL 方言');
        }

        return $this->connection->driver();
    }

    // ---- 插入 ----

    public function insert(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $generated = $meta->generatedField();
        $this->rememberEntity($entity);
        $this->fillTimestamp($entity, $meta, 'created_at');
        $this->fillTimestamp($entity, $meta, 'updated_at');
        $this->fillVersion($entity, $meta);
        $entity->beforeSave();
        $entity->beforeInsert();
        $params = [];

        foreach ($meta->fields as $field) {
            if ($field->generated) {
                if ($entity->propSet($field->property)) {
                    throw new IdentityException($meta->class . ' 生成列由数据库填写，调用方不能提供');
                }
                continue;
            }
            if (!$entity->propSet($field->property)) {
                throw new IdentityException($meta->class . '::' . $field->property . ' 插入前必须有值');
            }
            $params[$field->column] = $this->caster->toDb($meta->class, $field, $entity->{$field->property});
        }
        $version = $meta->versionField();
        if ($version !== null) {
            $this->versionValue($meta, $version, $entity->{$version->property});
        }

        if ($meta->identityKind === 'natural') {
            $this->identityOf($entity, $meta);
        }

        $columns = array_keys($params);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($meta->table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        );

        // P0-3：pg/sqlite 走 INSERT ... RETURNING，直接取回生成列，
        // 不依赖 lastInsertId（PG 下无序列名时 lastInsertId 不可靠）。
        if ($generated !== null && $this->connection->supportsReturning()) {
            $row = $this->runQueryOne(
                $sql . ' RETURNING ' . $this->quote($generated->column),
                $params,
                $meta->class,
            );
            $id = $row === null ? '' : (string) ($row[$generated->column] ?? '');
        } else {
            $this->runExecute($sql, $params, $meta->class);
            $id = $generated === null ? '' : $this->connection->lastInsertId();
        }

        if ($generated === null) {
            $entity->markPersisted($this);
            $entity->afterSave();
            $entity->afterInsert();
            $this->rememberEntity($entity);
            $this->cacheSet($entity, true);

            return;
        }

        if ($id === '' || $id === '0') {
            throw new IdentityException($meta->class . ' 行可能已写入，但生成列没有回填', true);
        }

        $entity->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        $entity->markPersisted($this);
        $entity->afterSave();
        $entity->afterInsert();
        $this->rememberEntity($entity);
        $this->cacheSet($entity, true);
    }

    /**
     * @param class-string<Entity> $class
     * @param list<Entity|array<string, mixed>> $rows
     */
    public function batchInsert(string $class, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $meta = $this->metadata->read($class);
        $generated = $meta->generatedField();
        $fieldList = [];
        foreach ($meta->fields as $field) {
            if ($field->generated) {
                continue;
            }
            $fieldList[] = $field;
        }
        if ($fieldList === []) {
            return;
        }

        $rows = array_values($rows);
        $entities = [];
        $prepared = [];
        $now = time();
        // 数组值、实体类型、新旧状态和归属先预检；失败批次不提前绑定新实体。
        foreach ($rows as $index => $row) {
            if (!$row instanceof Entity) {
                $prepared[$index] = $this->prepareBatchRow($meta, $fieldList, $row, $now);
                continue;
            }
            if (!$row instanceof $class) {
                throw new \InvalidArgumentException('批量插入实体必须是 ' . $class . '，收到 ' . $row::class);
            }
            if (!$row->isNew()) {
                throw new IdentityException($row::class . ' 已经在库里，不能再批量 insert');
            }
            if ($row->hasGatewayBinding()) {
                $bound = $row->boundGateway()
                    ?? throw new \LogicException($row::class . ' 原 Gateway 已销毁，请在新 Gateway 上显式重查');
                $this->assertSame($bound, '批量插入');
            }
            $entities[$index] = $row;
        }

        // P0-3：多行含实体 + 生成列时，RETURNING 是唯一可靠的批量回填途径。
        if ($generated !== null && $entities !== [] && count($rows) > 1 && !$this->connection->supportsReturning()) {
            throw new IdentityException(
                $meta->class . ' 该连接不支持 RETURNING，无法批量回填生成列，请逐行 insert',
                true,
            );
        }

        // 钩子可以补必填字段，但必须在首条 SQL 前完成整批最终值物化。
        // 事务内先保存钩子及自动填值之前的新实体基线；预检失败不假装撤销业务副作用。
        foreach ($entities as $index => $row) {
            $this->rememberEntity($row);
            $this->fillTimestamp($row, $meta, 'created_at');
            $this->fillTimestamp($row, $meta, 'updated_at');
            $this->fillVersion($row, $meta);
            $row->beforeSave();
            $row->beforeInsert();
            $prepared[$index] = $this->prepareBatchRow($meta, $fieldList, $row, $now);
        }
        ksort($prepared);

        // SQL 严格保留原始混批行序。RETURNING 的每个结果仍对应全局原始索引，
        // 所有 SQL 成功后才按输入顺序回填实体、执行后置钩子和发布缓存。
        $chunkSize = max(1, min(500, intdiv(65535, max(1, count($fieldList)))));
        $generatedIds = [];
        foreach (array_chunk($prepared, $chunkSize, true) as $chunk) {
            $ids = $this->executeBatchChunk($meta, $generated, $fieldList, array_values($chunk));
            foreach (array_keys($chunk) as $position => $index) {
                $generatedIds[$index] = $ids[$position] ?? null;
            }
        }
        foreach ($entities as $index => $row) {
            $this->publishBatchEntity($row, $meta, $generated, $generatedIds[$index] ?? null);
        }
    }

    /** @param list<FieldMeta> $fieldList @return list<int|float|string|null> */
    private function prepareBatchRow(ModelMeta $meta, array $fieldList, array|Entity $row, int $now): array
    {
        $isEntity = $row instanceof Entity;
        $values = [];
        foreach ($fieldList as $field) {
            $isTimestamp = in_array($field->property, $meta->timestamps, true);
            $isVersion = $field->property === $meta->version;
            $has = $isEntity
                ? $row->propSet($field->property)
                : (array_key_exists($field->column, $row) || array_key_exists($field->property, $row));
            if (($isTimestamp || $isVersion) && !$has) {
                $value = $isVersion ? 1 : $this->currentTimestamp($field, $now);
            } elseif ($has) {
                $value = $isEntity
                    ? $row->{$field->property}
                    : (array_key_exists($field->column, $row) ? $row[$field->column] : $row[$field->property]);
            } else {
                throw new IdentityException($meta->class . '::' . $field->property . ' 批量插入缺少列');
            }
            $values[] = $this->toDbValue($meta, $field, $value);
            if ($isVersion) {
                $version = $meta->versionField() ?? throw new \LogicException('版本元数据不一致');
                $this->versionValue($meta, $version, $this->caster->toPhp($meta->class, $version, $value));
            }
        }

        return $values;
    }

    /** SQL 成功后才建立新实体的 Gateway 归属并发布身份；后置钩子失败不宣称原子撤销。 */
    private function publishBatchEntity(
        Entity $row,
        ModelMeta $meta,
        ?FieldMeta $generated,
        ?string $id,
    ): void {
        if ($generated !== null && !$row->propSet($generated->property)) {
            if ($id === null || $id === '' || $id === '0') {
                throw new IdentityException($meta->class . ' 行可能已写入，但生成列没有回填', true);
            }
            $row->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        }
        $row->markPersisted($this);
        $row->afterSave();
        $row->afterInsert();
        $this->rememberEntity($row);
        $this->cacheSet($row, true);
    }

    /**
     * 执行已完整预检的分块；这里不再运行钩子或修改实体。
     *
     * @param list<FieldMeta> $fieldList
     * @param list<list<int|float|string|null>> $chunk
     * @return list<string|null>
     */
    private function executeBatchChunk(
        ModelMeta $meta,
        ?FieldMeta $generated,
        array $fieldList,
        array $chunk,
    ): array {
        $columns = array_map(fn (FieldMeta $f): string => $this->quote($f->column), $fieldList);
        $paramIndex = 0;
        $valueGroups = [];
        $params = [];
        foreach ($chunk as $values) {
            $placeholders = [];
            foreach ($values as $value) {
                $placeholder = ':b' . $paramIndex++;
                $placeholders[] = $placeholder;
                $params[substr($placeholder, 1)] = $value;
            }
            $valueGroups[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quote($meta->table),
            implode(', ', $columns),
            implode(', ', $valueGroups),
        );

        if ($generated !== null && $this->connection->supportsReturning()) {
            // P0-3：RETURNING 拿回本块全部生成列值，行序即结果序
            $returned = $this->runQueryAll(
                $sql . ' RETURNING ' . $this->quote($generated->column),
                $params,
                $meta->class,
            );

            return array_map(
                static fn (array $r): string => (string) ($r[$generated->column] ?? ''),
                $returned,
            );
        }

        // 不支持 RETURNING：多行带生成列已在入口拒绝；单行用 lastInsertId 兜底
        $this->runExecute($sql, $params, $meta->class);
        $ids = array_fill(0, count($chunk), null);
        if ($generated !== null && count($chunk) === 1) {
            $id = $this->connection->lastInsertId();
            $ids[0] = ($id === '' || $id === '0') ? null : $id;
        }

        return $ids;
    }

    /**
     * P1-2：Decimal / DateTimeImmutable / BackedEnum 不做 toPhp→toDb 双重转换——
     * 已是目标类型的值直接 toDb；裸值只走一次 toPhp 归一化再 toDb。
     * 标量（int/float/string/bool/array）保留往返，数组行裸值（如 string '5'）借此归一化，无损。
     */
    private function toDbValue(ModelMeta $meta, FieldMeta $field, mixed $value): int|float|string|null
    {
        $name = $field->typeName();
        $complex = !$field->type->isBuiltin()
            && ($name === DateTimeImmutable::class
                || is_a($name, Decimal::class, true)
                || is_a($name, BackedEnum::class, true));
        if ($complex && $value instanceof $name) {
            return $this->caster->toDb($meta->class, $field, $value);
        }

        return $this->caster->toDb($meta->class, $field, $this->caster->toPhp($meta->class, $field, $value));
    }

    // ---- 更新 —— 只改脏字段 ----

    public function update(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->rememberEntity($entity);
        $entity->beforeSave();
        $entity->beforeUpdate();

        $original = $entity->takeOriginal();
        $expectedVersion = $this->expectedVersion($entity, $meta, $original);
        $this->fillUpdatedAt($entity, $meta, $original);
        $sets = [];
        $params = [];

        foreach ($meta->fields as $field) {
            if ($field->primary || $field->property === $meta->version) {
                continue;
            }
            if (!$entity->propSet($field->property)) {
                continue;
            }
            $new = $entity->{$field->property};
            // 只改真正变了的字段
            if (array_key_exists($field->property, $original) && $new === $original[$field->property]) {
                continue;
            }
            $sets[] = $this->quote($field->column) . ' = :set_' . $field->column;
            $params['set_' . $field->column] = $this->caster->toDb($meta->class, $field, $new);
        }

        if ($sets === []) {
            if ($expectedVersion !== null) {
                $entity->{$meta->version} = $expectedVersion;
            }
            $entity->markPersisted($this);

            return;
        }

        if ($expectedVersion !== null) {
            $version = $meta->versionField() ?? throw new \LogicException('版本元数据不一致');
            $sets[] = $this->quote($version->column) . ' = ' . $this->quote($version->column) . ' + 1';
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quote($meta->table),
            implode(', ', $sets),
            $this->where($meta, $params, $identity),
        );
        if ($expectedVersion !== null) {
            $sql .= ' AND ' . $this->quote($meta->versionField()->column) . ' = :orm_version';
            $params['orm_version'] = $expectedVersion;
        }
        $affected = $this->runExecute($sql, $params, $meta->class);
        if ($expectedVersion !== null && $affected !== 1) {
            throw new OptimisticLockException($meta->class, 'UPDATE', $expectedVersion);
        }
        if ($expectedVersion !== null) {
            $entity->{$meta->version} = $expectedVersion + 1;
        }
        $entity->markPersisted($this);
        $entity->afterSave();
        $entity->afterUpdate();
        $this->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    // ---- 删除 ----

    public function delete(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->rememberEntity($entity);
        $entity->beforeDelete();
        $original = $entity->takeOriginal();
        $expectedVersion = $this->expectedVersion($entity, $meta, $original);
        $params = [];
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quote($meta->table),
            $this->where($meta, $params, $identity),
        );
        if ($expectedVersion !== null) {
            $version = $meta->versionField() ?? throw new \LogicException('版本元数据不一致');
            $sql .= ' AND ' . $this->quote($version->column) . ' = :orm_version';
            $params['orm_version'] = $expectedVersion;
        }
        $affected = $this->runExecute($sql, $params, $meta->class);
        if ($expectedVersion !== null && $affected !== 1) {
            throw new OptimisticLockException($meta->class, 'DELETE', $expectedVersion);
        }
        if ($expectedVersion === null && $affected !== 1) {
            throw new IdentityException($meta->class . ' 删除没有命中恰好一行');
        }
        $context = Session::context();
        if ($this->inTransactionByContext[$context] ?? false) {
            $key = $entity::class . ':' . serialize($identity->parts);
            $history = $this->txnNewIdentities[$context] ?? [];
            if (isset($history[$key])) {
                $baseline = array_pop($history[$key]);
                if ($history[$key] === []) {
                    unset($history[$key]);
                }
                $this->txnNewIdentities[$context] = $history;
                if ($baseline !== null) {
                    $identities = $this->txnIdentityStates[$context] ?? [];
                    $identities[$key] ??= $baseline;
                    $this->txnIdentityStates[$context] = $identities;
                }
            }
        }
        $entity->markDeleted();
        $entity->afterDelete();
        $this->cacheClear($entity, $identity);
    }

    // ---- 查找 —— 带缓存 ----

    /** @return Entity|null */
    public function find(string $class, array $key): ?Entity
    {
        $meta = $this->metadata->read($class);
        $identity = $this->identityFrom($meta, $key);
        $cached = $this->cache->get($class, $identity->parts);
        if ($cached !== null) {
            $this->forEntity($cached);

            return $cached;
        }
        $params = [];
        $columns = array_map(
            fn (FieldMeta $field): string => $this->quote($field->column),
            array_values($meta->fields),
        );
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s',
            implode(', ', $columns),
            $this->quote($meta->table),
            $this->where($meta, $params, $identity),
        );
        $row = $this->runQueryOne($sql, $params, $meta->class);
        if ($row === null) {
            return null;
        }

        $entity = $this->hydrate($meta, $row);
        $this->forEntity($entity);
        $this->rememberEntity($entity);
        $this->cacheSet($entity);

        return $entity;
    }

    public function toArray(Entity $entity): array
    {
        $meta = $this->metadata->read($entity::class);
        $arr = [];

        foreach ($meta->fields as $field) {
            if (!$entity->propSet($field->property)) {
                continue;
            }
            $value = $entity->{$field->property};
            $arr[$field->column] = $value === null
                ? null
                : $this->caster->toDb($meta->class, $field, $value);
        }

        return $arr;
    }

    public function refresh(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->rememberEntity($entity);
        $params = [];
        $columns = array_map(
            fn (FieldMeta $field): string => $this->quote($field->column),
            array_values($meta->fields),
        );
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s',
            implode(', ', $columns),
            $this->quote($meta->table),
            $this->where($meta, $params, $identity),
        );
        $row = $this->runQueryOne($sql, $params, $meta->class);
        if ($row === null) {
            throw new IdentityException($meta->class . ' 行不存在，无法刷新');
        }

        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                throw new IdentityException($meta->class . '::' . $field->property . ' 读出的行缺少列');
            }
            $entity->{$field->property} = $this->caster->toPhp($meta->class, $field, $row[$field->column]);
        }
        $entity->markPersisted($this);
        $entity->clearLoadedRelations();
        $this->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    public function identityComplete(Entity $entity): bool
    {
        try {
            $this->identityOf($entity, $this->metadata->read($entity::class));

            return true;
        } catch (IdentityException) {
            return false;
        }
    }

    // ---- 复杂查询与条件写入 ----

    /**
     * 将模型字段的查询值转换到数据库表示；JOIN 的其他表字段保留标量原值。
     * 此处只处理基表的无歧义字段，带表别名或表名的列由调用方传数据库标量。
     */
    public function castQueryValue(string $class, string $column, mixed $value): int|float|string|bool|null
    {
        if ($value === null || is_scalar($value)) {
            $field = $this->queryField($class, $column);
            if ($field === null) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('查询值必须是标量或 null');
                }

                return $value;
            }

            return $this->toDbValue($this->meta($class), $field, $value);
        }

        $field = $this->queryField($class, $column)
            ?? throw new \InvalidArgumentException('对象查询值必须对应一个基表模型字段: ' . $column);

        return $this->toDbValue($this->meta($class), $field, $value);
    }

    private function queryField(string $class, string $column): ?FieldMeta
    {
        if ($column === '*' || str_contains($column, '.') || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            return null;
        }
        foreach ($this->meta($class)->fields as $field) {
            if ($field->property === $column || $field->column === $column) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function executeRows(string $class, array $query): array
    {
        $this->assertLockSupported($query['sql']);

        return $this->runQueryAll($query['sql'], $query['params'], $class);
    }

    public function executeRow(string $class, array $query): ?array
    {
        $this->assertLockSupported($query['sql']);

        return $this->runQueryOne($query['sql'], $query['params'], $class);
    }

    public function executeValue(string $class, array $query, ?string $column = null): mixed
    {
        $row = $this->executeRow($class, $query);
        if ($row === null) {
            return null;
        }
        if ($column !== null) {
            if (!array_key_exists($column, $row)) {
                throw new \UnexpectedValueException('查询结果没有列: ' . $column);
            }

            return $row[$column];
        }

        return array_values($row)[0] ?? null;
    }

    /** 执行结构化 COUNT（含 GROUP/UNION 的派生表计数）。 */
    public function executeCountQuery(array $query): int
    {
        $this->assertLockSupported($query['sql']);
        $row = $this->runQueryOne($query['sql'], $query['params']);
        if ($row === null) {
            return 0;
        }
        if (array_key_exists('orm_count', $row)) {
            return (int) $row['orm_count'];
        }

        return (int) (array_values($row)[0] ?? 0);
    }

    public function executeUpdate(Builder $builder, array $values): int
    {
        $this->assertVersionWrite($this->metadata->read($builder->modelClass()), '批量条件 UPDATE');
        $query = $builder->buildUpdate($values);
        $affected = $this->runExecute($query['sql'], $query['params'], $builder->modelClass());
        $this->cache->clearClass($this->metadata->read($builder->modelClass())->class);

        return $affected;
    }

    public function executeDelete(Builder $builder): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $this->assertVersionWrite($meta, '批量条件 DELETE');
        $class = $meta->class;
        $query = $builder->buildDelete();
        if ($this->connection->supportsReturning()) {
            $sql = $query['sql'] . ' RETURNING ' . implode(', ', array_map(
                $this->quote(...),
                $meta->primaryColumns(),
            ));
            $deleted = $this->runQueryAll($sql, $query['params'], $class);
            $this->cache->clearDeleted($class, $this->normalizeIdentities($meta, $deleted));

            return count($deleted);
        }

        // MySQL 没有 DELETE ... RETURNING。按已缓存主键做一次受限预查，
        // 成功后只失效候选身份；并发插入/更新与缓存一致性边界见使用文档。
        $candidates = $this->deletedIdentityQuery($meta, $builder);
        $deleted = $candidates === null
            ? []
            : $this->runQueryAll($candidates['sql'], $candidates['params'], $class);
        $affected = $this->runExecute($query['sql'], $query['params'], $class);
        if ($affected > 0) {
            $this->cache->clearDeleted($class, $this->normalizeIdentities($meta, $deleted));
        }

        return $affected;
    }

    /**
     * MySQL 回退：只选取当前缓存中、且符合 DELETE 条件的候选主键，不扫描整表。
     * 原始 DELETE 已提前构建，预查阶段能再被拒绝，就不会执行任何写操作。
     *
     * @return array{sql: string, params: array<string, mixed>}|null
     */
    private function deletedIdentityQuery(ModelMeta $meta, Builder $builder): ?array
    {
        $identities = $this->cache->identities($meta->class);
        if ($identities === []) {
            return null;
        }
        $primary = $meta->primaryColumns();
        $sets = [];
        $params = [];
        $index = 0;
        foreach ($identities as $identity) {
            $parts = [];
            foreach ($primary as $column) {
                $name = 'cached_' . $index++;
                $parts[] = $this->quote($column) . ' = :' . $name;
                $params[$name] = $identity[$column];
            }
            $sets[] = '(' . implode(' AND ', $parts) . ')';
        }

        return (clone $builder)->select(...$primary)
            ->whereRaw('(' . implode(' OR ', $sets) . ')', $params)
            ->build();
    }

    /** 将驱动返回的主键值按缓存写入时的同一路径规范化（bool/enum/int 等）。 */
    private function normalizeIdentities(ModelMeta $meta, array $rows): array
    {
        $identities = [];
        foreach ($rows as $row) {
            $identity = [];
            foreach ($meta->primaryKey as $field) {
                if (!array_key_exists($field->column, $row)) {
                    throw new IdentityException($meta->class . ' DELETE RETURNING 缺少主键列 ' . $field->column);
                }
                $identity[$field->column] = $this->toDbValue($meta, $field, $row[$field->column]);
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /** @return list<array<string, mixed>> 原生 SELECT/CTE 查询，调用方负责 SQL 的正确性。 */
    public function selectRaw(string|Expression $sql, array $params = []): array
    {
        $query = $this->rawQuery($sql, $params);

        return $this->runQueryAll($query['sql'], $query['params']);
    }

    public function selectOneRaw(string|Expression $sql, array $params = []): ?array
    {
        $query = $this->rawQuery($sql, $params);

        return $this->runQueryOne($query['sql'], $query['params']);
    }

    /** 原生 INSERT/UPDATE/DELETE/DDL；SELECT 请使用 selectRaw() 以获取结果。 */
    public function executeRaw(string|Expression $sql, array $params = []): int
    {
        $query = $this->rawQuery($sql, $params);

        return $this->runExecute($query['sql'], $query['params']);
    }

    /** @return array{sql: string, params: array<string, mixed>} */
    private function rawQuery(string|Expression $sql, array $params): array
    {
        if ($sql instanceof Expression) {
            if ($params !== []) {
                throw new \InvalidArgumentException('Expression 已携带参数，不要再传 $params');
            }
            $query = $sql->build();
            $this->assertNamedParams($query['sql'], $query['params']);
            foreach ($query['params'] as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('原生 SQL 参数值必须是标量或 null');
                }
            }

            return $query;
        }
        if (trim($sql) === '') {
            throw new \InvalidArgumentException('原生 SQL 不能为空');
        }
        $this->assertNamedParams($sql, $params);
        foreach ($params as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('原生 SQL 参数值必须是标量或 null');
            }
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function assertNamedParams(string $sql, array $params): void
    {
        foreach ($params as $key => $_) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new \InvalidArgumentException('原生 SQL 参数必须使用命名占位符');
            }
        }
        // 用于识别命名占位符的状态机：忽略字符串、标识符、注释、PG dollar quote 和 :: 类型转换。
        $used = [];
        $length = strlen($sql);
        $state = 'sql';
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($state !== 'sql') {
                if (str_starts_with($state, '$')) {
                    if (substr($sql, $i, strlen($state)) === $state) {
                        $i += strlen($state) - 1;
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'line') {
                    if ($char === "\n" || $char === "\r") {
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'block') {
                    if ($char === '*' && $next === '/') {
                        $state = 'sql';
                        $i++;
                    }
                    continue;
                }
                if ($char === $state) {
                    if ($next === $char && $state !== '`') {
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }
            if ($char === '$' && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $match) === 1) {
                $state = $match[0];
                $i += strlen($state) - 1;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $state = $char;
            } elseif ($char === '-' && $next === '-') {
                $state = 'line';
                $i++;
            } elseif ($char === '#') {
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $state = 'block';
                $i++;
            } elseif ($char === ':' && $next === ':') {
                $i++;
            } elseif ($char === ':' && preg_match('/[A-Za-z_]/', $next) === 1) {
                $j = $i + 1;
                while ($j + 1 < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j + 1]) === 1) {
                    $j++;
                }
                $used[] = substr($sql, $i + 1, $j - $i);
                $i = $j;
            } elseif ($char === '?') {
                throw new \InvalidArgumentException('原生 SQL 请使用命名占位符，避免与复杂查询参数合并时错位');
            }
        }
        $used = array_values(array_unique($used));
        sort($used);
        $provided = array_keys($params);
        sort($provided);
        if ($used !== $provided) {
            throw new \InvalidArgumentException(sprintf(
                '原生 SQL 参数不匹配，SQL 需要 [%s]，实际提供 [%s]',
                implode(', ', $used),
                implode(', ', $provided),
            ));
        }
    }

    private function assertLockSupported(string $sql): void
    {
        if ($this->connection instanceof DriverAwareConnection
            && $this->driver() === 'sqlite'
            && preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $sql) === 1) {
            throw new \LogicException('SQLite 不支持 FOR UPDATE / FOR SHARE，请使用事务');
        }
    }

    /**
     * 跨方言 UPSERT。$updateColumns=[] 表示冲突时忽略；null 表示更新其余已提供字段。
     * 默认冲突键是模型主键。代理键只有显式包含在 $conflictColumns 中才允许写入。
     *
     * @param class-string<Entity> $class
     * @param array<string, mixed> $values 属性名或真实列名 => 值
     * @param list<string>|null $updateColumns
     * @param list<string>|null $conflictColumns
     */
    public function upsert(string $class, array $values, ?array $updateColumns = null, ?array $conflictColumns = null): int
    {
        $meta = $this->metadata->read($class);
        $this->assertVersionWrite($meta, 'UPSERT');
        if ($values === []) {
            throw new \InvalidArgumentException('UPSERT 至少需要一个字段');
        }
        $fields = [];
        foreach ($meta->fields as $field) {
            $fields[$field->property] = $field;
            $fields[$field->column] = $field;
        }
        $resolve = static function (string $name) use ($fields, $class): FieldMeta {
            return $fields[$name] ?? throw new \InvalidArgumentException($class . ' 未知字段: ' . $name);
        };
        $conflictColumns ??= $meta->primaryColumns();
        if ($conflictColumns === []) {
            throw new \InvalidArgumentException('UPSERT 必须指定冲突键');
        }
        $conflicts = [];
        foreach ($conflictColumns as $column) {
            $field = $resolve($column);
            $conflicts[$field->column] = $field;
        }
        $insert = [];
        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException('UPSERT 字段名必须是字符串');
            }
            $field = $resolve($column);
            if (isset($insert[$field->column])) {
                throw new \InvalidArgumentException('UPSERT 字段重复: ' . $field->column);
            }
            if ($field->generated && !isset($conflicts[$field->column])) {
                throw new IdentityException($class . ' 代理键只有作为显式 UPSERT 冲突键时才可写入');
            }
            $insert[$field->column] = $this->toDbValue($meta, $field, $value);
        }
        foreach ($conflicts as $column => $_) {
            if (!array_key_exists($column, $insert)) {
                throw new IdentityException($class . ' UPSERT 缺少冲突键 ' . $column);
            }
        }
        $updates = [];
        $createdAtField = $meta->timestampField('created_at');
        $createdAt = $createdAtField === null ? [] : [$createdAtField->column];
        foreach (['created_at', 'updated_at'] as $timestamp) {
            $field = $meta->timestampField($timestamp);
            if ($field !== null && !array_key_exists($field->column, $insert)) {
                $insert[$field->column] = $this->toDbValue($meta, $field, $this->currentTimestamp($field));
            }
        }
        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $insert) && !$field->generated && !$field->nullable()) {
                throw new IdentityException($class . ' UPSERT 缺少非空字段 ' . $field->column);
            }
        }
        if ($updateColumns !== []) {
            $updateColumns ??= array_values(array_diff(
                array_keys($insert),
                array_keys($conflicts),
                // 创建时间只在插入时写入；即使显式传值，冲突更新也不覆盖。
                $createdAt,
            ));
            $updateColumns = array_values(array_diff($updateColumns, $createdAt));
            if ($updateColumns !== []) {
                $updateColumns = array_map(
                    static fn (string $column): string => $resolve($column)->column,
                    $updateColumns,
                );
                $updatedAt = $meta->timestampField('updated_at')?->column;
                if ($updatedAt !== null
                    && $updateColumns !== [$updatedAt]
                    && !in_array($updatedAt, $updateColumns, true)) {
                    $updateColumns[] = $updatedAt;
                }
            }
            foreach ($updateColumns as $column) {
                $field = $resolve($column);
                if (isset($conflicts[$field->column]) || $field->generated) {
                    throw new \InvalidArgumentException('UPSERT 不允许更新冲突键或生成列: ' . $field->column);
                }
                if (!array_key_exists($field->column, $insert)) {
                    throw new \InvalidArgumentException('UPSERT 更新字段没有输入值: ' . $field->column);
                }
                $updates[$field->column] = $field;
            }
        }
        if ($updateColumns === null && $updates === []) {
            throw new \InvalidArgumentException('UPSERT 没有可更新字段；如需冲突时忽略请传 $updateColumns=[]');
        }
        $columns = array_keys($insert);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($meta->table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_map(static fn (string $column): string => ':upsert_' . $column, $columns)),
        );
        $params = [];
        foreach ($insert as $column => $value) {
            $params['upsert_' . $column] = $value;
        }
        if ($this->driver() === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE ';
            if ($updates === []) {
                $first = array_key_first($conflicts);
                $sql .= $this->quote($first) . ' = ' . $this->quote($first);
            } else {
                $sets = [];
                foreach ($updates as $column => $_) {
                    $sets[] = $this->quote($column) . ' = VALUES(' . $this->quote($column) . ')';
                }
                $sql .= implode(', ', $sets);
            }
        } else {
            $sql .= ' ON CONFLICT (' . implode(', ', array_map($this->quote(...), array_keys($conflicts))) . ')';
            if ($updates === []) {
                $sql .= ' DO NOTHING';
            } else {
                $sets = [];
                foreach ($updates as $column => $_) {
                    $sets[] = $this->quote($column) . ' = excluded.' . $this->quote($column);
                }
                $sql .= ' DO UPDATE SET ' . implode(', ', $sets);
            }
        }
        $affected = $this->runExecute($sql, $params, $meta->class);
        $this->cache->clearClass($class);

        return $affected;
    }

    /** @throws \Throwable */
    public function transaction(callable $func): mixed
    {
        $this->assertConnectionUsable();
        $ctx = Session::context();
        if ($this->inTransactionByContext[$ctx] ?? false) {
            return $func();
        }

        Session::clearRollbackStatus($ctx);
        if ($this->confirmedRollbacks !== null) {
            unset($this->confirmedRollbacks[$ctx]);
        }
        $this->inTransactionByContext[$ctx] = true;
        if (!isset($this->txnCacheSnapshots[$ctx])) {
            $this->txnCacheSnapshots[$ctx] = $this->captureTransactionCache();
        }
        $this->txnStatesByContext[$ctx] = $this->txnStatesByContext[$ctx] ?? $this->newTransactionStates();
        $this->txnIdentityStates[$ctx] = $this->txnIdentityStates[$ctx] ?? [];
        $this->txnNewIdentities[$ctx] = [];
        foreach ($this->txnCacheSnapshots[$ctx]['entities'] as $entity) {
            $this->rememberEntity($entity);
        }
        $startedAt = $this->clock->now();
        $this->txnStartedAt[$ctx] = $startedAt;
        $begun = false;
        $committed = false;
        $rolledBack = false;
        $discardConfirmed = false;
        $failure = null;
        $transactionFailure = null;
        $rollbackFailure = null;
        $rollbackAttempted = false;
        $discardAttempted = false;
        $commitAttempted = false;
        $result = null;
        try {
            try {
                Session::claimConnection($this, $ctx);
            try {
                if (!$this->connection->beginTransaction()) {
                    throw new \RuntimeException('数据库事务开始失败');
                }
                $begun = true;
            } catch (\Throwable $e) {
                $failure = $e;
                $this->invalidateConnection();
                $transactionFailure = new TransactionException('开始', null, $e);
                $this->observeTransaction('begin', $ctx, $startedAt, $transactionFailure);
                throw $transactionFailure;
            }
            $this->observeTransaction('begin', $ctx, $startedAt);
            } catch (\Throwable $e) {
                Session::releaseConnection($this, $ctx);
                if ($e instanceof TransactionException) {
                    throw $e;
                }
                throw $e;
            }

            try {
                $result = $func();
                $this->rememberCachedEntities();
                if (Session::currentContext() !== $ctx) {
                    throw new \LogicException('事务回调不能切换 Fiber/协程上下文后提交');
                }
                if (!isset($this->inTransactionByContext[$ctx])) {
                    if (!Session::rollbackConfirmed($ctx)) {
                        $this->invalidateConnection();
                        throw new \LogicException('事务期间 Session 已被清理，不能继续提交；该连接已禁止复用');
                    }
                    $discardConfirmed = true;
                    throw new \LogicException('事务期间 Session 已被清理，操作已回滚，不能继续提交');
                }
                $commitAttempted = true;
                if (!$this->connection->commit()) {
                    throw new \RuntimeException('数据库事务提交失败');
                }
                $committed = true;
            } catch (\Throwable $e) {
                $failure = $e;
                if ($e instanceof TransactionException && !isset($this->inTransactionByContext[$ctx])) {
                    $rollbackAttempted = true;
                    $discardAttempted = true;
                    $rollbackFailure = $e;
                    throw $e;
                }
                if ($commitAttempted) {
                    $this->observeTransaction('commit', $ctx, $startedAt, $e);
                }
                $discardAttempted = Session::rollbackAttempted($ctx);
                if (isset($this->inTransactionByContext[$ctx]) && !$discardAttempted) {
                    $rollbackAttempted = true;
                    try {
                        if (!$this->connection->rollback()) {
                            throw new \RuntimeException('数据库事务回滚失败');
                        }
                        $rolledBack = true;
                        if (!$commitAttempted) {
                            $this->restoreTransaction($ctx);
                        }
                    } catch (\Throwable $rollbackError) {
                        $rollbackFailure = $rollbackError;
                        $this->invalidateConnection();
                    }
                }
                if (($rolledBack || $discardConfirmed) && !$commitAttempted) {
                    throw $failure;
                }
                $this->invalidateConnection();
                $transactionFailure = new TransactionException(
                    $commitAttempted ? '提交' : '回滚',
                    $commitAttempted ? null : $failure,
                    !$commitAttempted && $rollbackFailure !== null ? $rollbackFailure : $failure,
                );
                if ($rollbackAttempted) {
                    $rollbackFailure = $transactionFailure;
                }
                throw $transactionFailure;
            }
        } finally {
            if ($begun && !$committed && !$rolledBack && !$discardConfirmed) {
                $this->invalidateConnection();
            }
            if (!$begun && $failure !== null) {
                $this->invalidateConnection();
                $this->cache->forgetKnown();
            }
            if ($begun && !$committed && $rollbackAttempted && !$discardAttempted) {
                $this->observeTransaction('rollback', $ctx, $startedAt, $rolledBack ? null : $rollbackFailure);
            }
            Session::releaseConnection($this, $ctx);
            $this->clearTransaction($ctx);
            if ($committed) {
                $this->observeTransaction('commit', $ctx, $startedAt);
            }
        }

        return $result;
    }

    /** @internal 由 Session::discard() 调用，回滚并解除当前上下文的事务所有权。 */
    public function releaseContext(Context $context): void
    {
        $active = $this->inTransactionByContext[$context] ?? false;
        $startedAt = $active
            ? ($this->txnStartedAt[$context] ?? $this->clock->now())
            : $this->clock->now();
        $failure = null;
        $transactionFailure = null;
        try {
            if ($active) {
                if (!$this->connection->rollback()) {
                    throw new \RuntimeException('数据库事务回滚失败');
                }
                $this->restoreTransaction($context);
                $this->confirmedRollbacks ??= new \WeakMap();
                $this->confirmedRollbacks[$context] = true;
            }
        } catch (\Throwable $e) {
            $failure = $e;
            $transactionFailure = new TransactionException('回滚', null, $e);
        } finally {
            if ($transactionFailure !== null) {
                $this->invalidateConnection();
            }
            $this->clearTransaction($context);
            if ($active) {
                $this->observeTransaction('rollback', $context, $startedAt, $transactionFailure);
            }
            Session::releaseConnection($this, $context);
        }
        if ($transactionFailure !== null) {
            throw $transactionFailure;
        }
    }

    /** @internal Session 在清理事务状态之后读取一次确认标记。 */
    public function rollbackWasConfirmed(Context $context): bool
    {
        return $this->confirmedRollbacks !== null && isset($this->confirmedRollbacks[$context]);
    }

    /** @internal Session::use() 也必须拒绝复用提交结果未知的连接。 */
    public function assertSessionUsable(): void
    {
        $this->assertConnectionUsable();
    }

    /** @internal 隐式换绑不能代替调用方决定未结束事务的回滚与失败处理。 */
    public function assertSessionReplaceable(Context $context): void
    {
        if ($this->inTransactionByContext[$context] ?? false) {
            throw new \LogicException('当前 Session 存在未结束事务，禁止换绑；请先显式 discard 并处理回滚结果');
        }
    }

    /** @internal discard() 应继续清理失败 Session，但禁止监听器重入清理。 */
    public function assertObservationBoundary(): void
    {
        if (self::$observingConnections !== null && isset(self::$observingConnections[$this->connection])) {
            throw new \LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
        }
    }

    /** 每个 SQL 入口统一检查，包含 MySQL 条件 DELETE 的预查和原生 SQL。 */
    private function assertConnectionUsable(): void
    {
        if (self::$failedConnections !== null && isset(self::$failedConnections[$this->connection])) {
            throw new \LogicException(self::$failedConnections[$this->connection]);
        }
        if (self::$observingConnections !== null && isset(self::$observingConnections[$this->connection])) {
            throw new \LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
        }
    }

    private function invalidateConnection(): void
    {
        self::$failedConnections ??= new \WeakMap();
        self::$failedConnections[$this->connection] = '数据库开始、提交或回滚结果未知，禁止复用当前连接；请丢弃该连接、Gateway 和关联实体';
        $this->cache->flush();
        $this->cache->forgetKnown();
    }

    private function clearTransaction(Context $context): void
    {
        unset(
            $this->inTransactionByContext[$context],
            $this->txnStatesByContext[$context],
            $this->txnIdentityStates[$context],
            $this->txnNewIdentities[$context],
            $this->txnCacheSnapshots[$context],
            $this->txnStartedAt[$context],
        );
    }

    private function restoreTransaction(Context $context): void
    {
        foreach ($this->cache->known() as $entity) {
            $this->rememberEntity($entity, $context);
        }
        foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
            /**
             * @var $entity Entity
             */
            $entity->restoreState($state, $this->metadata->read($entity::class));
        }
        $snapshot = $this->txnCacheSnapshots[$context] ?? null;
        if ($snapshot !== null) {
            $this->cache->restoreSnapshot($snapshot);
        }
        $cachedKeys = $snapshot === null ? [] : array_fill_keys(array_keys($snapshot['items']), true);
        foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
            if ($state['new']) {
                continue;
            }
            $meta = $this->metadata->read($entity::class);
            $parts = [];
            foreach ($meta->primaryKey as $field) {
                $parts[$field->column] = $this->caster->toDb($meta->class, $field, $entity->{$field->property});
            }
            if (!isset($cachedKeys[$this->cache->setKey($entity, $parts)])) {
                $this->cache->set($entity, $parts);
            }
        }
    }

    private function rememberCachedEntities(): void
    {
        foreach ($this->cache->snapshot() as $entity) {
            $this->rememberEntity($entity);
        }
    }

    /** @return array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, keys: list<string>, entities: list<Entity>} */
    private function captureTransactionCache(): array
    {
        /** @var array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, keys: list<string>, entities: list<Entity>} $snapshot */
        $snapshot = $this->cache->transactionSnapshot();
        $snapshot['entities'] = array_values(array_merge($snapshot['items'], $this->cache->known()));

        return $snapshot;
    }

    /** @return \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> */
    private function newTransactionStates(): \WeakMap
    {
        /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> $states */
        $states = new \WeakMap();

        return $states;
    }

    private function rememberEntity(Entity $entity, ?Context $transactionContext = null): void
    {
        $context = $transactionContext ?? Session::context();
        if (!($this->inTransactionByContext[$context] ?? false)) {
            return;
        }
        /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> $states */
        $states = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
        if (isset($states[$entity])) {
            if ($entity->isNew()) {
                $this->pendingInsertStates ??= new \WeakMap();
                $this->pendingInsertStates[$entity] = $states[$entity];
            }

            return;
        }
        $meta = $this->metadata->read($entity::class);
        $pending = $this->txnNewIdentities[$context] ?? [];
        $identities = $this->txnIdentityStates[$context] ?? [];
        $key = $this->identityComplete($entity)
            ? \Pixiaoka\Orm\Cache\RowCache::key($entity::class, $this->identityOf($entity, $meta)->parts)
            : null;
        if ($entity->isNew() || $key === null || !isset($pending[$key])) {
            if (!$entity->isNew()) {
                $identities[$key] ??= $entity->captureState($meta);
                $this->txnIdentityStates[$context] = $identities;
                $states[$entity] = $identities[$key];
            } else {
                $states[$entity] = $entity->captureState($meta);
                $this->pendingInsertStates ??= new \WeakMap();
                $this->pendingInsertStates[$entity] = $states[$entity];
            }
            $this->txnStatesByContext[$context] = $states;

            return;
        }
        $states[$entity] = $identities[$key] ?? ['new' => true, 'original' => [], 'values' => $entity->captureState($meta)['values']];
        $this->txnStatesByContext[$context] = $states;
    }

    private function observeTransaction(string $phase, Context $context, float $started, ?\Throwable $exception = null): void
    {
        $this->dispatchObserved(fn () => $this->events->dispatch(new TransactionEvent(
            $phase,
            $this->contextInfo($context),
            max(0.0, $this->clock->now() - $started),
            $exception === null,
            $exception === null ? null : $exception::class,
        )));
    }

    /** 事件同步发送，但连接级隔离禁止监听器重入 ORM；监听器异常仍由 Dispatcher 隔离。 */
    private function dispatchObserved(callable $dispatch): void
    {
        if (!$this->events->hasListeners()) {
            return;
        }
        self::$observingConnections ??= new \WeakMap();
        $depth = (self::$observingConnections[$this->connection] ?? 0) + 1;
        self::$observingConnections[$this->connection] = $depth;
        try {
            Session::observe($dispatch);
        } finally {
            if ($depth === 1) {
                unset(self::$observingConnections[$this->connection]);
            } else {
                self::$observingConnections[$this->connection] = $depth - 1;
            }
        }
    }

    // ---- 查询执行 —— 带缓存 ----

    /** @return list<Entity> */
    public function executeQuery(string $class, array $query, array $relations = [], bool $cache = true): array
    {
        $this->assertLockSupported($query['sql']);
        $meta = $this->metadata->read($class);
        $cacheSnapshot = $relations !== [] ? $this->cache->transactionSnapshot() : null;
        $rows = $this->runQueryAll($query['sql'], $query['params'], $class);
        $entities = [];
        foreach ($rows as $row) {
            $entity = $this->hydrate($meta, $row);
            $this->forEntity($entity);
            $this->rememberEntity($entity);
            if ($cache && $relations === []) {
                $this->cacheSet($entity);
            }
            $entities[] = $entity;
        }
        if ($relations !== []) {
            try {
                $this->loadRelations($entities, $relations);
                if ($cache) {
                    foreach ($entities as $entity) {
                        $this->cacheSet($entity);
                    }
                }
            } catch (\Throwable $e) {
                $this->cache->restoreSnapshot($cacheSnapshot);
                throw $e;
            }
        }

        return $entities;
    }

    /** @return Entity|null */
    public function executeQueryOne(string $class, array $query, array $relations = []): ?Entity
    {
        $this->assertLockSupported($query['sql']);
        $meta = $this->metadata->read($class);
        $cacheSnapshot = $relations !== [] ? $this->cache->transactionSnapshot() : null;
        $row = $this->runQueryOne($query['sql'], $query['params'], $class);
        if ($row === null) {
            return null;
        }

        $entity = $this->hydrate($meta, $row);
        $this->forEntity($entity);
        $this->rememberEntity($entity);
        if ($relations === []) {
            $this->cacheSet($entity);
        } else {
            try {
                $this->loadRelations([$entity], $relations);
                $this->cacheSet($entity);
            } catch (\Throwable $e) {
                $this->cache->restoreSnapshot($cacheSnapshot);
                throw $e;
            }
        }

        return $entity;
    }

    /**
     * P0-2：直接执行 Builder::countSql() 结构化生成的 COUNT 查询。
     * $column 非 '*' 时把 COUNT(*) 换成 COUNT("col")。
     *
     * @param class-string<Entity> $class
     * @param array{sql: string, params: array<string, mixed>} $query
     */
    public function executeCount(string $class, array $query, string $column): int
    {
        $this->metadata->read($class);
        $sql = $query['sql'];
        if ($column !== '*') {
            $sql = preg_replace('/COUNT\(\*\)/', 'COUNT(' . $this->quote($column) . ')', $sql, 1) ?? $sql;
        }
        $row = $this->runQueryOne($sql, $query['params'], $class);
        if ($row === null) {
            return 0;
        }

        return (int) (array_values($row)[0] ?? 0);
    }

    // ---- 内部 ----

    private function hydrate(ModelMeta $meta, array $row): Entity
    {
        /** @var Entity $entity */
        $entity = (new \ReflectionClass($meta->class))->newInstanceWithoutConstructor();

        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                if ($field->nullable()) {
                    $entity->{$field->property} = null;
                    continue;
                }
                throw new IdentityException($meta->class . '::' . $field->property . ' 读出的行缺少非空列');
            }
            $entity->{$field->property} = $this->caster->toPhp($meta->class, $field, $row[$field->column]);
        }

        $entity->markPersisted($this);

        return $entity;
    }

    private function identityOf(Entity $entity, ModelMeta $meta): Identity
    {
        $parts = [];
        foreach ($meta->primaryKey as $field) {
            if (!$entity->propSet($field->property)) {
                throw new IdentityException($meta->class . ' 缺少主键列 ' . $field->column);
            }
            $value = $entity->{$field->property};
            if ($value === null) {
                throw new IdentityException($meta->class . ' 主键列 ' . $field->column . ' 不可空');
            }
            $parts[$field->column] = $this->caster->toDb($meta->class, $field, $value);
        }

        return new Identity($parts);
    }

    private function identityFrom(ModelMeta $meta, array $key): Identity
    {
        $parts = [];
        foreach ($meta->primaryKey as $field) {
            if (!array_key_exists($field->column, $key)) {
                throw new IdentityException($meta->class . ' 按身份查找缺少主键列 ' . $field->column);
            }
            if ($key[$field->column] === null) {
                throw new IdentityException($meta->class . ' 主键列 ' . $field->column . ' 不可空');
            }
            $php = $this->caster->toPhp($meta->class, $field, $key[$field->column]);
            $parts[$field->column] = $this->caster->toDb($meta->class, $field, $php);
        }
        $unknown = array_diff(array_keys($key), $meta->primaryColumns());
        if ($unknown !== []) {
            throw new IdentityException($meta->class . ' 身份里有不属于主键的列: ' . implode(', ', $unknown));
        }

        return new Identity($parts);
    }

    private function where(ModelMeta $meta, array &$params, Identity $identity): string
    {
        if (!$identity->complete(count($meta->primaryKey))) {
            throw new IdentityException($meta->class . ' 主键列不齐全，拒绝执行');
        }

        $clauses = [];
        foreach ($meta->primaryKey as $field) {
            $placeholder = 'pk_' . $field->column;
            $clauses[] = $this->quote($field->column) . ' = :' . $placeholder;
            $params[$placeholder] = $identity->parts[$field->column];
        }

        return implode(' AND ', $clauses);
    }

    /** 创建时间仅在未设置时填充，显式提供的值不被覆盖。 */
    private function fillVersion(Entity $entity, ModelMeta $meta): void
    {
        $field = $meta->versionField();
        if ($field !== null && !$entity->propSet($field->property)) {
            $entity->{$field->property} = 1;
        }
    }

    private function versionValue(ModelMeta $meta, FieldMeta $field, mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value === PHP_INT_MAX) {
            throw new IdentityException($meta->class . '::' . $field->property . ' 版本必须是大于等于 0 且可递增的 int');
        }

        return $value;
    }

    /** @param array<string, mixed> $original */
    private function expectedVersion(Entity $entity, ModelMeta $meta, array $original): ?int
    {
        $field = $meta->versionField();
        if ($field === null) {
            return null;
        }
        $value = $original[$field->property] ?? null;
        if ($entity->propSet($field->property)
            && array_key_exists($field->property, $original)
            && $entity->{$field->property} !== $value) {
            throw new IdentityException($meta->class . '::' . $field->property . ' 是 ORM 托管的版本字段，不能直接修改；请使用 refresh');
        }
        if (!is_int($value) || $value < 0 || $value === PHP_INT_MAX) {
            throw new IdentityException($meta->class . '::' . $field->property . ' 缺少有效持久化版本，请 refresh 后重试');
        }

        return $value;
    }

    private function assertVersionWrite(ModelMeta $meta, string $operation): void
    {
        if ($meta->version !== null) {
            throw new \LogicException($meta->class . " 使用乐观锁，$operation 无法保留逐实体版本语义；请逐实体 save/delete");
        }
    }

    /** 创建时间仅在未设置时填充，显式提供的值不被覆盖。 */
    private function fillTimestamp(Entity $entity, ModelMeta $meta, string $role): void
    {
        $field = $meta->timestampField($role);
        if ($field !== null && !$entity->propSet($field->property)) {
            $entity->{$field->property} = $this->currentTimestamp($field);
        }
    }

    /**
     * 更新时间是“最后修改时间”语义：调用方本次显式改过则尊重，否则刷新。
     *
     * @param array<string, mixed> $original
     */
    private function fillUpdatedAt(Entity $entity, ModelMeta $meta, array $original): void
    {
        $field = $meta->timestampField('updated_at');
        if ($field === null) {
            return;
        }
        $property = $field->property;
        if ($entity->propSet($property)
            && array_key_exists($property, $original)
            && $entity->{$property} != $original[$property]) {
            return;
        }
        $entity->{$property} = $this->currentTimestamp($field);
    }

    private function currentTimestamp(FieldMeta $field, ?int $now = null): int|DateTimeImmutable
    {
        if ($field->typeName() === 'int') {
            return $now ?? time();
        }

        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function cacheSet(Entity $entity, bool $inserted = false): void
    {
        $meta = $this->metadata->read($entity::class);
        $parts = [];
        foreach ($meta->primaryKey as $field) {
            if ($entity->propSet($field->property)) {
                $parts[$field->column] = $this->caster->toDb($meta->class, $field, $entity->{$field->property});
            }
        }
        if (count($parts) !== count($meta->primaryKey)) {
            return;
        }

        $this->rememberEntity($entity);
        $context = Session::context();
        if ($inserted && ($this->inTransactionByContext[$context] ?? false)) {
            $key = \Pixiaoka\Orm\Cache\RowCache::key($entity::class, $parts);
            $baseline = $this->txnIdentityStates[$context][$key] ?? null;
            $states = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
            $states[$entity] = $baseline ?? ($this->pendingInsertStates[$entity] ?? $entity->captureState($meta));
            $this->txnStatesByContext[$context] = $states;
            $new = $this->txnNewIdentities[$context] ?? [];
            $new[$key] ??= [];
            $new[$key][] = $baseline;
            $this->txnNewIdentities[$context] = $new;
        }
        $this->cache->set($entity, $parts);
    }

    private function cacheClear(Entity $entity, Identity $identity): void
    {
        $this->cache->clear($entity::class, $identity->parts);
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    private function contextInfo(Context $context): ContextInfo
    {
        return new ContextInfo(
            $context->type(),
            spl_object_id($context),
        );
    }

    /** @return list<string> 只收集键名，不读取、复制或序列化任何参数值。 */
    private function parameterNames(array $params): array
    {
        return array_map(
            static fn (int|string $name): string => is_int($name) ? (string) ($name + 1) : ltrim($name, ':'),
            array_keys($params),
        );
    }

    private function runExecute(string $sql, array $params, ?string $model = null): int
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $affected = $this->connection->execute($sql, $params);
            $this->observeSql($sql, $params, $model, $started, $affected, null);

            return $affected;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function runQueryAll(string $sql, array $params, ?string $model = null): array
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $rows = $this->connection->queryAll($sql, $params);
            $this->observeSql($sql, $params, $model, $started, null, count($rows));

            return $rows;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function runQueryOne(string $sql, array $params, ?string $model = null): ?array
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $row = $this->connection->queryOne($sql, $params);
            $this->observeSql($sql, $params, $model, $started, null, $row === null ? 0 : 1);

            return $row;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    private function observeSql(
        string $sql,
        array $params,
        ?string $model,
        float $started,
        ?int $affected,
        ?int $rows,
        ?\Throwable $exception = null,
    ): void {
        if (!$this->events->hasListeners()) {
            return;
        }
        $operation = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: 'SQL');
        $this->dispatchObserved(fn () => $this->events->dispatch(new SqlEvent(
            $sql,
            $operation,
            $model,
            $this->contextInfo(Session::context()),
            max(0.0, $this->clock->now() - $started),
            $exception === null,
            $this->parameterNames($params),
            $affected,
            $rows,
            $exception === null ? null : $exception::class,
        )));
    }
}
