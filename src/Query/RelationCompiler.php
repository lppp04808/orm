<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Relation\Subquery;

/** @internal 延迟关系条件、相关子查询及关系约束校验；不持有 Builder。 */
final readonly class RelationCompiler
{
    public function __construct(private EntityGateway $gateway)
    {
    }

    public function relationQuery(
        string $class,
        QueryState $state,
        Subquery $spec,
        RenderContext $ctx,
        bool $count,
    ): Builder {
        $parts = explode('.', $spec->path);
        if ($parts === [] || in_array('', $parts, true)) {
            throw new RelationException('Relation paths cannot contain empty names');
        }
        $name = array_shift($parts);
        $relation = $this->gateway->relation($class, $name);
        try {
            // 嵌套关系已有内部别名，只把外部真实表名/用户别名登记为祖先限定名。
            if ($state->relationAlias === null) {
                $ctx->reserveRelationQualifier($this->tableQualifier($class, $state));
            }
            $alias = $ctx->relationAlias();
            // 自关联允许重复登记真实表名，但不得遮蔽任意已分配的内部别名。
            $ctx->reserveRelationQualifier($this->gateway->meta($relation->target)->table);
        } catch (InvalidArgumentException $e) {
            throw new RelationException('Internal relation alias conflict: ' . $e->getMessage(), 0, $e);
        }
        $query = new Builder($relation->target, $this->gateway);
        $target = $query->state;
        $target->relationAlias = $alias;
        $constraint = $spec->constraint;
        // 内部计划始终保留终结操作保护。
        $target->buildingRelationConstraint = true;
        if ($parts !== []) {
            // 中间层不接受自己的闭包；约束沿路径传递给最后一层。
            $target->where[] = [
                'column' => '',
                'operator' => 'EXISTS',
                'value' => new Subquery(implode('.', $parts), $constraint),
            ];
        } elseif ($constraint !== null) {
            $result = $constraint($query);
            if ($result !== null && $result !== $query) {
                throw new RelationException('Relation constraint ' . $spec->path . ' must modify the provided Builder or return the same Builder');
            }
        }
        $target->correlatedRelation = true;
        $this->materializeRelationConditions($relation->target, $target, $ctx);
        $this->assertRelationConstraint($target, $spec->path);
        $target->relationConstraintValidated = true;
        $target->projection = [$count
            ? ['kind' => 'aggregate', 'function' => 'COUNT', 'column' => '*', 'alias' => null, 'distinct' => false]
            : ['kind' => 'raw', 'sql' => '1', 'params' => []],
        ];
        $sourceQualifier = $this->tableQualifier($class, $state);
        $correlations = [];
        foreach ($relation->sourceKey as $index => $sourceField) {
            $targetField = $relation->targetKey[$index];
            $correlations[] = $this->quote($alias . '.' . $targetField->column)
                . ' = ' . $this->quote($sourceQualifier . '.' . $sourceField->column);
        }
        $target->where[] = [
            'column' => '',
            'operator' => 'CORRELATION',
            'value' => implode(' AND ', $correlations),
        ];

        return $query;
    }

    public function assertRelationConstraint(QueryState $state, string $path, bool $internal = false): void
    {
        if ($state->limit !== null || $state->offset > 0 || $state->orderBy !== []
            || ($internal && !$state->relationConstraintValidated)
            || (!$internal && $state->projection !== []) || $state->relationCounts !== [] || $state->joins !== []
            || $state->groupBy !== [] || $state->having !== [] || $state->unions !== []
            || $state->derivedFrom !== null || $state->distinct || $state->tableAlias !== null
            || $state->lockMode !== null || $state->eagerRelations !== []) {
            throw new RelationException('Relation constraint ' . $path . ' only allows structured WHERE/OR and nested relation conditions; projections, counts, JOIN, GROUP, UNION, derived tables, DISTINCT, alias, ordering, pagination, row locks, and with are not supported');
        }
        $this->assertNoRawRelationConditions($state->where, $path);
        foreach ($state->orGroups as $group) {
            $this->assertNoRawRelationGroup($group, $path);
        }
    }

    /** 展开一次内部关系计划；用户约束只执行一次，校验不会重复触发闭包。 */
    public function materializeRelationConditions(string $class, QueryState $state, RenderContext $ctx): void
    {
        if ($state->derivedFrom !== null || $state->joins !== [] || $state->unions !== []) {
            // 形状校验先拒绝这些结构，不能执行其内部闭包或静默忽略其参数。
            return;
        }
        foreach ($state->where as $index => $condition) {
            if ($condition['value'] instanceof Subquery) {
                $state->where[$index]['value'] = $this->relationQuery(
                    $class,
                    $state,
                    $condition['value'],
                    $ctx,
                    false,
                );
            }
        }
        foreach ($state->orGroups as $index => $group) {
            $state->orGroups[$index] = $this->materializeRelationGroup($class, $state, $group, $ctx);
        }
    }

    public function materializeRelationGroup(
        string $class,
        QueryState $state,
        array $group,
        RenderContext $ctx,
    ): array {
        foreach ($group['conditions'] as $index => $condition) {
            if ($condition['value'] instanceof Subquery) {
                $group['conditions'][$index]['value'] = $this->relationQuery(
                    $class,
                    $state,
                    $condition['value'],
                    $ctx,
                    false,
                );
            }
        }
        foreach ($group['groups'] as $index => $nested) {
            $group['groups'][$index] = $this->materializeRelationGroup($class, $state, $nested, $ctx);
        }

        return $group;
    }

    /** @return list<array{string, callable|null}> */
    public function relationCountItems(array $relations, string $prefix = ''): array
    {
        $items = [];
        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $items = [...$items, ...$this->relationCountItems($value, $prefix)];
                    continue;
                }
                if (!is_string($value)) {
                    throw new RelationException('withCount() positional items must be path strings or nested configuration arrays');
                }
                $items[] = [$prefix . $value, null];
            } elseif (is_callable($value)) {
                $items[] = [$prefix . $key, $value];
            } elseif (is_string($value) || is_array($value)) {
                $children = is_string($value)
                    ? $this->relationCountItems([$value], $prefix . $key . '.')
                    : $this->relationCountItems($value, $prefix . $key . '.');
                $items = [...$items, ...$children];
            } else {
                throw new RelationException('withCount() constraints must be callable or nested path arrays');
            }
        }

        return $items;
    }

    public function assertNoRawRelationConditions(array $conditions, string $path): void
    {
        foreach ($conditions as $condition) {
            if ($condition['operator'] === 'RAW') {
                throw new RelationException('Relation constraint ' . $path . ' does not support whereRaw; use structured conditions or nested whereHas()');
            }
            if ($condition['operator'] === 'CORRELATION') {
                continue;
            }
            if (str_contains($condition['column'], '.')) {
                throw new RelationException('Columns in relation constraint ' . $path . ' are automatically qualified by the current relation; explicit table prefixes are not accepted');
            }
            if ($condition['value'] instanceof Builder) {
                $nested = $condition['value'];
                if (!$nested->state->correlatedRelation) {
                    throw new RelationException('Relation constraint ' . $path . ' does not accept arbitrary subquery Builders; use nested whereHas()');
                }
                $this->assertRelationConstraint($nested->state, $path, true);
            }
        }
    }

    public function assertNoRawRelationGroup(array $group, string $path): void
    {
        $this->assertNoRawRelationConditions($group['conditions'], $path);
        foreach ($group['groups'] as $nested) {
            $this->assertNoRawRelationGroup($nested, $path);
        }
    }

    public function assertRelationParentSupported(string $class, QueryState $state): void
    {
        if (!$this->hasRelationFeatures($state)) {
            return;
        }
        if ($state->derivedFrom !== null || $state->joins !== [] || $state->groupBy !== []
            || $state->having !== [] || $state->unions !== [] || $state->distinct) {
            throw new RelationException('Relation filtering/counting requires a parent query without JOIN/GROUP/HAVING/UNION/derived tables/DISTINCT; a parent table alias is allowed');
        }
    }

    public function hasRelationFeatures(QueryState $state): bool
    {
        if ($state->relationCounts !== []) {
            return true;
        }
        foreach ($state->where as $condition) {
            if ($condition['value'] instanceof Subquery) {
                return true;
            }
        }
        foreach ($state->orGroups as $group) {
            if ($this->groupHasRelationFeatures($group)) {
                return true;
            }
        }

        return false;
    }

    public function groupHasRelationFeatures(array $group): bool
    {
        foreach ($group['conditions'] as $condition) {
            if ($condition['value'] instanceof Subquery) {
                return true;
            }
        }
        foreach ($group['groups'] as $nested) {
            if ($this->groupHasRelationFeatures($nested)) {
                return true;
            }
        }

        return false;
    }

    public function assertNoRelationCounts(QueryState $state, string $operation): void
    {
        if ($state->relationCounts !== []) {
            throw new RelationException($operation . ' cannot be combined with withCount(); split the operations into separate queries');
        }
    }

    public function assertNoRelationFilters(QueryState $state, string $operation, ?array $group = null): void
    {
        if ($state->buildingRelationConstraint) {
            return;
        }
        if ($group !== null) {
            if ($this->groupHasRelationFeatures($group)) {
                throw new RelationException($operation . ' cannot be combined with whereHas()/whereDoesntHave()/whereRelation(); split the operations into separate queries');
            }

            return;
        }
        $this->assertNoRelationFiltersInWhere($state, $operation);
        $this->assertNoRelationFiltersInGroups($state, $operation);
    }

    public function assertNoRelationFiltersInGroups(QueryState $state, string $operation): void
    {
        foreach ($state->orGroups as $group) {
            if ($this->groupHasRelationFeatures($group)) {
                throw new RelationException($operation . ' cannot be combined with whereHas()/whereDoesntHave()/whereRelation(); split the operations into separate queries');
            }
        }
    }

    public function assertNoRelationFiltersInWhere(QueryState $state, string $operation): void
    {
        foreach ($state->where as $condition) {
            if ($condition['value'] instanceof Subquery) {
                throw new RelationException($operation . ' cannot be combined with whereHas()/whereDoesntHave()/whereRelation(); split the operations into separate queries');
            }
        }
    }

    public function tableQualifier(string $class, QueryState $state): string
    {
        if ($state->tableAlias !== null) {
            return $state->tableAlias;
        }
        if ($state->relationAlias !== null) {
            return $state->relationAlias;
        }

        return $this->baseTable($class);
    }

    public function relationColumn(string $class, QueryState $state, string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }
        $field = $this->resolveField($class, $column);
        if ($state->relationAlias === null) {
            return $field === null ? $column : $field->column;
        }

        return $state->relationAlias . '.' . ($field === null ? $column : $field->column);
    }

    private function baseTable(string $class): string
    {
        return $this->gateway->meta($class)->table;
    }

    private function resolveField(string $class, string $column): ?\Pixiaoka\Orm\Metadata\FieldMeta
    {
        foreach ($this->gateway->meta($class)->fields as $field) {
            if ($field->column === $column || $field->property === $column) {
                return $field;
            }
        }

        return null;
    }

    private function quote(string $identifier): string
    {
        return $this->gateway->quoteIdentifier($identifier);
    }
}
