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
use Pixiaoka\Orm\Identity\Identity;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\Expression;
use Pixiaoka\Orm\Session;

final class EntityGateway
{
    private readonly RowCache $cache;

    /** @var \WeakMap<Context, bool> 对象身份避免 Fiber 销毁后的数字 ID 重用。 */
    private \WeakMap $inTransactionByContext;

    /** @var \WeakMap<Context, list<string>> 回滚只清当前上下文事务内写入的 key。 */
    private \WeakMap $txnCacheKeysByContext;

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataReader $metadata = new MetadataReader(),
        private readonly Caster $caster = new Caster(),
    ) {
        $this->cache = new RowCache();
        $this->inTransactionByContext = new \WeakMap();
        $this->txnCacheKeysByContext = new \WeakMap();
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
        $meta = $this->metadata->read($entity::class);
        $generated = $meta->generatedField();
        $this->fillTimestamp($entity, $meta, 'created_at');
        $this->fillTimestamp($entity, $meta, 'updated_at');
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
            $row = $this->connection->queryOne(
                $sql . ' RETURNING ' . $this->quote($generated->column),
                $params,
            );
            $id = $row === null ? '' : (string) ($row[$generated->column] ?? '');
        } else {
            $this->connection->execute($sql, $params);
            $id = $generated === null ? '' : $this->connection->lastInsertId();
        }

        if ($generated === null) {
            $entity->markPersisted();
            $entity->afterSave();
            $entity->afterInsert();
            $this->cacheSet($entity);

            return;
        }

        if ($id === '' || $id === '0') {
            throw new IdentityException($meta->class . ' 行可能已写入，但生成列没有回填', true);
        }

        $entity->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        $entity->markPersisted();
        $entity->afterSave();
        $entity->afterInsert();
        $this->cacheSet($entity);
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
        $entityCount = 0;
        foreach ($rows as $row) {
            if ($row instanceof Entity) {
                $entityCount++;
            }
        }

        // P0-3：多行实体 + 生成列时，RETURNING 是唯一可靠的批量回填途径。
        // 不支持 RETURNING 的连接（mysql）下 lastInsertId 只反映最后一行，回填会串号。
        if ($generated !== null && $entityCount > 0 && count($rows) > 1 && !$this->connection->supportsReturning()) {
            throw new IdentityException(
                $meta->class . ' 该连接不支持 RETURNING，无法批量回填生成列，请逐行 insert',
                true,
            );
        }

        // P1-4：分块。块大小同时受行数（500）与占位符上限（65535）约束。
        $columnCount = count($fieldList);
        $chunkSize = max(1, min(500, intdiv(65535, max(1, $columnCount))));

        // P0-3：RETURNING 时按块收集生成列值，行序与 $rows 一一对应
        $generatedIds = [];
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            foreach ($this->executeBatchChunk($meta, $generated, $fieldList, $chunk) as $id) {
                $generatedIds[] = $id;
            }
        }

        $rowIndex = 0;
        foreach ($rows as $row) {
            if ($row instanceof Entity) {
                if ($generated !== null && !$row->propSet($generated->property)) {
                    $id = $generatedIds[$rowIndex] ?? null;
                    if ($id !== null && $id !== '' && $id !== '0') {
                        $row->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
                    }
                }
                $row->markPersisted();
                $row->afterSave();
                $row->afterInsert();
                $this->cacheSet($row);
            }
            $rowIndex++;
        }
    }

    /**
     * 执行一个分块。返回该块内每行的生成列值（无生成列时为 null 填充）。
     *
     * @param list<FieldMeta> $fieldList
     * @param list<Entity|array<string, mixed>> $chunk
     * @return list<string|null>
     */
    private function executeBatchChunk(
        ModelMeta $meta,
        ?FieldMeta $generated,
        array $fieldList,
        array $chunk,
    ): array {
        $columns = array_map(fn (FieldMeta $f): string => $this->quote($f->column), $fieldList);
        $now = time();
        $paramIndex = 0;
        $valueGroups = [];
        $params = [];

        foreach ($chunk as $row) {
            $isEntity = $row instanceof Entity;
            if ($isEntity) {
                $row->beforeSave();
                $row->beforeInsert();
            }
            $placeholders = [];
            foreach ($fieldList as $field) {
                $column = $field->column;
                $isTimestamp = in_array($field->property, $meta->timestamps, true);
                $has = $isEntity
                    ? $row->propSet($field->property)
                    : (array_key_exists($column, $row) || array_key_exists($field->property, $row));
                if ($isTimestamp && !$has) {
                    // P1-2：时间戳只在未提供时才填充，显式给值的不覆盖
                    $value = $this->currentTimestamp($field, $now);
                } elseif ($has) {
                    $value = $isEntity
                        ? $row->{$field->property}
                        : (array_key_exists($column, $row) ? $row[$column] : $row[$field->property]);
                } else {
                    throw new IdentityException($meta->class . '::' . $field->property . ' 批量插入缺少列');
                }
                $p = ':b' . $paramIndex++;
                $placeholders[] = $p;
                $params[substr($p, 1)] = $this->toDbValue($meta, $field, $value);
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
            $returned = $this->connection->queryAll(
                $sql . ' RETURNING ' . $this->quote($generated->column),
                $params,
            );

            return array_map(
                static fn (array $r): string => (string) ($r[$generated->column] ?? ''),
                $returned,
            );
        }

        // 不支持 RETURNING：多行带生成列已在入口拒绝；单行用 lastInsertId 兜底
        $this->connection->execute($sql, $params);
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
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $entity->beforeSave();
        $entity->beforeUpdate();

        $original = $entity->takeOriginal();
        $this->fillUpdatedAt($entity, $meta, $original);
        $sets = [];
        $params = [];

        foreach ($meta->fields as $field) {
            if ($field->primary) {
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
            $entity->markPersisted();

            return;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quote($meta->table),
            implode(', ', $sets),
            $this->where($meta, $params, $identity),
        );
        $this->connection->execute($sql, $params);
        $entity->markPersisted();
        $entity->afterSave();
        $entity->afterUpdate();
        $this->cacheSet($entity);
    }

    // ---- 删除 ----

    public function delete(Entity $entity): void
    {
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
        $entity->beforeDelete();
        $params = [];
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quote($meta->table),
            $this->where($meta, $params, $identity),
        );
        $affected = $this->connection->execute($sql, $params);
        if ($affected !== 1) {
            throw new IdentityException($meta->class . ' 删除没有命中恰好一行');
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
        $row = $this->connection->queryOne($sql, $params);
        if ($row === null) {
            return null;
        }

        $entity = $this->hydrate($meta, $row);
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
        $meta = $this->metadata->read($entity::class);
        $identity = $this->identityOf($entity, $meta);
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
        $row = $this->connection->queryOne($sql, $params);
        if ($row === null) {
            throw new IdentityException($meta->class . ' 行不存在，无法刷新');
        }

        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                throw new IdentityException($meta->class . '::' . $field->property . ' 读出的行缺少列');
            }
            $entity->{$field->property} = $this->caster->toPhp($meta->class, $field, $row[$field->column]);
        }
        $entity->markPersisted();
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

        return $this->connection->queryAll($query['sql'], $query['params']);
    }

    public function executeRow(string $class, array $query): ?array
    {
        $this->assertLockSupported($query['sql']);

        return $this->connection->queryOne($query['sql'], $query['params']);
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
        $row = $this->connection->queryOne($query['sql'], $query['params']);
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
        $query = $builder->buildUpdate($values);
        $affected = $this->connection->execute($query['sql'], $query['params']);
        $this->cache->clearClass($this->metadata->read($builder->modelClass())->class);

        return $affected;
    }

    public function executeDelete(Builder $builder): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $class = $meta->class;
        $query = $builder->buildDelete();
        if ($this->connection->supportsReturning()) {
            $sql = $query['sql'] . ' RETURNING ' . implode(', ', array_map(
                $this->quote(...),
                $meta->primaryColumns(),
            ));
            $deleted = $this->connection->queryAll($sql, $query['params']);
            $this->cache->clearDeleted($class, $this->normalizeIdentities($meta, $deleted));

            return count($deleted);
        }

        // MySQL 没有 DELETE ... RETURNING。按已缓存主键做一次受限预查，
        // 成功后只失效候选身份；并发插入/更新与缓存一致性边界见使用文档。
        $candidates = $this->deletedIdentityQuery($meta, $builder);
        $deleted = $candidates === null
            ? []
            : $this->connection->queryAll($candidates['sql'], $candidates['params']);
        $affected = $this->connection->execute($query['sql'], $query['params']);
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

        return $this->connection->queryAll($query['sql'], $query['params']);
    }

    public function selectOneRaw(string|Expression $sql, array $params = []): ?array
    {
        $query = $this->rawQuery($sql, $params);

        return $this->connection->queryOne($query['sql'], $query['params']);
    }

    /** 原生 INSERT/UPDATE/DELETE/DDL；SELECT 请使用 selectRaw() 以获取结果。 */
    public function executeRaw(string|Expression $sql, array $params = []): int
    {
        $query = $this->rawQuery($sql, $params);

        return $this->connection->execute($query['sql'], $query['params']);
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
        $affected = $this->connection->execute($sql, $params);
        $this->cache->clearClass($class);

        return $affected;
    }

    public function transaction(callable $func): mixed
    {
        $ctx = Session::context();
        $outer = $this->inTransactionByContext[$ctx] ?? false;
        $this->inTransactionByContext[$ctx] = true;
        if (!$outer) {
            $this->txnCacheKeysByContext[$ctx] = [];
        }

        $begun = false;
        try {
            if (!$outer) {
                try {
                    Session::claimConnection($this, $ctx);
                    $this->connection->beginTransaction();
                    $begun = true;
                } catch (\Throwable $e) {
                    Session::releaseConnection($this, $ctx);
                    throw $e;
                }
            }

            $result = $func();
            if (Session::currentContext() !== $ctx) {
                throw new \LogicException('事务回调不能切换 Fiber/协程上下文后提交');
            }
            if (!$outer && !isset($this->inTransactionByContext[$ctx])) {
                throw new \LogicException('事务期间 Session 已被清理，操作已回滚，不能继续提交');
            }
            if (!$outer) {
                $this->connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($begun && isset($this->inTransactionByContext[$ctx])) {
                try {
                    $this->connection->rollback();
                } finally {
                    $this->clearTransactionCache($ctx);
                }
            }
            throw $e;
        } finally {
            if (!$outer) {
                Session::releaseConnection($this, $ctx);
                unset($this->inTransactionByContext[$ctx], $this->txnCacheKeysByContext[$ctx]);
            }
        }
    }

    /** @internal 由 Session::discard() 调用，回滚并解除当前上下文的事务所有权。 */
    public function releaseContext(Context $context): void
    {
        $active = $this->inTransactionByContext[$context] ?? false;
        try {
            if ($active) {
                $this->connection->rollback();
            }
        } finally {
            $this->clearTransactionCache($context);
            Session::releaseConnection($this, $context);
            unset($this->inTransactionByContext[$context], $this->txnCacheKeysByContext[$context]);
        }
    }

    private function clearTransactionCache(Context $context): void
    {
        foreach ($this->txnCacheKeysByContext[$context] ?? [] as $key) {
            $this->cache->clearKey($key);
        }
    }

    // ---- 查询执行 —— 带缓存 ----

    /** @return list<Entity> */
    public function executeQuery(string $class, array $query): array
    {
        $this->assertLockSupported($query['sql']);
        $meta = $this->metadata->read($class);
        $rows = $this->connection->queryAll($query['sql'], $query['params']);
        $entities = [];
        foreach ($rows as $row) {
            $entity = $this->hydrate($meta, $row);
            $this->cacheSet($entity);
            $entities[] = $entity;
        }

        return $entities;
    }

    /** @return Entity|null */
    public function executeQueryOne(string $class, array $query): ?Entity
    {
        $this->assertLockSupported($query['sql']);
        $meta = $this->metadata->read($class);
        $row = $this->connection->queryOne($query['sql'], $query['params']);
        if ($row === null) {
            return null;
        }

        $entity = $this->hydrate($meta, $row);
        $this->cacheSet($entity);

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
        $row = $this->connection->queryOne($sql, $query['params']);
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

        $entity->markPersisted();

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

    private function cacheSet(Entity $entity): void
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

        $key = $this->cache->setKey($entity, $parts);
        $this->cache->set($entity, $parts);

        // P1-3：事务期间写入的 key 记下来，回滚时精确清除
        $ctx = Session::context();
        if ($this->inTransactionByContext[$ctx] ?? false) {
            $this->txnCacheKeysByContext[$ctx][] = $key;
        }
    }

    private function cacheClear(Entity $entity, Identity $identity): void
    {
        $this->cache->clear($entity::class, $identity->parts);
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
