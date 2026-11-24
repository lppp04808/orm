<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Relation\Subquery;

/**
 * 结构化查询构建器：单表 / 聚合 / JOIN / 子查询 / 派生表 / UNION / 行锁 / 写操作。
 *
 * @template T of Entity
 */
final class Builder
{
    private const array ALLOWED_AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
    private const array LOCK_MODES = ['UPDATE', 'SHARE'];

    /** @internal 查询图由 QueryState 深拷贝，SQL/关系编译器不持有 Builder。 */
    public QueryState $state;

    private readonly RelationCompiler $relations;
    private readonly SqlCompiler $compiler;

    /** @param class-string<T> $class */
    public function __construct(
        private readonly string $class,
        private readonly EntityGateway $gateway,
    ) {
        $this->state = new QueryState();
        $this->relations = new RelationCompiler($gateway);
        $this->compiler = new SqlCompiler($gateway, $this->relations);
    }

    public function __clone()
    {
        $this->state = clone $this->state;
    }

    // ==================== Raw 逃生舱 ====================

    /** 工厂方法：构造 Raw 表达式。仅开发者 SQL，值必须走 $params。 */
    public static function raw(string $sql, array $params = []): Expression
    {
        return new Expression($sql, $params);
    }

    // ==================== SELECT / 投影 ====================

    /** @return $this */
    public function select(string ...$columns): self
    {
        $this->assertRelationFeatureAllowed('projections');
        foreach ($columns as $column) {
            $this->state->projection[] = ['kind' => 'column', 'column' => $column];
        }

        return $this;
    }

    /** @return $this */
    public function selectAs(string $column, string $alias): self
    {
        $this->assertRelationFeatureAllowed('projections');
        $this->state->projection[] = ['kind' => 'as', 'column' => $column, 'alias' => $alias];

        return $this;
    }

    /** @return $this */
    public function selectRaw(string $sql, array $params = []): self
    {
        $this->assertRelationFeatureAllowed('raw projections');
        $this->state->projection[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    public function distinct(): self
    {
        $this->assertRelationFeatureAllowed('DISTINCT');
        $this->state->distinct = true;

        return $this;
    }

    /** @return $this */
    public function aggregate(string $function, string $column = '*', ?string $alias = null, bool $distinct = false): self
    {
        $this->assertRelationFeatureAllowed('aggregate projections');
        $function = strtoupper(trim($function));
        if (!in_array($function, self::ALLOWED_AGGREGATES, true)) {
            throw new InvalidArgumentException('Unsupported aggregate function: ' . $function);
        }
        if ($column === '*' && $function !== 'COUNT') {
            throw new InvalidArgumentException('Only COUNT accepts the * column');
        }
        $this->state->projection[] = [
            'kind' => 'aggregate',
            'function' => $function,
            'column' => $column,
            'alias' => $alias,
            'distinct' => $distinct,
        ];

        return $this;
    }

    // ==================== FROM / 别名 / 派生表 ====================

    /** 基表别名。 @return $this */
    public function alias(string $alias): self
    {
        $this->assertRelationFeatureAllowed('table aliases');
        $this->assertIdentifier($alias, 'Table alias');
        $this->state->tableAlias = $alias;

        return $this;
    }

    /** 派生表 FROM (子查询) AS alias。 @return $this */
    public function from(Builder $subquery, string $alias): self
    {
        $this->assertRelationFeatureAllowed('derived tables');
        $this->assertRelationSubqueryAllowed($subquery);
        $this->compiler->assertRelationConstraintBuilderAllowed($subquery->state);
        $this->compiler->assertNoLock($subquery, 'derived table subquery');
        $this->compiler->assertSameGateway($subquery, 'derived table');
        $this->assertIdentifier($alias, 'Derived table alias');
        $this->state->derivedFrom = ['builder' => $subquery, 'alias' => $alias];

        return $this;
    }

    // ==================== JOIN ====================

    /** @return $this */
    public function join(string $table, string $alias, callable $on): self
    {
        return $this->addJoin('INNER', $table, $alias, $on);
    }

    /** @return $this */
    public function innerJoin(string $table, string $alias, callable $on): self
    {
        return $this->addJoin('INNER', $table, $alias, $on);
    }

    /** @return $this */
    public function leftJoin(string $table, string $alias, callable $on): self
    {
        return $this->addJoin('LEFT', $table, $alias, $on);
    }

    /** @return $this */
    public function rightJoin(string $table, string $alias, callable $on): self
    {
        return $this->addJoin('RIGHT', $table, $alias, $on);
    }

    /** @return $this */
    public function joinRaw(string $sql, array $params = []): self
    {
        $this->assertRelationFeatureAllowed('JOIN');
        $this->state->joins[] = ['type' => 'RAW', 'raw' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    private function addJoin(string $type, string $table, string $alias, callable $on): self
    {
        $this->assertRelationFeatureAllowed('JOIN');
        $this->compiler->assertRelationConstraintBuilderAllowed($this->state);
        $this->assertIdentifier($alias, 'JOIN alias');
        $clause = new JoinClause();
        $on($clause);
        $this->state->joins[] = ['type' => $type, 'table' => $table, 'alias' => $alias, 'on' => $clause];

        return $this;
    }

    // ==================== WHERE ====================

    /** @return $this */
    public function where(string $column, mixed $value): self
    {
        return $this->whereOp($column, '=', $value);
    }

    /** @return $this */
    public function whereOp(string $column, string $operator, mixed $value): self
    {
        $operator = Operator::normalize($operator);
        if ($this->state->buildingRelationConstraint) {
            $this->assertRelationConditionColumn($column);
        }
        if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
            if ($value !== null) {
                throw new InvalidArgumentException($operator . ' does not accept a comparison value');
            }
            $this->state->where[] = ['column' => $column, 'operator' => $operator, 'value' => null];

            return $this;
        }
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if ($value instanceof self) {
                $this->assertRelationSubqueryAllowed($value);
            }
            if (!is_array($value) && !$value instanceof self) {
                throw new InvalidArgumentException($operator . ' requires an array or subquery; use whereIn()/whereNotIn()');
            }
            $this->state->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

            return $this;
        }
        Operator::assertAllowed($operator);
        if ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException('BETWEEN requires an array of exactly two values [min, max]');
            }
        }
        // where('col', null) 归一化为 IS NULL
        if ($value === null && $operator === '=') {
            $operator = 'IS NULL';
        }
        if ($value === null && ($operator === '!=' || $operator === '<>')) {
            $operator = 'IS NOT NULL';
        }
        if ($value === null && !in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
            throw new InvalidArgumentException($operator . ' cannot compare against null; use whereNull()/whereNotNull()');
        }
        if ($value instanceof self) {
            $this->assertRelationSubqueryAllowed($value);
        }
        $this->state->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    /** @return $this */
    public function whereNull(string $column): self
    {
        return $this->whereOp($column, 'IS NULL', null);
    }

    /** @return $this */
    public function whereNotNull(string $column): self
    {
        return $this->whereOp($column, 'IS NOT NULL', null);
    }

    /** @param array|Builder $values 数组或子查询 @return $this */
    public function whereIn(string $column, array|Builder $values): self
    {
        return $this->whereOp($column, 'IN', $values);
    }

    /** @return $this */
    public function whereNotIn(string $column, array|Builder $values): self
    {
        return $this->whereOp($column, 'NOT IN', $values);
    }

    /** @return $this */
    public function whereExists(Builder $subquery): self
    {
        $this->assertRelationFeatureAllowed('arbitrary EXISTS subqueries; use whereHas()');
        $this->assertRelationSubqueryAllowed($subquery);
        $this->compiler->assertSameGateway($subquery, 'EXISTS subquery');
        $this->state->where[] = ['column' => '', 'operator' => 'EXISTS', 'value' => $subquery];

        return $this;
    }

    /** @return $this */
    public function whereNotExists(Builder $subquery): self
    {
        $this->assertRelationFeatureAllowed('arbitrary NOT EXISTS subqueries; use whereDoesntHave()');
        $this->assertRelationSubqueryAllowed($subquery);
        $this->compiler->assertSameGateway($subquery, 'NOT EXISTS subquery');
        $this->state->where[] = ['column' => '', 'operator' => 'NOT EXISTS', 'value' => $subquery];

        return $this;
    }

    /** 接收由框架组件生成的命名参数表达式，或调用方显式 SQL 片段。 @return $this */
    public function whereRaw(string|Expression $sql, array $params = []): self
    {
        $this->assertRelationFeatureAllowed('whereRaw()/Expression');
        if ($sql instanceof Expression) {
            if ($params !== []) {
                throw new InvalidArgumentException('Expression already has parameters; do not pass $params again');
            }
            $this->state->where[] = ['column' => '', 'operator' => 'RAW', 'value' => $sql];

            return $this;
        }
        $this->state->where[] = ['column' => '', 'operator' => 'RAW', 'value' => new Expression($sql, $params)];

        return $this;
    }

    /** OR 条件分组；支持嵌套。 @return $this */
    public function orGroup(callable $group): self
    {
        $sub = new self($this->class, $this->gateway);
        $sub->state->buildingRelationConstraint = $this->state->buildingRelationConstraint;
        try {
            $group($sub);
        } finally {
            if ($this->state->buildingRelationConstraint) {
                $this->relations->assertRelationConstraint($sub->state, 'OR group');
            }
        }
        if (!$this->state->buildingRelationConstraint) {
            $this->relations->assertNoRelationCounts($sub->state, 'withCount() in orGroup()');
            if ($this->state->relationCounts !== []) {
                $this->relations->assertNoRelationFilters($this->state, 'relation filters combined with withCount() in orGroup()', [
                    'conditions' => $sub->state->where,
                    'groups' => $sub->state->orGroups,
                ]);
            }
        }
        $this->state->orGroups[] = ['conditions' => $sub->state->where, 'groups' => $sub->state->orGroups];

        return $this;
    }

    // ==================== GROUP BY / HAVING ====================

    /** @return $this */
    public function groupBy(string ...$columns): self
    {
        $this->assertRelationFeatureAllowed('GROUP BY');
        foreach ($columns as $column) {
            $this->state->groupBy[] = $column;
        }

        return $this;
    }

    /** 受控 HAVING：expression 必须是合法聚合表达式或标识符，不能放开任意注入。 @return $this */
    public function having(string $expression, string $operator, mixed $value): self
    {
        $this->assertRelationFeatureAllowed('HAVING');
        $this->compiler->assertHavingExpression($expression);
        $operator = Operator::assertComparison($operator);
        $this->assertScalar($value);
        $this->state->having[] = ['kind' => 'structured', 'expression' => $expression, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    /** @return $this */
    public function havingRaw(string $sql, array $params = []): self
    {
        $this->assertRelationFeatureAllowed('HAVING');
        $this->state->having[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    // ==================== UNION ====================

    /** @return $this */
    public function union(Builder $other, bool $all = false): self
    {
        $this->assertRelationFeatureAllowed('UNION');
        $this->assertRelationSubqueryAllowed($other);
        $this->compiler->assertRelationConstraintBuilderAllowed($other->state);
        $this->compiler->assertNoLock($other, 'UNION subquery');
        if ($this->state->lockMode !== null) {
            throw new InvalidArgumentException('Row-lock queries cannot use UNION');
        }
        $this->compiler->assertUnionCompatibility($this->class, $this->state, $other);
        if ($this->state->unions === []) {
            $this->state->unionFirstOrderBy = $this->state->orderBy;
            $this->state->unionFirstLimit = $this->state->limit;
            $this->state->unionFirstOffset = $this->state->offset;
            $this->state->orderBy = [];
            $this->state->limit = null;
            $this->state->offset = 0;
        }
        $this->state->unions[] = ['builder' => $other, 'all' => $all];

        return $this;
    }

    /** @return $this */
    public function unionAll(Builder $other): self
    {
        return $this->union($other, true);
    }

    // ==================== ORDER / LIMIT / OFFSET ====================

    /** @return $this */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertRelationFeatureAllowed('ordering');
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('ORDER BY direction must be ASC or DESC');
        }
        $this->state->orderBy[] = ['kind' => 'column', 'column' => $column, 'direction' => $direction];

        return $this;
    }

    /** @return $this */
    public function orderByRaw(string $sql, array $params = []): self
    {
        $this->assertRelationFeatureAllowed('ordering');
        $this->state->orderBy[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    public function limit(int $limit): self
    {
        $this->assertRelationFeatureAllowed('pagination');
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT cannot be negative');
        }
        $this->state->limit = $limit;

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): self
    {
        $this->assertRelationFeatureAllowed('pagination');
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET cannot be negative');
        }
        $this->state->offset = $offset;

        return $this;
    }

    // ==================== 行锁 ====================

    /** @return $this */
    public function forUpdate(): self
    {
        return $this->lock('UPDATE');
    }

    /** @return $this */
    public function forShare(): self
    {
        return $this->lock('SHARE');
    }

    /** @return $this */
    public function lock(string $mode): self
    {
        $this->assertRelationFeatureAllowed('row locks');
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, self::LOCK_MODES, true)) {
            throw new InvalidArgumentException('Row-lock mode must be UPDATE or SHARE');
        }
        if ($this->state->unions !== []) {
            throw new InvalidArgumentException('UNION queries do not support row locks');
        }
        $this->state->lockMode = $mode;

        return $this;
    }

    // ==================== 只读关系 ====================

    /** @param string|array<int|string, mixed> $relations @return $this */
    public function with(string|array $relations, string ...$more): self
    {
        $this->assertRelationFeatureAllowed('with() eager loading');
        $this->state->eagerRelations[] = $relations;
        foreach ($more as $relation) {
            $this->state->eagerRelations[] = $relation;
        }

        return $this;
    }

    /** @param callable(Builder): mixed|null $constraint @return $this */
    public function whereHas(string $path, ?callable $constraint = null): self
    {
        $this->relations->assertNoRelationCounts($this->state, 'whereHas()');
        $this->state->where[] = [
            'column' => '',
            'operator' => 'EXISTS',
            'value' => new Subquery($path, $constraint),
        ];

        return $this;
    }

    /** @param callable(Builder): mixed|null $constraint @return $this */
    public function whereDoesntHave(string $path, ?callable $constraint = null): self
    {
        $this->relations->assertNoRelationCounts($this->state, 'whereDoesntHave()');
        $this->state->where[] = [
            'column' => '',
            'operator' => 'NOT EXISTS',
            'value' => new Subquery($path, $constraint),
        ];

        return $this;
    }

    /** 三个参数表示 field = value；四个参数表示 field operator value。 @return $this */
    public function whereRelation(string $path, string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 3) {
            return $this->whereHas(
                $path,
                static fn (self $query): self => $query->where($column, $operatorOrValue),
            );
        }

        return $this->whereHas(
            $path,
            static fn (self $query): self => $query->whereOp($column, $operatorOrValue, $value),
        );
    }

    /** @param string|array<int|string, mixed> $relations @param callable(Builder): mixed|null $constraint @return $this */
    public function withCount(string|array $relations, ?callable $constraint = null, ?string $alias = null): self
    {
        $this->assertRelationFeatureAllowed('withCount()');
        $this->relations->assertNoRelationFilters($this->state, 'withCount()');
        if (is_string($relations)) {
            $this->addRelationCount(
                $relations,
                $constraint,
                $alias ?? str_replace('.', '_', $relations) . '_count',
            );

            return $this;
        }
        if ($constraint !== null || $alias !== null) {
            throw new RelationException('For withCount() array configuration, declare constraints on their corresponding paths; do not pass a separate constraint or alias');
        }
        foreach ($this->relations->relationCountItems($relations) as [$path, $itemConstraint]) {
            $this->addRelationCount($path, $itemConstraint, str_replace('.', '_', $path) . '_count');
        }

        return $this;
    }

    private function addRelationCount(string $path, ?callable $constraint, string $alias): void
    {
        $this->assertIdentifier($alias, 'Relation count alias');
        $this->state->relationCounts[] = [
            'relation' => new Subquery($path, $constraint),
            'alias' => $alias,
        ];
    }

    /** @internal 关系子查询只在整棵关系图成功后发布，不能提前覆盖缓存身份。 @return list<T> */
    public function withoutCache(): array
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertHydratable($this->state);

        return $this->gateway->executeQuery($this->class, $this->build(), cache: false);
    }

    /** @internal 关系 Loader 只读检查，不改变查询。 */
    public function relationShape(): array
    {
        $this->relations->assertRelationParentSupported($this->class, $this->state);

        return [
            'projection' => $this->state->projection !== [],
            'joins' => $this->state->joins !== [],
            'groupBy' => $this->state->groupBy !== [],
            'having' => $this->state->having !== [],
            'unions' => $this->state->unions !== [],
            'derivedFrom' => $this->state->derivedFrom !== null,
            'distinct' => $this->state->distinct,
            'alias' => $this->state->tableAlias !== null,
            'lock' => $this->state->lockMode !== null,
            'limit' => $this->state->limit,
            'offset' => $this->state->offset,
            'order' => array_values(array_filter(array_map(
                static fn (array $order): ?string => $order['kind'] === 'column' ? $order['column'] : null,
                $this->state->orderBy,
            ))),
            'ordered' => $this->state->orderBy !== [],
            'relations' => $this->state->eagerRelations,
            'counts' => $this->state->relationCounts !== [],
        ];
    }

    // ==================== 执行 ====================

    /** @return list<T> */
    public function get(): array
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertHydratable($this->state);

        return $this->gateway->executeQuery(
            $this->class,
            $this->build(),
            $this->state->eagerRelations,
            $this->state->cacheSoftDeleteMode,
        );
    }

    /** @return T|null */
    public function first(): ?Entity
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertHydratable($this->state);
        $clone = clone $this;
        $clone->state->limit = 1;

        return $this->gateway->executeQueryOne(
            $this->class,
            $clone->build(),
            $this->state->eagerRelations,
            $this->state->cacheSoftDeleteMode,
        );
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertNoEagerRelations($this->state, 'rows()');

        return $this->gateway->executeRows($this->class, $this->build());
    }

    /** 单行原始结果（强制 LIMIT 1 clone）。 */
    public function row(): ?array
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertNoEagerRelations($this->state, 'row()');
        $clone = clone $this;
        $clone->state->limit = 1;

        return $this->gateway->executeRow($this->class, $clone->build());
    }

    /** 标量值（COUNT/SUM/单值列）。$column 指定返回列，null 取首列。 */
    public function value(?string $column = null): mixed
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertNoEagerRelations($this->state, 'value()');
        $clone = clone $this;
        $clone->state->limit = 1;

        return $this->gateway->executeValue($this->class, $clone->build(), $column);
    }

    public function count(string $column = '*'): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $this->compiler->assertNoEagerRelations($this->state, 'count()');
        $this->relations->assertNoRelationCounts($this->state, 'count()');

        return $this->gateway->executeCountQuery($this->countSql($column));
    }

    /** 按条件更新，返回受影响行数。无 WHERE、空 SET 或主键更新会被拒绝。 */
    public function update(array $values): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);

        return $this->gateway->executeUpdate($this, $values);
    }

    /** 按条件原子增加数值字段，可附带更新其他字段，返回受影响行数。 */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);

        return $this->gateway->executeDeltaUpdate($this, $column, '+', $amount, $extra);
    }

    /** 按条件原子减少数值字段，可附带更新其他字段，返回受影响行数。 */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);

        return $this->gateway->executeDeltaUpdate($this, $column, '-', $amount, $extra);
    }

    /** 按条件删除，返回受影响行数。无 WHERE 会被拒绝。 */
    public function delete(): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);

        return $this->gateway->executeDelete($this);
    }

    /** 查询包含已软删除的行；实体恢复请用 $entity->restore()。 @return $this */
    public function withTrashed(): self
    {
        $this->state->softDeleteMode = 'with';
        $this->state->cacheSoftDeleteMode = 'with';

        return $this;
    }

    /** 只查询已软删除的行。 @return $this */
    public function onlyTrashed(): self
    {
        if ($this->gateway->meta($this->class)->softDeleteField() === null) {
            throw new InvalidArgumentException($this->class . ' does not declare a soft-delete field');
        }
        $this->state->softDeleteMode = 'only';
        $this->state->cacheSoftDeleteMode = 'only';

        return $this;
    }

    /** 批量恢复符合当前条件的软删除行；不会触碰未删除行。 */
    public function restore(): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);
        $field = $this->gateway->meta($this->class)->softDeleteField();
        if ($field === null) {
            throw new InvalidArgumentException($this->class . ' does not declare a soft-delete field');
        }
        $this->state->softDeleteMode = 'only';
        $this->state->cacheSoftDeleteMode = 'only';

        return $this->gateway->executeRestore($this);
    }

    /** 强制物理删除符合当前条件的行，默认仍只针对未软删除的行。 */
    public function forceDelete(): int
    {
        $this->compiler->assertDeferredRelationExecution($this->state);

        return $this->gateway->executeDelete($this, true);
    }

    /** @internal @return class-string<T> */
    public function modelClass(): string
    {
        return $this->class;
    }

    /** @internal 关系约束只能在同一个数据库连接上执行。 */
    public function gateway(): EntityGateway
    {
        return $this->gateway;
    }

    // ==================== SQL 构建门面 ====================

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function build(): array
    {
        return $this->compiler->buildSelect($this->class, $this->state);
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function countSql(string $column = '*'): array
    {
        return $this->compiler->buildCount($this->class, $this->state, $column);
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildUpdate(array $set): array
    {
        return $this->compiler->buildUpdate($this->class, $this->state, $set);
    }

    /** @internal @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildDeltaUpdate(string $column, string $operator, int|float $amount, array $extra = []): array
    {
        return $this->compiler->buildDeltaUpdate($this->class, $this->state, $column, $operator, $amount, $extra);
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildDelete(): array
    {
        return $this->compiler->buildDelete($this->class, $this->state);
    }

    // ==================== DSL 校验 ====================

    private function assertRelationFeatureAllowed(string $feature): void
    {
        if ($this->state->buildingRelationConstraint) {
            throw new RelationException('Relation constraints do not support ' . $feature . '; only structured WHERE/OR and nested relation conditions are allowed');
        }
    }

    private function assertRelationConditionColumn(string $column): void
    {
        if (str_contains($column, '.')) {
            throw new RelationException('Columns in relation constraints are automatically qualified by the current relation; explicit table prefixes are not accepted');
        }
    }

    private function assertRelationSubqueryAllowed(self $subquery): void
    {
        if ($this->state->buildingRelationConstraint && !$subquery->state->buildingRelationConstraint) {
            throw new RelationException('Relation constraints do not accept arbitrary subquery Builders; use nested whereHas()');
        }
    }

    private function assertIdentifier(string $identifier, string $what): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException($what . ' is not a valid identifier: ' . $identifier);
        }
    }

    private function assertScalar(mixed $value): int|float|string|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        if (is_object($value)) {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if ($value instanceof \Pixiaoka\Orm\Cast\Decimal) {
                return (string) $value;
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
        }
        throw new InvalidArgumentException('Unsupported bound value type: ' . get_debug_type($value));
    }
}
