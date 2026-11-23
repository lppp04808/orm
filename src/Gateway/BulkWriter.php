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
final class BulkWriter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataReader $metadata,
        private readonly Caster $caster,
        private readonly RowCache $cache,
        private readonly SqlExecutor $executor,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param class-string<Entity> $class
     * @param list<Entity|array<string, mixed>> $rows
     * @param callable(Entity, bool=, string=): void $cacheSet
     */
    public function batchInsert(EntityGateway $gateway, string $class, array $rows, callable $cacheSet): void
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
                $gateway->assertSame($bound, '批量插入');
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
            $this->transactions->rememberEntity($row);
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
            $this->publishBatchEntity($gateway, $row, $meta, $generated, $generatedIds[$index] ?? null, $cacheSet);
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
                $value = $isVersion ? 1 : $this->caster->currentTimestamp($field, $now);
            } elseif ($has) {
                $value = $isEntity
                    ? $row->{$field->property}
                    : (array_key_exists($field->column, $row) ? $row[$field->column] : $row[$field->property]);
            } else {
                throw new IdentityException($meta->class . '::' . $field->property . ' 批量插入缺少列');
            }
            $values[] = $this->caster->toDbNormalized($meta->class, $field, $value);
            if ($isVersion) {
                $version = $meta->versionField() ?? throw new \LogicException('版本元数据不一致');
                $this->versionValue($meta, $version, $this->caster->toPhp($meta->class, $version, $value));
            }
        }

        return $values;
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
        if ($generated !== null && !$row->propSet($generated->property)) {
            if ($id === null || $id === '' || $id === '0') {
                throw new IdentityException($meta->class . ' 行可能已写入，但生成列没有回填', true);
            }
            $row->{$generated->property} = $this->caster->toPhp($meta->class, $generated, $id);
        }
        $row->markPersisted($gateway);
        $row->afterSave();
        $row->afterInsert();
        $this->transactions->rememberEntity($row);
        $cacheSet($row, true);
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
        $this->assertVersionWrite($this->metadata->read($builder->modelClass()), '批量条件 UPDATE');
        $query = $builder->buildUpdate($values);
        $affected = $this->executor->execute($query['sql'], $query['params'], $builder->modelClass());
        $this->cache->clearClass($this->metadata->read($builder->modelClass())->class);

        return $affected;
    }

    /** @param callable(Entity, bool=, string=): void $cacheSet 批量恢复只同步实际命中的已交付实体。 */
    public function executeRestore(EntityGateway $gateway, Builder $builder, callable $cacheSet): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $field = $meta->softDeleteField()
            ?? throw new \InvalidArgumentException($meta->class . ' 未声明软删除字段');
        $this->assertVersionWrite($meta, '批量条件 UPDATE');
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

    public function executeDelete(EntityGateway $gateway, Builder $builder, bool $force = false): int
    {
        $meta = $this->metadata->read($builder->modelClass());
        $this->assertVersionWrite($meta, '批量条件 DELETE');
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

    /** @return list<Entity> */
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
                    throw new IdentityException($meta->class . ' DELETE RETURNING 缺少主键列 ' . $field->column);
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
            $insert[$field->column] = $this->caster->toDbNormalized($meta->class, $field, $value);
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
                $insert[$field->column] = $this->caster->toDbNormalized($meta->class, $field, $this->caster->currentTimestamp($field));
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
            throw new IdentityException($meta->class . '::' . $field->property . ' 版本必须是大于等于 0 且可递增的 int');
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
            $entity->{$field->property} = $this->caster->currentTimestamp($field);
        }
    }

    private function driver(): string
    {
        if (!$this->connection instanceof DriverAwareConnection) {
            throw new \LogicException('该操作需要连接实现 DriverAwareConnection，以选择正确的 SQL 方言');
        }

        return $this->connection->driver();
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
