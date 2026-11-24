<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Context;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\OptimisticLockException;
use Pixiaoka\Orm\Identity\Identity;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Metadata\RelationMeta;
use Pixiaoka\Orm\Observability\Clock;
use Pixiaoka\Orm\Observability\ContextInfo;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\EventListener;
use Pixiaoka\Orm\Observability\SystemClock;
use Pixiaoka\Orm\Observability\SessionEvent;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\Expression;
use Pixiaoka\Orm\Relation\Loader;
use Pixiaoka\Orm\Session;

final class EntityGateway
{
    /** @var array<class-string<Entity>, \ReflectionClass<Entity>> */
    private static array $reflectionClasses = [];

    private readonly RowCache $cache;

    private readonly TransactionManager $transactions;

    private readonly EventDispatcher $events;

    private readonly Clock $clock;

    private readonly Loader $relations;

    private readonly SqlExecutor $executor;

    private readonly BulkWriter $bulk;

    public function __construct(
        private readonly Connection     $connection,
        private readonly MetadataReader $metadata = new MetadataReader(),
        private readonly Caster         $caster = new Caster(),
        ?Clock                          $clock = null,
    ) {
        $this->cache = new RowCache();
        $this->events = new EventDispatcher();
        $this->clock = $clock ?? new SystemClock();
        $this->executor = new SqlExecutor($this->connection, $this->events, $this->clock);
        $this->transactions = new TransactionManager(
            $this->connection,
            $this->cache,
            $this->metadata,
            $this->caster,
            $this->executor,
            $this->events,
            $this->clock,
        );
        $this->bulk = new BulkWriter(
            $this->connection,
            $this->metadata,
            $this->caster,
            $this->cache,
            $this->executor,
            $this->transactions,
        );
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
            throw new \InvalidArgumentException($operation . ' must use the same EntityGateway');
        }
    }

    /** @internal 关系图优先复用本次主查询对象，其次复用 canonical 缓存对象。 */
    public function relationTarget(string $class, array $identity): ?Entity
    {
        return $this->cache->get($class, $identity);
    }

    /** @internal @return array<string, int|float|string|bool|null>|null */
    public function knownIdentity(Entity $entity): ?array
    {
        return $this->cache->knownIdentity($entity);
    }

    /** @internal @return list<Entity> */
    public function knownEntities(string $class): array
    {
        return $this->cache->knownEntities($class);
    }

    /** @internal 将 Loader 已按主键顺序转换的值映射为 RowCache 的列身份。 */
    public function relationIdentity(string $class, array $values): array
    {
        $identity = [];
        $primary = $this->meta($class)->primaryKey;
        if (count($primary) !== count($values)) {
            throw new \LogicException($class . ' has an incomplete relation target primary key');
        }
        foreach ($primary as $index => $field) {
            if (!array_key_exists($index, $values) || (!is_scalar($values[$index]) && $values[$index] !== null)) {
                throw new \LogicException($class . ' relation target primary key must be a complete scalar tuple');
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
        $this->transactions->rememberEntity($entity);
    }

    /** @internal 成功时登记旧状态供事务回滚，再把关系目标设为 canonical 缓存对象。 */
    public function publishRelationTarget(Entity $entity): void
    {
        $this->transactions->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    /** @internal 首次接触实体时建立弱归属，后续禁止跨连接或过期隐式换绑。 */
    public function forEntity(Entity $entity): self
    {
        if (!$entity->hasGatewayBinding()) {
            $entity->bindGateway($this);
        } else {
            $bound = $entity->boundGateway()
                ?? throw new \LogicException($entity::class . ' has a destroyed Gateway; query it explicitly through a new Gateway');
            $this->assertSame($bound, 'entity operation');
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
            throw new \LogicException('Relation key and value counts differ');
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
            throw new \LogicException('This operation requires DriverAwareConnection to select the SQL dialect');
        }

        return $this->connection->driver();
    }

    // ---- 插入 ----

    public function insert(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $generated = $meta->generatedField();
        $this->transactions->rememberEntity($entity);
        $this->fillTimestamp($entity, $meta, 'created_at');
        $this->fillTimestamp($entity, $meta, 'updated_at');
        $this->fillVersion($entity, $meta);
        $entity->beforeSave();
        $entity->beforeInsert();
        $params = [];

        foreach ($meta->fields as $field) {
            if ($field->generated) {
                if ($entity->propSet($field->property)) {
                    throw new IdentityException($meta->class . ' generated column is assigned by the database and cannot be provided');
                }
                continue;
            }
            if (!$entity->propSet($field->property)) {
                throw new IdentityException($meta->class . '::' . $field->property . ' must have a value before insertion');
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
            $this->transactions->rememberEntity($entity);
            $this->cacheSet($entity, true);

            return;
        }

        if ($id === '' || $id === '0') {
            throw new IdentityException($meta->class . ' row may have been written, but its generated column was not populated', true);
        }

        $entity->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        $entity->markPersisted($this);
        $entity->afterSave();
        $entity->afterInsert();
        $this->transactions->rememberEntity($entity);
        $this->cacheSet($entity, true);
    }

    /**
     * @param class-string<Entity> $class
     * @param list<Entity|array<string, mixed>> $rows
     */
    public function batchInsert(string $class, array $rows): void
    {
        $this->bulk->batchInsert(
            $this,
            $class,
            $rows,
            function (Entity $entity, bool $inserted = false, string $softDeleteMode = 'default'): void {
                $this->cacheSet($entity, $inserted, $softDeleteMode);
            },
        );
    }

    /** @internal 脏跟踪与关系合并共用数据库表示的字段值语义。 */
    public function fieldValuesEqual(ModelMeta $meta, FieldMeta $field, mixed $a, mixed $b): bool
    {
        $databaseValue = null;

        return $this->compareFieldValues($meta, $field, $a, $b, $databaseValue);
    }

    /** @param int|float|string|null $aDatabase 已转换的左侧数据库值，供 UPDATE 复用。 */
    private function compareFieldValues(
        ModelMeta $meta,
        FieldMeta $field,
        mixed $a,
        mixed $b,
        int|float|string|null &$aDatabase,
    ): bool {
        $aDatabase = null;
        if ($a === $b) {
            return true;
        }
        if (!is_object($a) || !is_object($b)) {
            return false;
        }
        $aDatabase = $this->caster->toDb($meta->class, $field, $a);

        return $aDatabase === $this->caster->toDb($meta->class, $field, $b);
    }

    // ---- 更新 —— 只改脏字段 ----

    public function update(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->transactions->rememberEntity($entity);
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
            // 只改真正变了的字段；对象以数据库表示比较并复用转换结果。
            $databaseValue = null;
            if (array_key_exists($field->property, $original)) {
                if ($this->compareFieldValues(
                    $meta,
                    $field,
                    $new,
                    $original[$field->property],
                    $databaseValue,
                )) {
                    continue;
                }
            } elseif (is_object($new)) {
                $databaseValue = $this->caster->toDb($meta->class, $field, $new);
            }
            $sets[] = $this->quote($field->column) . ' = :set_' . $field->column;
            $params['set_' . $field->column] = is_object($new)
                ? $databaseValue
                : $this->caster->toDb($meta->class, $field, $new);
        }

        if ($sets === []) {
            if ($expectedVersion !== null) {
                $entity->{$meta->version} = $expectedVersion;
            }
            $entity->markPersisted($this);

            return;
        }

        if ($expectedVersion !== null) {
            $version = $meta->versionField() ?? throw new \LogicException('Version metadata is inconsistent');
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
        $this->transactions->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    // ---- 删除 ----

    public function delete(Entity $entity, bool $force = false): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->transactions->rememberEntity($entity);
        $entity->beforeDelete();
        $original = $entity->takeOriginal();
        $expectedVersion = $this->expectedVersion($entity, $meta, $original);
        $params = [];
        $softDelete = !$force ? $meta->softDeleteField() : null;
        if ($softDelete !== null) {
            if ($entity->propSet($softDelete->property) && $entity->{$softDelete->property} !== null) {
                throw new IdentityException($meta->class . ' is soft-deleted; use restore() or forceDelete()');
            }
            $deletedAt = $this->caster->currentTimestamp($softDelete);
            $sql = sprintf(
                'UPDATE %s SET %s = :orm_deleted_at WHERE %s',
                $this->quote($meta->table),
                $this->quote($softDelete->column),
                $this->where($meta, $params, $identity),
            );
            $params['orm_deleted_at'] = $this->caster->toDb($meta->class, $softDelete, $deletedAt);
            $sql .= ' AND ' . $this->quote($softDelete->column) . ' IS NULL';
        } else {
            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $this->quote($meta->table),
                $this->where($meta, $params, $identity),
            );
        }
        if ($expectedVersion !== null) {
            $version = $meta->versionField() ?? throw new \LogicException('Version metadata is inconsistent');
            $sql .= ' AND ' . $this->quote($version->column) . ' = :orm_version';
            $params['orm_version'] = $expectedVersion;
        }
        $affected = $this->runExecute($sql, $params, $meta->class);
        if ($expectedVersion !== null && $affected !== 1) {
            throw new OptimisticLockException($meta->class, 'DELETE', $expectedVersion);
        }
        if ($expectedVersion === null && $affected !== 1) {
            throw new IdentityException($meta->class . ' delete must affect exactly one row');
        }
        $this->transactions->recordDeletedIdentity($entity, $identity->parts);
        if ($softDelete !== null) {
            $entity->{$softDelete->property} = $deletedAt;
            $entity->markPersisted($this);
        } else {
            $entity->markDeleted();
        }
        $entity->afterDelete();
        $this->cacheClear($entity, $identity);
    }

    public function restore(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $field = $meta->softDeleteField()
            ?? throw new \LogicException($meta->class . ' does not declare a soft-delete field');
        $identity = $this->identityOf($entity, $meta);
        $this->transactions->rememberEntity($entity);
        $params = [];
        $sql = sprintf(
            'UPDATE %s SET %s = NULL WHERE %s AND %s IS NOT NULL',
            $this->quote($meta->table),
            $this->quote($field->column),
            $this->where($meta, $params, $identity),
            $this->quote($field->column),
        );
        $affected = $this->runExecute($sql, $params, $meta->class);
        if ($affected !== 1) {
            throw new IdentityException($meta->class . ' restore must affect exactly one soft-deleted row');
        }
        $entity->{$field->property} = null;
        $entity->markPersisted($this);
        $this->transactions->rememberEntity($entity);
        $this->cacheSet($entity);
    }

    public function forceDelete(Entity $entity): void
    {
        $this->delete($entity, true);
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
            $softDelete = $meta->softDeleteField();
            if ($softDelete === null || !$cached->propSet($softDelete->property)
                || $cached->{$softDelete->property} === null) {
                return $cached;
            }
            $this->cache->clear($class, $identity->parts);
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
        $softDelete = $meta->softDeleteField();
        if ($softDelete !== null) {
            $sql .= ' AND ' . $this->quote($softDelete->column) . ' IS NULL';
        }
        $row = $this->runQueryOne($sql, $params, $meta->class);
        if ($row === null) {
            return null;
        }

        $entity = $this->hydrate($meta, $row);
        $this->forEntity($entity);
        $this->transactions->rememberEntity($entity);
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
                : $this->caster->formatDisplay($meta->class, $field, $value);
        }

        return $arr;
    }

    public function refresh(Entity $entity): void
    {
        $this->forEntity($entity);
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $this->transactions->rememberEntity($entity);
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
            throw new IdentityException($meta->class . ' row does not exist and cannot be refreshed');
        }

        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                throw new IdentityException($meta->class . '::' . $field->property . ' result row is missing column');
            }
            $entity->{$field->property} = $this->caster->toPhp($meta->class, $field, $row[$field->column]);
        }
        $entity->markPersisted($this);
        $entity->clearLoadedRelations();
        $this->transactions->rememberEntity($entity);
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
                    throw new \InvalidArgumentException('Query values must be scalar or null');
                }

                return $value;
            }

            return $this->caster->toDbNormalized($class, $field, $value);
        }

        $field = $this->queryField($class, $column)
            ?? throw new \InvalidArgumentException('Object query values must correspond to a base-model field: ' . $column);

        return $this->caster->toDbNormalized($class, $field, $value);
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
                throw new \UnexpectedValueException('Query result has no column: ' . $column);
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
        return $this->bulk->executeUpdate($builder, $values);
    }

    /** @internal 运算符只接受 Builder 传入的受控字面量。 */
    public function executeDeltaUpdate(
        Builder $builder,
        string $column,
        string $operator,
        int|float $amount,
        array $extra = [],
    ): int {
        return $this->bulk->executeDeltaUpdate($builder, $column, $operator, $amount, $extra);
    }

    /** 批量恢复时按主键预查实际命中行，只同步这些已交付给调用方的实体。 */
    public function executeRestore(Builder $builder): int
    {
        return $this->bulk->executeRestore(
            $this,
            $builder,
            function (Entity $entity, bool $inserted = false, string $softDeleteMode = 'default'): void {
                $this->cacheSet($entity, $inserted, $softDeleteMode);
            },
        );
    }

    public function executeDelete(Builder $builder, bool $force = false): int
    {
        return $this->bulk->executeDelete($this, $builder, $force);
    }

    /** @return list<array<string, mixed>> 原生 SELECT/CTE 查询，调用方负责 SQL 的正确性。 */
    public function selectRaw(string|Expression $sql, array $params = []): array
    {
        return $this->executor->selectRaw($sql, $params);
    }

    public function selectOneRaw(string|Expression $sql, array $params = []): ?array
    {
        return $this->executor->selectOneRaw($sql, $params);
    }

    /** 原生 INSERT/UPDATE/DELETE/DDL；SELECT 请使用 selectRaw() 以获取结果。 */
    public function executeRaw(string|Expression $sql, array $params = []): int
    {
        return $this->executor->executeRaw($sql, $params);
    }

    private function assertLockSupported(string $sql): void
    {
        $this->executor->assertLockSupported($sql);
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
        return $this->bulk->upsert($class, $values, $updateColumns, $conflictColumns);
    }

    /** @throws \Throwable */
    public function transaction(callable $func): mixed
    {
        return $this->transactions->run($this, $func);
    }

    /** @internal 由 Session::discard() 调用，回滚并解除当前上下文的事务所有权。 */
    public function releaseContext(Context $context): void
    {
        $this->transactions->releaseContext($this, $context);
    }

    /** @internal Session 在清理事务状态之后读取一次确认标记。 */
    public function rollbackWasConfirmed(Context $context): bool
    {
        return $this->transactions->rollbackWasConfirmed($context);
    }

    /** @internal Session::use() 也必须拒绝复用提交结果未知的连接。 */
    public function assertSessionUsable(): void
    {
        $this->executor->assertConnectionUsable();
    }

    /** @internal 隐式换绑不能代替调用方决定未结束事务的回滚与失败处理。 */
    public function assertSessionReplaceable(Context $context): void
    {
        $this->transactions->assertSessionReplaceable($context);
    }

    /** @internal discard() 应继续清理失败 Session，但禁止监听器重入清理。 */
    public function assertObservationBoundary(): void
    {
        $this->executor->assertObservationBoundary();
    }

    /** 事件同步发送，但连接级隔离禁止监听器重入 ORM；监听器异常仍由 Dispatcher 隔离。 */
    private function dispatchObserved(callable $dispatch): void
    {
        $this->executor->dispatchObserved($dispatch);
    }

    // ---- 查询执行 —— 带缓存 ----

    /** @return list<Entity> */
    public function executeQuery(string $class, array $query, array $relations = [], bool|string $cache = true): array
    {
        $this->assertLockSupported($query['sql']);
        $meta = $this->metadata->read($class);
        $cacheSoftDeleteMode = is_string($cache) ? $cache : 'default';
        $cacheEnabled = $cache !== false;
        $cacheSnapshot = $relations !== [] ? $this->cache->transactionSnapshot() : null;
        $rows = $this->runQueryAll($query['sql'], $query['params'], $class);
        $entities = [];
        foreach ($rows as $row) {
            $entity = $this->hydrate($meta, $row);
            $this->forEntity($entity);
            $this->transactions->rememberEntity($entity);
            if ($cacheEnabled && $relations === []) {
                $this->cacheSet($entity, softDeleteMode: $cacheSoftDeleteMode);
            }
            $entities[] = $entity;
        }
        if ($relations !== []) {
            try {
                $this->loadRelations($entities, $relations);
                if ($cacheEnabled) {
                    foreach ($entities as $entity) {
                        $this->cacheSet($entity, softDeleteMode: $cacheSoftDeleteMode);
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
    public function executeQueryOne(string $class, array $query, array $relations = [], string $cacheSoftDeleteMode = 'default'): ?Entity
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
        $this->transactions->rememberEntity($entity);
        if ($relations === []) {
            $this->cacheSet($entity, softDeleteMode: $cacheSoftDeleteMode);
        } else {
            try {
                $this->loadRelations([$entity], $relations);
                $this->cacheSet($entity, softDeleteMode: $cacheSoftDeleteMode);
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

    /** @param class-string<Entity> $class @return \ReflectionClass<Entity> */
    private function reflectionClass(string $class): \ReflectionClass
    {
        return self::$reflectionClasses[$class] ??= new \ReflectionClass($class);
    }

    private function hydrate(ModelMeta $meta, array $row): Entity
    {
        /**
         * @var Entity $entity
         */
        $entity = $this->reflectionClass($meta->class)->newInstanceWithoutConstructor();

        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                if ($field->nullable()) {
                    $entity->{$field->property} = null;
                    continue;
                }
                throw new IdentityException($meta->class . '::' . $field->property . ' result row is missing non-nullable column');
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
                throw new IdentityException($meta->class . ' is missing primary key column ' . $field->column);
            }
            $value = $entity->{$field->property};
            if ($value === null) {
                throw new IdentityException($meta->class . ' primary key column ' . $field->column . ' must not be null');
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
                throw new IdentityException($meta->class . ' identity lookup is missing primary key column ' . $field->column);
            }
            if ($key[$field->column] === null) {
                throw new IdentityException($meta->class . ' primary key column ' . $field->column . ' must not be null');
            }
            $php = $this->caster->toPhp($meta->class, $field, $key[$field->column]);
            $parts[$field->column] = $this->caster->toDb($meta->class, $field, $php);
        }
        $unknown = array_diff(array_keys($key), $meta->primaryColumns());
        if ($unknown !== []) {
            throw new IdentityException($meta->class . ' identity contains non-primary-key columns: ' . implode(', ', $unknown));
        }

        return new Identity($parts);
    }

    private function where(ModelMeta $meta, array &$params, Identity $identity): string
    {
        if (!$identity->complete(count($meta->primaryKey))) {
            throw new IdentityException($meta->class . ' has an incomplete primary key; operation rejected');
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
            throw new IdentityException($meta->class . '::' . $field->property . ' version must be a non-negative int that can be incremented');
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
            throw new IdentityException($meta->class . '::' . $field->property . ' is an ORM-managed version field; do not modify it directly, use refresh');
        }
        if (!is_int($value) || $value < 0 || $value === PHP_INT_MAX) {
            throw new IdentityException($meta->class . '::' . $field->property . ' has no valid persisted version; refresh before retrying');
        }

        return $value;
    }

    /** 创建时间仅在未设置时填充，显式提供的值不被覆盖。 */
    private function fillTimestamp(Entity $entity, ModelMeta $meta, string $role): void
    {
        $field = $meta->timestampField($role);
        if ($field !== null && !$entity->propSet($field->property)) {
            $entity->{$field->property} = $this->caster->currentTimestamp($field);
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
            && !$this->fieldValuesEqual($meta, $field, $entity->{$property}, $original[$property])) {
            return;
        }
        $entity->{$property} = $this->caster->currentTimestamp($field);
    }

    private function cacheSet(Entity $entity, bool $inserted = false, string $softDeleteMode = 'default'): void
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
        $cached = $this->cache->get($entity::class, $parts);
        if ($cached !== null && $cached !== $entity) {
            $softDelete = $meta->softDeleteField();
            if ($softDelete !== null && $cached->propSet($softDelete->property)) {
                $visibility = $cached->{$softDelete->property} === null ? 'default' : 'only';
                if ($softDeleteMode !== 'with' && $visibility !== $softDeleteMode) {
                    // 旧缓存对象代表同一个数据库身份，不让一次不同范围的查询回退其已知状态。
                    return;
                }
            }
        }

        $this->transactions->rememberEntity($entity);
        if ($inserted) {
            $this->transactions->recordInsertedIdentity($entity, $meta, $parts);
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

    private function runExecute(string $sql, array $params, ?string $model = null): int
    {
        return $this->executor->execute($sql, $params, $model);
    }

    /** @return list<array<string, mixed>> */
    private function runQueryAll(string $sql, array $params, ?string $model = null): array
    {
        return $this->executor->queryAll($sql, $params, $model);
    }

    /** @return array<string, mixed>|null */
    private function runQueryOne(string $sql, array $params, ?string $model = null): ?array
    {
        return $this->executor->queryOne($sql, $params, $model);
    }
}
