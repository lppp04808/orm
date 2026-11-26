<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Query\Builder;

/** @internal 批量及条件写入；仅调用期间使用网关，不保存网关强引用。 */
final readonly class BulkWriter
{
    public function __construct(
        private Connection         $connection,
        private MetadataReader     $metadata,
        private Caster             $caster,
        private RowCache           $cache,
        private SqlExecutor        $executor,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * @param EntityGateway $gateway
     * @param class-string<Entity> $class
     * @param list<Entity|array<string, mixed>> $rows
     * @param callable(Entity, bool=, string=): void $cacheSet
     * @throws \Throwable
     */
    public function batchInsert(EntityGateway $gateway, string $class, array $rows, callable $cacheSet): void
    {
        if ($rows === []) {
            return;
        }

        $rows = array_values($rows);
        $entities = [];
        $prepared = [];
        $now = time();
        $meta = $this->metadata->read($class);
        $generated = $meta->generatedField();
        /** @var array<string, FieldMeta> $columns */
        $columns = [];
        $fieldList = [];
        foreach ($meta->fields as $field) {
            $columns[$field->column] ??= $field;
            if ($field->generated) {
                continue;
            }
            $fieldList[] = $field;
        }
        if ($fieldList === []) {
            // 生成键是唯一字段时，空数组/未初始化实体都表示一行数据库默认值。
            foreach ($rows as $index => $row) {
                if (!$row instanceof Entity) {
                    $prepared[$index] = $this->prepareBatchRow($meta, $columns, [], $row, $now);
                    continue;
                }
                if (!$row instanceof $class) {
                    throw new \InvalidArgumentException('Batch insert entities must be ' . $class . ', got ' . $row::class);
                }
                if (!$row->isNew()) {
                    throw new IdentityException($row::class . ' is already persisted and cannot be batch-inserted');
                }
                if ($row->hasGatewayBinding()) {
                    $bound = $row->boundGateway()
                        ?? throw new \LogicException($row::class . ' has a destroyed Gateway; query it explicitly through a new Gateway');
                    $gateway->assertSame($bound, 'batch insert');
                }
                $entities[$index] = $row;
            }
            foreach ($entities as $index => $row) {
                $this->transactions->rememberEntity($row);
                $row->beforeSave();
                $row->beforeInsert();
                $prepared[$index] = $this->prepareBatchRow($meta, $columns, [], $row, $now);
            }
            ksort($prepared);
            $this->gatewayTransaction($gateway, function () use (
                $gateway, $meta, $prepared, $generated, $entities, $cacheSet,
            ): void {
                foreach ($prepared as $index => $values) {
                    $ids = $this->executeBatchChunk($meta, $generated, [], [$values]);
                    if (isset($entities[$index])) {
                        $this->publishBatchEntity($gateway, $entities[$index], $meta, $generated, $ids[0] ?? null, $cacheSet);
                    }
                }
            });

            return;
        }

        foreach ($rows as $index => $row) {
            if (!$row instanceof Entity) {
                $prepared[$index] = $this->prepareBatchRow($meta, $columns, $fieldList, $row, $now);
                continue;
            }
            if (!$row instanceof $class) {
                throw new \InvalidArgumentException('Batch insert entities must be ' . $class . ', got ' . $row::class);
            }
            if (!$row->isNew()) {
                throw new IdentityException($row::class . ' is already persisted and cannot be batch-inserted');
            }
            if ($row->hasGatewayBinding()) {
                $bound = $row->boundGateway()
                    ?? throw new \LogicException($row::class . ' has a destroyed Gateway; query it explicitly through a new Gateway');
                $gateway->assertSame($bound, 'batch insert');
            }
            $entities[$index] = $row;
        }

        // P0-3：多行含实体 + 生成列时，RETURNING 是唯一可靠的批量回填途径。
        if ($generated !== null && $entities !== [] && count($rows) > 1 && !$this->connection->supportsReturning()) {
            throw new IdentityException(
                $meta->class . ' cannot batch-populate generated columns because the connection does not support RETURNING; insert entities individually',
                true,
            );
        }

        // 钩子可以补必填字段，但必须在首条 SQL 前完成整批最终值物化。
        // 事务内先保存钩子及自动填值之前的新实体基线；预检失败不假装撤销业务副作用。
        foreach ($entities as $index => $row) {
            $this->transactions->rememberEntity($row);
            $this->fillTimestamp($row, $meta, 'created_at');
            $this->fillTimestamp($row, $meta, 'updated_at');
            $this->fillVersion($row, $meta);
            $row->beforeSave();
            $row->beforeInsert();
            $prepared[$index] = $this->prepareBatchRow($meta, $columns, $fieldList, $row, $now);
        }
        ksort($prepared);

        // SQL 严格保留原始混批行序。所有分块共享一个事务边界，
        // 任一条 SQL 失败都不能留下已提交的前置分块。
        $this->gatewayTransaction($gateway, function () use (
            $gateway, $meta, $prepared, $fieldList, $generated, $entities, $cacheSet,
        ): void {
            $chunkSize = max(1, min(500, intdiv(65535, max(1, count($fieldList)))));
            $generatedIds = [];
            foreach (array_chunk($prepared, $chunkSize, true) as $chunk) {
                $ids = $this->executeBatchChunk($meta, $generated, $fieldList, array_values($chunk));
                foreach (array_keys($chunk) as $position => $index) {
                    $generatedIds[$index] = $ids[$position] ?? null;
                }
            }
            foreach ($entities as $index => $row) {
                $this->publishBatchEntity($gateway, $row, $meta, $generated, $generatedIds[$index] ?? null, $cacheSet);
            }
        });
    }

    private function gatewayTransaction(EntityGateway $gateway, callable $work): void
    {
        // 仅通过公共事务入口建立边界；已有外层事务复用 SAVEPOINT。
        $gateway->transaction($work);
    }

    /** @param array<string, FieldMeta> $columns @param list<FieldMeta> $fieldList @return list<int|float|string|null> */
    private function prepareBatchRow(ModelMeta $meta, array $columns, array $fieldList, array|Entity $row, int $now): array
    {
        $isEntity = $row instanceof Entity;
        if (!$isEntity) {
            $resolved = $this->resolveArrayBatchFields($meta, $columns, $row);
        }
        $values = [];
        foreach ($meta->fields as $field) {
            if ($field->generated) {
                if (($isEntity && $row->propSet($field->property))
                    || (!$isEntity && array_key_exists($field->property, $resolved))) {
                    throw new \InvalidArgumentException($meta->class . '::' . $field->property . ' is generated and cannot be provided for batch insert');
                }
                continue;
            }
        }
        foreach ($fieldList as $field) {
            $isTimestamp = in_array($field->property, $meta->timestamps, true);
            $isVersion = $field->property === $meta->version;
            $has = $isEntity
                ? $row->propSet($field->property)
                : array_key_exists($field->property, $resolved);
            if (($isTimestamp || $isVersion) && !$has) {
                $value = $isVersion ? 1 : $this->caster->currentTimestamp($field, $now);
            } elseif ($has) {
                $value = $isEntity
                    ? $row->{$field->property}
                    : $resolved[$field->property];
            } else {
                throw new IdentityException($meta->class . '::' . $field->property . ' is missing a value for batch insert');
            }
            $values[] = $this->caster->toDbNormalized($meta->class, $field, $value);
            if ($isVersion) {
                $version = $meta->versionField() ?? throw new \LogicException('Version metadata is inconsistent');
                $this->versionValue($meta, $version, $this->caster->toPhp($meta->class, $version, $value));
            }
        }

        return $values;
    }

    /** @param array<string, FieldMeta> $columns @return array<string, mixed> 属性名 => 原始输入值，重复来源不得按优先级吞掉。 */
    private function resolveArrayBatchFields(ModelMeta $meta, array $columns, array $row): array
    {
        $resolved = [];
        $sources = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException($meta->class . ' batch insert keys must be strings');
            }
            $field = $meta->fields[$key] ?? $columns[$key] ?? null;
            if ($field === null) {
                throw new \InvalidArgumentException($meta->class . ' batch insert contains unknown field: ' . $key);
            }
            if (isset($sources[$field->property])) {
                throw new \InvalidArgumentException($meta->class . '::' . $field->property
                    . ' was provided more than once through keys ' . $sources[$field->property] . ' and ' . $key);
            }
            $sources[$field->property] = $key;
            $resolved[$field->property] = $value;
        }

        return $resolved;
    }

    /** @param callable(Entity, bool=, string=): void $cacheSet SQL 成功后才发布实体身份与缓存。 */
    private function publishBatchEntity(
        EntityGateway $gateway,
        Entity $row,
        ModelMeta $meta,
        ?FieldMeta $generated,
        ?string $id,
        callable $cacheSet,
    ): void {
        // 自动事务在预检后才开始，必须在生成键回填及持久化状态改变前登记新实体基线。
        $this->transactions->rememberEntity($row);
        if ($generated !== null && !$row->propSet($generated->property)) {
            if ($id === null || $id === '' || $id === '0') {
                throw new IdentityException($meta->class . ' row may have been written, but its generated column was not populated', true);
            }
            $row->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        }
        $row->markPersisted($gateway);
        $cacheSet($row, true);
        $this->transactions->rememberEntity($row);
        $row->afterSave();
        $row->afterInsert();
        $gateway->assertPersistedIdentity($row);
    }

    /**
     * 执行已完整预检的分块；这里不再运行钩子或修改实体。
     *
     * @param ModelMeta $meta
     * @param FieldMeta|null $generated
     * @param list<FieldMeta> $fieldList
     * @param list<list<int|float|string|null>> $chunk
     * @return list<string|null>
     * @throws \Throwable
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

        $sql = $fieldList === []
            ? 'INSERT INTO ' . $this->quote($meta->table) . ($this->driver() === 'mysql' ? ' () VALUES ()' : ' DEFAULT VALUES')
            : sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->quote($meta->table),
                implode(', ', $columns),
                implode(', ', $valueGroups),
            );

        if ($generated !== null && $this->connection->supportsReturning()) {
            // P0-3：RETURNING 拿回本块全部生成列值，行序即结果序
            $returned = $this->executor->queryAll(
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
        $this->executor->execute($sql, $params, $meta->class);
        $ids = array_fill(0, count($chunk), null);
        if ($generated !== null && count($chunk) === 1) {
            $id = $this->connection->lastInsertId();
            $ids[0] = ($id === '' || $id === '0') ? null : $id;
        }

        return $ids;
    }

    public function executeUpdate(Builder $builder, array $values): int
    {
        $builder->gateway()->assertConditionalWrite($builder->modelClass(), 'conditional UPDATE');
        $query = $builder->buildUpdate($values);
        $affected = $this->executor->execute($query['sql'], $query['params'], $builder->modelClass());
        $this->cache->clearClass($this->metadata->read($builder->modelClass())->class);

        return $affected;
    }

    /** @internal 运算符由 Builder 和 SQL 编译器再次校验，不接受外部 SQL 片段。 */
    public function executeDeltaUpdate(
        Builder $builder,
        string $column,
        string $operator,
        int|float $amount,
        array $extra = [],
    ): int {
        $meta = $this->metadata->read($builder->modelClass());
        $builder->gateway()->assertConditionalWrite($builder->modelClass(), 'conditional UPDATE');
        $query = $builder->buildDeltaUpdate($column, $operator, $amount, $extra);
        $affected = $this->executor->execute($query['sql'], $query['params'], $meta->class);
        $this->cache->clearClass($meta->class);

        return $affected;
    }

    /**
     * @param EntityGateway $gateway
     * @param Builder $builder
     * @param callable(Entity, bool=, string=): void $cacheSet 批量恢复只同步实际命中的已交付实体。
     * @return int
     * @throws \Throwable
     */
    public function executeRestore(EntityGateway $gateway, Builder $builder, callable $cacheSet): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $field = $meta->softDeleteField()
            ?? throw new \InvalidArgumentException($meta->class . ' does not declare a soft-delete field');
        $builder->gateway()->assertConditionalWrite($builder->modelClass(), 'conditional UPDATE');
        $known = $this->knownMatchingEntities($meta, $builder);
        $query = $builder->buildUpdate([$field->property => null]);
        $affected = $this->executor->execute($query['sql'], $query['params'], $meta->class);
        if ($affected > 0) {
            foreach ($known as $entity) {
                $entity->{$field->property} = null;
                $entity->markPersisted($gateway);
                $this->transactions->rememberEntity($entity);
                $cacheSet($entity);
            }
        }

        return $affected;
    }

    /**
     * @param EntityGateway $gateway
     * @param Builder $builder
     * @param bool $force
     * @return int
     * @throws \Throwable
     */
    public function executeDelete(EntityGateway $gateway, Builder $builder, bool $force = false): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $builder->gateway()->assertConditionalWrite($builder->modelClass(), 'conditional DELETE', $force);
        $class = $meta->class;
        $softDelete = $force ? null : $meta->softDeleteField();
        if ($softDelete !== null) {
            $known = $this->knownMatchingEntities($meta, $builder);
            $deletedAt = $this->caster->currentTimestamp($softDelete);
            $query = $builder->buildUpdate([$softDelete->property => $deletedAt]);
            $affected = $this->executor->execute($query['sql'], $query['params'], $class);
            if ($affected > 0) {
                foreach ($known as $entity) {
                    $entity->{$softDelete->property} = $deletedAt;
                    $entity->markPersisted($gateway);
                    $identity = $this->cache->knownIdentity($entity);
                    if ($identity !== null) {
                        $this->cache->clear($class, $identity);
                    }
                }
            }

            return $affected;
        }
        $query = $builder->buildDelete();
        if ($this->connection->supportsReturning()) {
            $sql = $query['sql'] . ' RETURNING ' . implode(', ', array_map(
                $this->quote(...),
                $meta->primaryColumns(),
            ));
            $deleted = $this->executor->queryAll($sql, $query['params'], $class);
            $this->cache->clearDeleted($class, $this->normalizeIdentities($meta, $deleted));

            return count($deleted);
        }

        // MySQL 没有 DELETE ... RETURNING。按已缓存主键做一次受限预查，
        // 成功后只失效候选身份；并发插入/更新与缓存一致性边界见使用文档。
        $candidates = $this->deletedIdentityQuery($meta, $builder);
        $deleted = $candidates === null
            ? []
            : $this->executor->queryAll($candidates['sql'], $candidates['params'], $class);
        $affected = $this->executor->execute($query['sql'], $query['params'], $class);
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

    /**
     * @param ModelMeta $meta
     * @param Builder $builder
     * @return list<Entity>
     * @throws \Throwable
     */
    private function knownMatchingEntities(ModelMeta $meta, Builder $builder): array
    {
        $candidates = $this->deletedIdentityQuery($meta, $builder);
        $rows = $candidates === null
            ? []
            : $this->executor->queryAll($candidates['sql'], $candidates['params'], $meta->class);
        $keys = [];
        foreach ($this->normalizeIdentities($meta, $rows) as $identity) {
            $keys[RowCache::key($meta->class, $identity)] = true;
        }
        $entities = [];
        foreach ($this->cache->knownEntities($meta->class) as $entity) {
            $identity = $this->cache->knownIdentity($entity);
            if ($identity !== null && isset($keys[RowCache::key($meta->class, $identity)])) {
                $this->transactions->rememberEntity($entity);
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /** 将驱动返回的主键值按缓存写入时的同一路径规范化（bool/enum/int 等）。 */
    private function normalizeIdentities(ModelMeta $meta, array $rows): array
    {
        $identities = [];
        foreach ($rows as $row) {
            $identity = [];
            foreach ($meta->primaryKey as $field) {
                if (!array_key_exists($field->column, $row)) {
                    throw new IdentityException($meta->class . ' DELETE RETURNING is missing primary key column ' . $field->column);
                }
                $identity[$field->column] = $this->caster->toDbNormalized($meta->class, $field, $row[$field->column]);
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /**
     * 跨方言 UPSERT。$updateColumns=[] 表示冲突时忽略；null 表示更新其余已提供字段。
     * 默认冲突键是模型主键。代理键只有显式包含在 $conflictColumns 中才允许写入。
     *
     * @param class-string<Entity> $class
     * @param array<string, mixed> $values 属性名或真实列名 => 值
     * @param list<string>|null $updateColumns
     * @param list<string>|null $conflictColumns
     * @return int
     * @throws \Throwable
     */
    public function upsert(string $class, array $values, ?array $updateColumns = null, ?array $conflictColumns = null): int
    {
        $meta = $this->metadata->read($class);
        $this->assertConditionalWrite($meta, 'UPSERT');
        if ($values === []) {
            throw new \InvalidArgumentException('UPSERT requires at least one field');
        }
        $fields = [];
        foreach ($meta->fields as $field) {
            $fields[$field->property] = $field;
            $fields[$field->column] = $field;
        }
        $resolve = static function (string $name) use ($fields, $class): FieldMeta {
            return $fields[$name] ?? throw new \InvalidArgumentException($class . ' has unknown field: ' . $name);
        };
        $conflictColumns ??= $meta->primaryColumns();
        if ($conflictColumns === []) {
            throw new \InvalidArgumentException('UPSERT requires a conflict key');
        }
        $conflicts = [];
        foreach ($conflictColumns as $column) {
            $field = $resolve($column);
            $conflicts[$field->column] = $field;
        }
        $insert = [];
        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException('UPSERT field names must be strings');
            }
            $field = $resolve($column);
            if (isset($insert[$field->column])) {
                throw new \InvalidArgumentException('Duplicate UPSERT field: ' . $field->column);
            }
            if ($field->generated && !isset($conflicts[$field->column])) {
                throw new IdentityException($class . ' generated key may be written only as an explicit UPSERT conflict key');
            }
            $insert[$field->column] = $this->caster->toDbNormalized($meta->class, $field, $value);
        }
        foreach ($conflicts as $column => $_) {
            if (!array_key_exists($column, $insert)) {
                throw new IdentityException($class . ' UPSERT is missing conflict key ' . $column);
            }
        }
        $updates = [];
        $defaultUpdates = $updateColumns === null;
        $createdAtField = $meta->timestampField('created_at');
        $createdAt = $createdAtField === null ? [] : [$createdAtField->column];
        $updatedAtField = $meta->timestampField('updated_at');
        $updatedAt = $updatedAtField === null ? [] : [$updatedAtField->column];
        // 默认更新只采用调用方实际提供的普通字段；自动填充的 updated_at 不能凭空制造更新需求。
        $defaultUpdateColumns = array_values(array_diff(
            array_keys($insert),
            array_keys($conflicts),
            $createdAt,
            $updatedAt,
        ));
        foreach (['created_at', 'updated_at'] as $timestamp) {
            $field = $meta->timestampField($timestamp);
            if ($field !== null && !array_key_exists($field->column, $insert)) {
                $insert[$field->column] = $this->caster->toDbNormalized($meta->class, $field, $this->caster->currentTimestamp($field));
            }
        }
        foreach ($meta->fields as $field) {
            if (!array_key_exists($field->column, $insert) && !$field->generated && !$field->nullable()) {
                throw new IdentityException($class . ' UPSERT is missing non-nullable field ' . $field->column);
            }
        }
        if ($updateColumns !== []) {
            $updateColumns ??= $defaultUpdateColumns;
            $updateColumns = array_values(array_diff($updateColumns, $createdAt));
            if ($updateColumns !== []) {
                $updateColumns = array_map(
                    static fn (string $column): string => $resolve($column)->column,
                    $updateColumns,
                );
                $updatedAt = $updatedAtField?->column;
                if ($updatedAt !== null
                    && $updateColumns !== [$updatedAt]
                    && !in_array($updatedAt, $updateColumns, true)) {
                    $updateColumns[] = $updatedAt;
                }
            }
            foreach ($updateColumns as $column) {
                $field = $resolve($column);
                if (isset($conflicts[$field->column]) || $field->generated) {
                    throw new \InvalidArgumentException('UPSERT cannot update conflict keys or generated columns: ' . $field->column);
                }
                if (!array_key_exists($field->column, $insert)) {
                    throw new \InvalidArgumentException('UPSERT update field has no input value: ' . $field->column);
                }
                $updates[$field->column] = $field;
            }
        }
        if ($defaultUpdates && $updates === []) {
            throw new \InvalidArgumentException('UPSERT has no fields to update; pass $updateColumns=[] to ignore conflicts');
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
        $affected = $this->executor->execute($sql, $params, $meta->class);
        $this->cache->clearClass($class);

        return $affected;
    }

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

    /** @internal 真实条件写和 SQL 预览都通过 EntityGateway 复用本校验。 */
    public static function assertConditionalWrite(ModelMeta $meta, string $operation, bool $forceDelete = false): void
    {
        if ($meta->version !== null) {
            throw new \LogicException($meta->class . " uses optimistic locking; $operation cannot preserve per-entity version semantics; use save/delete per entity");
        }
        if ($forceDelete && $meta->softDeleteField() === null) {
            throw new \InvalidArgumentException($meta->class . ' does not declare a soft-delete field');
        }
    }

    /** 创建时间仅在未设置时填充，显式提供的值不被覆盖。 */
    private function fillTimestamp(Entity $entity, ModelMeta $meta, string $role): void
    {
        $field = $meta->timestampField($role);
        if ($field !== null && !$entity->propSet($field->property)) {
            $entity->{$field->property} = $this->caster->currentTimestamp($field);
        }
    }

    private function driver(): string
    {
        if (!$this->connection instanceof DriverAwareConnection) {
            throw new \LogicException('This operation requires DriverAwareConnection to select the SQL dialect');
        }

        return $this->connection->driver();
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
