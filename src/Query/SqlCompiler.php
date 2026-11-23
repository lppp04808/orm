<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Relation\Subquery;

/** @internal 结构化 SELECT/UNION/UPDATE/DELETE 的纯编译器；不持有 Builder。 */
final readonly class SqlCompiler
{
    public function __construct(
        private EntityGateway    $gateway,
        private RelationCompiler $relations,
    ) {
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildSelect(string $class, QueryState $state): array
    {
        $this->assertDeferredRelationExecution($state);
        $this->relations->assertRelationParentSupported($class, $state);
        $ctx = new RenderContext();
        $sql = $this->renderSelect($class, $state, $ctx);

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildCount(string $class, QueryState $state, string $column = '*'): array
    {
        $this->assertDeferredRelationExecution($state);
        $this->relations->assertRelationParentSupported($class, $state);
        $this->relations->assertNoRelationCounts($state, 'countSql()');
        if ($this->isSimpleBaseQuery($state)) {
            $ctx = new RenderContext();
            $where = $this->renderWhereClause($class, $state, $ctx);
            $expression = $column === '*' ? '*' : $this->quote($column);
            $sql = 'SELECT COUNT(' . $expression . ') FROM ' . $this->quote($this->baseTable($class)) . $where;

            return ['sql' => $sql, 'params' => $ctx->params()];
        }

        // 复杂查询：包裹去 ORDER/LIMIT/OFFSET/lock 的完整 SELECT。
        $clone = clone $state;
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = 0;
        $clone->lockMode = null;
        $ctx = new RenderContext();
        $inner = $this->renderSelect($class, $clone, $ctx);
        $sql = 'SELECT COUNT(*) FROM (' . $inner . ') AS ' . $this->quote('orm_count');

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildUpdate(string $class, QueryState $state, array $set): array
    {
        $this->assertDeferredRelationExecution($state);
        $this->relations->assertRelationParentSupported($class, $state);
        $this->assertWritable($state);
        if ($set === []) {
            throw new InvalidArgumentException('UPDATE SET 不能为空');
        }
        if ($state->where === [] && $state->orGroups === []) {
            throw new InvalidArgumentException('UPDATE 必须有 WHERE 条件，拒绝全表更新');
        }

        $meta = $this->gateway->meta($class);
        $ctx = new RenderContext();
        $sets = [];
        foreach ($set as $column => $value) {
            $field = $this->resolveField($class, $column);
            if ($field === null) {
                throw new InvalidArgumentException('UPDATE 未知列: ' . $column);
            }
            if ($field->primary) {
                throw new InvalidArgumentException('禁止通过 UPDATE 修改主键列: ' . $field->column);
            }
            $casted = $this->castValue($class, $field->column, $value);
            $sets[] = $this->quote($field->column) . ' = ' . $ctx->bind($casted);
        }
        $where = $this->renderWhereClause($class, $state, $ctx);
        if ($where === '') {
            throw new InvalidArgumentException('UPDATE 的 WHERE 不能为空，拒绝全表更新');
        }
        $sql = 'UPDATE ' . $this->quote($meta->table) . ' SET ' . implode(', ', $sets) . $where;

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function buildDelete(string $class, QueryState $state): array
    {
        $this->assertDeferredRelationExecution($state);
        $this->relations->assertRelationParentSupported($class, $state);
        $this->assertWritable($state);
        if ($state->where === [] && $state->orGroups === []
            && $this->gateway->meta($class)->softDeleteField() === null) {
            throw new InvalidArgumentException('DELETE 必须有 WHERE 条件，拒绝全表删除');
        }
        $meta = $this->gateway->meta($class);
        $ctx = new RenderContext();
        $where = $this->renderWhereClause($class, $state, $ctx);
        if ($where === '') {
            throw new InvalidArgumentException('DELETE 的 WHERE 不能为空，拒绝全表删除');
        }
        $sql = 'DELETE FROM ' . $this->quote($meta->table) . $where;

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    public function renderSelect(string $class, QueryState $state, RenderContext $ctx): string
    {
        if ($state->unions !== []) {
            if ($state->lockMode !== null) {
                throw new InvalidArgumentException('UNION 查询不支持行锁');
            }
            $union = $this->renderUnionSql($class, $state, $ctx);
            // MySQL 的派生表不能可靠保留末尾复合查询 ORDER BY 的作用域。
            $sql = $this->gateway->driver() === 'mysql'
                ? 'SELECT * FROM (' . $union . ') AS ' . $this->quote('orm_union_result')
                : $union;
            if ($state->orderBy !== [] && $this->gateway->driver() !== 'mysql') {
                $sql = 'SELECT * FROM (' . $union . ') AS ' . $this->quote('orm_union_result');
            }
            if ($state->nestedUnion) {
                // 分支自身的排序分页先在自己的派生表内消费，整体再成为外层 UNION 分支。
                $sql .= $this->renderOrderBy($ctx, $state->orderBy)
                    . $this->renderLimitOffset($state->limit, $state->offset);

                return 'SELECT * FROM (' . $sql . ') AS ' . $this->quote('orm_union_branch');
            }
        } else {
            $sql = $this->renderSelectCore($class, $state, $ctx);
        }

        if ($state->orderBy !== []) {
            $sql .= $this->renderOrderBy($ctx, $state->orderBy);
        }

        $lock = $state->lockMode === null ? '' : ($state->lockMode === 'UPDATE' ? ' FOR UPDATE' : ' FOR SHARE');
        // PostgreSQL 的锁子句在 LIMIT/OFFSET 前；MySQL 在分页后。SQLite 执行前会明确拒绝。
        if ($lock !== '' && $this->gateway->driver() === 'pgsql') {
            $sql .= $lock . $this->renderLimitOffset($state->limit, $state->offset);
        } else {
            $sql .= $this->renderLimitOffset($state->limit, $state->offset) . $lock;
        }

        return $sql;
    }

    public function renderSelectCore(string $class, QueryState $state, RenderContext $ctx): string
    {
        $sql = 'SELECT ';
        if ($state->distinct) {
            $sql .= 'DISTINCT ';
        }
        $sql .= $this->renderProjection($class, $state, $ctx) . ' FROM ' . $this->renderFrom($class, $state, $ctx);
        $sql .= $this->renderJoins($state, $ctx);
        $sql .= $this->renderWhereClause($class, $state, $ctx);

        if ($state->groupBy !== []) {
            $cols = array_map(fn (string $c): string => $this->quote($c), $state->groupBy);
            $sql .= ' GROUP BY ' . implode(', ', $cols);
        }

        if ($state->having !== []) {
            $parts = [];
            foreach ($state->having as $having) {
                if ($having['kind'] === 'raw') {
                    $parts[] = $ctx->mergeRaw($having['sql'], $having['params'] ?? []);
                } else {
                    $expr = $this->renderHavingExpression($having['expression']);
                    $parts[] = $expr . ' ' . $having['operator'] . ' ' . $ctx->bind($this->assertScalar($having['value']));
                }
            }
            $sql .= ' HAVING ' . implode(' AND ', $parts);
        }

        return $sql;
    }

    public function renderUnionSql(string $class, QueryState $state, RenderContext $ctx): string
    {
        if ($state->lockMode !== null) {
            throw new InvalidArgumentException('UNION 查询不支持行锁');
        }
        $sql = $this->renderUnionBranch(
            $class,
            $state,
            $ctx,
            $state->unionFirstOrderBy ?? [],
            $state->unionFirstLimit,
            $state->unionFirstOffset,
        );
        foreach ($state->unions as $union) {
            $other = $union['builder'];
            $this->assertNoLock($other, 'UNION 子查询');
            $this->assertUnionCompatibility($class, $state, $other);
            $sql .= ($union['all'] ? ' UNION ALL ' : ' UNION ')
                . $this->renderUnion($other->modelClass(), $other->state, $ctx);
        }

        return $sql;
    }

    public function renderUnion(string $class, QueryState $state, RenderContext $ctx): string
    {
        $branch = clone $state;
        $branch->nestedUnion = true;
        if ($branch->unions !== []) {
            return $this->renderSelect($class, $branch, $ctx);
        }

        return $this->renderUnionBranch($class, $branch, $ctx, $branch->orderBy, $branch->limit, $branch->offset);
    }

    public function renderUnionBranch(
        string $class,
        QueryState $state,
        RenderContext $ctx,
        array $orderBy,
        ?int $limit,
        int $offset,
    ): string {
        $sql = $this->renderSelectCore($class, $state, $ctx);
        if ($orderBy !== [] || $limit !== null || $offset > 0) {
            $sql .= $this->renderOrderBy($ctx, $orderBy) . $this->renderLimitOffset($limit, $offset);
            // 各分支排序分页都先成为派生表；MySQL 尤其不能把排序留在复合分支外。
            $sql = 'SELECT * FROM (' . $sql . ') AS ' . $this->quote('orm_union');
        }

        return $sql;
    }

    public function renderProjection(string $class, QueryState $state, RenderContext $ctx): string
    {
        $this->relations->assertRelationParentSupported($class, $state);
        $this->assertProjectionAliasesUnique($class, $state);
        $parts = [];
        if ($state->projection === []) {
            $meta = $this->gateway->meta($class);
            $qualifier = null;
            if ($state->joins !== []) {
                $qualifier = $state->tableAlias;
                if ($qualifier === null) {
                    $segments = explode('.', $meta->table);
                    $qualifier = end($segments);
                }
            } elseif ($state->relationAlias !== null) {
                $qualifier = $state->relationAlias;
            }
            $columns = array_map(
                fn ($f) => $this->quote($qualifier === null ? $f->column : $qualifier . '.' . $f->column),
                array_values($meta->fields),
            );
            $parts = $columns;
        } else {
            foreach ($state->projection as $proj) {
                switch ($proj['kind']) {
                    case 'column':
                        $parts[] = $this->quote($proj['column']);
                        break;
                    case 'as':
                        $parts[] = $this->quote($proj['column']) . ' AS ' . $this->quote($proj['alias']);
                        break;
                    case 'raw':
                        $parts[] = $ctx->mergeRaw($proj['sql'], $proj['params'] ?? []);
                        break;
                    case 'aggregate':
                        $inner = $proj['column'] === '*' ? '*' : $this->quote($this->relations->relationColumn($class, $state, $proj['column']));
                        $agg = $proj['function'] . '(' . ($proj['distinct'] ? 'DISTINCT ' : '') . $inner . ')';
                        $parts[] = $agg . ($proj['alias'] !== null ? ' AS ' . $this->quote($proj['alias']) : '');
                        break;
                }
            }
        }
        foreach ($state->relationCounts as $count) {
            $subquery = $this->relations->relationQuery($class, $state, $count['relation'], $ctx, true);
            $parts[] = '(' . $this->renderSelect($subquery->modelClass(), $subquery->state, $ctx) . ') AS ' . $this->quote($count['alias']);
        }

        return implode(', ', $parts);
    }

    public function renderFrom(string $class, QueryState $state, RenderContext $ctx): string
    {
        if ($state->derivedFrom !== null) {
            $subquery = $state->derivedFrom['builder'];
            $sub = $this->renderSelect($subquery->modelClass(), $subquery->state, $ctx);

            return '(' . $sub . ') AS ' . $this->quote($state->derivedFrom['alias']);
        }

        $from = $this->quote($this->baseTable($class));
        $alias = $state->tableAlias ?? $state->relationAlias;
        if ($alias !== null) {
            $from .= ' AS ' . $this->quote($alias);
        }

        return $from;
    }

    public function renderJoins(QueryState $state, RenderContext $ctx): string
    {
        if ($state->joins === []) {
            return '';
        }
        $sql = '';
        foreach ($state->joins as $join) {
            if ($join['type'] === 'RAW') {
                $sql .= ' ' . $ctx->mergeRaw($join['raw'], $join['params'] ?? []);
                continue;
            }
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->quote($join['table']) . ' AS ' . $this->quote($join['alias']);
            /** @var JoinClause $clause */
            $clause = $join['on'];
            if ($clause->isEmpty()) {
                throw new InvalidArgumentException($join['type'] . ' JOIN 必须有 ON 条件；无 ON 请显式使用 joinRaw()');
            }
            $parts = [];
            foreach ($clause->conditions() as $cond) {
                if ($cond['kind'] === 'on') {
                    $parts[] = [
                        'boolean' => $cond['boolean'],
                        'sql' => $this->quote($cond['left']) . ' ' . $cond['operator'] . ' ' . $this->quote($cond['right']),
                    ];
                } else {
                    $parts[] = [
                        'boolean' => $cond['boolean'],
                        'sql' => $this->quote($cond['left']) . ' ' . $cond['operator'] . ' ' . $ctx->bind($this->assertScalar($cond['right'])),
                    ];
                }
            }
            $sql .= ' ON ' . $this->joinBoolean($parts);
        }

        return $sql;
    }

    /** @param list<array{boolean: string, sql: string}> $parts */
    public function joinBoolean(array $parts): string
    {
        $sql = '';
        foreach ($parts as $i => $part) {
            $sql .= ($i === 0 ? '' : ' ' . $part['boolean'] . ' ') . $part['sql'];
        }

        return $sql;
    }

    public function renderWhereClause(string $class, QueryState $state, RenderContext $ctx): string
    {
        $clauses = [];
        if ($state->softDeleteMode !== 'with') {
            $field = $this->gateway->meta($class)->softDeleteField();
            if ($field !== null) {
                $column = $this->quote($this->relations->relationColumn($class, $state, $field->property));
                $clauses[] = $column . ($state->softDeleteMode === 'only' ? ' IS NOT NULL' : ' IS NULL');
            }
        }
        foreach ($state->where as $condition) {
            $rendered = $this->renderCondition($class, $state, $condition, $ctx);
            if ($rendered !== null) {
                $clauses[] = $rendered;
            }
        }

        foreach ($state->orGroups as $group) {
            $rendered = $this->renderOrGroup($class, $state, $group, $ctx);
            if ($rendered !== null) {
                $clauses[] = $rendered;
            }
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    public function renderCondition(
        string $class,
        QueryState $state,
        array $condition,
        RenderContext $ctx,
    ): ?string {
        $column = $condition['column'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        switch ($operator) {
            case 'RAW':
                /** @var Expression $expr */
                $expr = $value;

                return $ctx->mergeRaw($expr->sql, $expr->params);

            case 'CORRELATION':
                // 仅由 RelationCompiler 用已校验的字段元数据和内部别名构造。
                return (string) $value;

            case 'EXISTS':
            case 'NOT EXISTS':
                $sub = $value instanceof Subquery
                    ? $this->relations->relationQuery($class, $state, $value, $ctx, false)
                    : $value;
                if (!$sub instanceof Builder) {
                    throw new \LogicException('EXISTS 条件必须是 Builder 或关系子查询');
                }
                $this->assertNoLock($sub, 'EXISTS 子查询');
                $inner = $this->renderSelect($sub->modelClass(), $sub->state, $ctx);

                return $operator . ' (' . $inner . ')';

            case 'IS NULL':
                return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' IS NULL';

            case 'IS NOT NULL':
                return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' IS NOT NULL';

            case 'IN':
            case 'NOT IN':
                return $this->renderIn($class, $state, $column, $operator, $value, $ctx);

            case 'BETWEEN':
                $lo = $this->castQueryValue($class, $column, $value[0]);
                $hi = $this->castQueryValue($class, $column, $value[1]);

                return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' BETWEEN ' . $ctx->bind($lo) . ' AND ' . $ctx->bind($hi);
        }

        // 标量子查询。
        if ($value instanceof Builder) {
            $this->assertSameGateway($value, '标量子查询');
            $this->assertSingleColumn($value, '标量子查询');
            $this->assertNoLock($value, '标量子查询');
            $inner = $this->renderSelect($value->modelClass(), $value->state, $ctx);

            return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' ' . $operator . ' (' . $inner . ')';
        }

        $casted = $this->castQueryValue($class, $column, $value);

        return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' ' . $operator . ' ' . $ctx->bind($casted);
    }

    public function renderIn(
        string $class,
        QueryState $state,
        string $column,
        string $operator,
        mixed $value,
        RenderContext $ctx,
    ): string {
        if ($value === []) {
            return $operator === 'IN' ? '1 = 0' : '1 = 1';
        }
        if ($value instanceof Builder) {
            $this->assertSameGateway($value, 'IN 子查询');
            $this->assertSingleColumn($value, 'IN 子查询');
            $this->assertNoLock($value, 'IN 子查询');
            $inner = $this->renderSelect($value->modelClass(), $value->state, $ctx);

            return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' ' . $operator . ' (' . $inner . ')';
        }
        $placeholders = [];
        foreach ($value as $v) {
            $placeholders[] = $ctx->bind($this->castQueryValue($class, $column, $v));
        }

        return $this->quote($this->relations->relationColumn($class, $state, $column)) . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
    }

    public function renderOrGroup(
        string $class,
        QueryState $state,
        array $group,
        RenderContext $ctx,
    ): ?string {
        $orParts = [];
        foreach ($group['conditions'] as $condition) {
            $rendered = $this->renderCondition($class, $state, $condition, $ctx);
            if ($rendered !== null) {
                $orParts[] = $rendered;
            }
        }
        foreach ($group['groups'] as $subGroup) {
            $rendered = $this->renderOrGroup($class, $state, $subGroup, $ctx);
            if ($rendered !== null) {
                $orParts[] = $rendered;
            }
        }

        return $orParts === [] ? null : '(' . implode(' OR ', $orParts) . ')';
    }

    public function renderOrderBy(RenderContext $ctx, array $orderBy): string
    {
        if ($orderBy === []) {
            return '';
        }
        $parts = [];
        foreach ($orderBy as $order) {
            $parts[] = $order['kind'] === 'raw'
                ? $ctx->mergeRaw($order['sql'], $order['params'] ?? [])
                : $this->quote($order['column']) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    public function renderLimitOffset(?int $limit = null, int $offset = 0): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }
        if ($limit === null) {
            // 只有 OFFSET 时 MySQL 仍需要 LIMIT；普通 LIMIT 不要求连接提供方言能力。
            return match ($this->gateway->driver()) {
                'sqlite' => ' LIMIT -1 OFFSET ' . $offset,
                'mysql' => ' LIMIT 18446744073709551615 OFFSET ' . $offset,
                'pgsql' => ' OFFSET ' . $offset,
                default => throw new \LogicException('不支持的数据库方言'),
            };
        }
        $sql = ' LIMIT ' . $limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    public function assertHavingExpression(string $expression): void
    {
        $trimmed = trim($expression);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $trimmed) === 1) {
            return;
        }
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(DISTINCT\s+)?(\*|[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\)$/i', $trimmed) === 1) {
            return;
        }
        throw new InvalidArgumentException('HAVING 表达式只允许标识符或受控聚合表达式: ' . $expression);
    }

    public function renderHavingExpression(string $expression): string
    {
        $trimmed = trim($expression);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $trimmed) === 1) {
            return $this->quote($trimmed);
        }
        preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(DISTINCT\s+)?(\*|[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\)$/i', $trimmed, $m);
        $function = strtoupper($m[1]);
        $distinct = isset($m[2]) && trim($m[2]) !== '' ? 'DISTINCT ' : '';
        $inner = $m[3] === '*' ? '*' : $this->quote($m[3]);

        return $function . '(' . $distinct . $inner . ')';
    }

    /** @return list<string> */
    public function projectionShape(string $class, QueryState $state): array
    {
        if ($state->projection === []) {
            $shape = array_map(
                static fn ($field): string => strtolower($field->column),
                array_values($this->gateway->meta($class)->fields),
            );
        } else {
            $shape = [];
        }
        foreach ($state->projection as $projection) {
            if ($projection['kind'] === 'raw' || ($projection['kind'] === 'aggregate' && $projection['alias'] === null)) {
                throw new InvalidArgumentException(
                    'UNION 分支请使用 select()/selectAs() 或带别名的 aggregate()；复杂 raw UNION 请使用 queryRaw()',
                );
            }
            $name = match ($projection['kind']) {
                'as', 'aggregate' => $projection['alias'],
                default => ($parts = explode('.', $projection['column']))[count($parts) - 1],
            };
            $shape[] = strtolower($name);
        }
        foreach ($state->relationCounts as $count) {
            $shape[] = strtolower($count['alias']);
        }
        if (count($shape) !== count(array_unique($shape))) {
            throw new InvalidArgumentException('UNION 分支不能包含重复的输出列名/别名');
        }

        return $shape;
    }

    public function assertProjectionAliasesUnique(string $class, QueryState $state): void
    {
        $names = [];
        if ($state->relationCounts === []) {
            foreach ($state->projection as $projection) {
                if (!in_array($projection['kind'], ['as', 'aggregate'], true) || $projection['alias'] === null) {
                    continue;
                }
                $name = strtolower($projection['alias']);
                if (isset($names[$name])) {
                    throw new InvalidArgumentException('SELECT 输出别名重复: ' . $projection['alias']);
                }
                $names[$name] = true;
            }

            return;
        }
        if ($state->projection === []) {
            foreach ($this->gateway->meta($class)->fields as $field) {
                $names[strtolower($field->column)] = true;
            }
        }
        foreach ($state->projection as $projection) {
            if ($projection['kind'] === 'raw' || ($projection['kind'] === 'aggregate' && $projection['alias'] === null)) {
                throw new RelationException('withCount() 不能与无明确输出别名的 selectRaw()/aggregate() 组合');
            }
            $name = match ($projection['kind']) {
                'as', 'aggregate' => strtolower($projection['alias']),
                default => strtolower(($parts = explode('.', $projection['column']))[count($parts) - 1]),
            };
            if (isset($names[$name])) {
                throw new InvalidArgumentException('SELECT 输出别名重复: ' . $name);
            }
            $names[$name] = true;
        }
        foreach ($state->relationCounts as $count) {
            $name = strtolower($count['alias']);
            if (isset($names[$name])) {
                throw new InvalidArgumentException('SELECT 输出别名重复: ' . $count['alias']);
            }
            $names[$name] = true;
        }
    }

    public function assertUnionCompatibility(string $class, QueryState $state, Builder $other): void
    {
        $this->assertSameGateway($other, 'UNION 分支');
        $left = $this->firstSelect($class, $state);
        $right = $this->firstSelect($other->modelClass(), $other->state);
        if ($this->projectionShape($left[0], $left[1]) !== $this->projectionShape($right[0], $right[1])) {
            throw new InvalidArgumentException('UNION 每个分支必须返回相同数量的列，且输出列名/别名必须一致');
        }
    }

    public function assertNoLock(Builder $builder, string $context): void
    {
        $state = $builder->state;
        if ($state->lockMode !== null) {
            throw new InvalidArgumentException($context . '不允许携带行锁');
        }
        if ($state->derivedFrom !== null) {
            $this->assertNoLock($state->derivedFrom['builder'], $context);
        }
        foreach ($state->unions as $union) {
            $this->assertNoLock($union['builder'], $context);
        }
        foreach ($state->where as $condition) {
            if ($condition['value'] instanceof Builder) {
                $this->assertNoLock($condition['value'], $context);
            }
        }
        foreach ($state->orGroups as $group) {
            $this->assertGroupNoLock($group, $context);
        }
    }

    public function assertGroupNoLock(array $group, string $context): void
    {
        foreach ($group['conditions'] as $condition) {
            if ($condition['value'] instanceof Builder) {
                $this->assertNoLock($condition['value'], $context);
            }
        }
        foreach ($group['groups'] as $nested) {
            $this->assertGroupNoLock($nested, $context);
        }
    }

    public function assertSingleColumn(Builder $builder, string $context): void
    {
        $state = $builder->state;
        if ($state->projection === []) {
            throw new InvalidArgumentException($context . '必须显式 select() 选择一个列');
        }
        $columns = 0;
        foreach ($state->projection as $projection) {
            if ($projection['kind'] === 'raw') {
                throw new InvalidArgumentException($context . '使用 selectRaw() 时请确保只返回一列，或显式 select() 单列');
            }
            $columns++;
        }
        if ($columns !== 1) {
            throw new InvalidArgumentException($context . '必须恰好返回一列');
        }
    }

    public function assertHydratable(QueryState $state): void
    {
        if (!$this->isSimpleBaseQuery($state)) {
            throw new InvalidArgumentException(
                '该查询包含投影/JOIN/GROUP/UNION/派生表，无法 hydrate 为实体，请使用 rows()/row()/value()'
            );
        }
    }

    public function assertWritable(QueryState $state): void
    {
        $this->assertNoEagerRelations($state, 'UPDATE/DELETE');
        if ($state->joins !== [] || $state->groupBy !== [] || $state->having !== []
            || $state->unions !== [] || $state->derivedFrom !== null || $state->tableAlias !== null
            || $state->projection !== [] || $state->relationCounts !== []
            || $state->distinct || $state->orderBy !== []
            || $state->limit !== null || $state->offset > 0 || $state->lockMode !== null) {
            throw new InvalidArgumentException(
                'UPDATE/DELETE 仅支持基表 WHERE 条件，不支持投影/JOIN/GROUP/HAVING/UNION/alias/派生表/排序/分页/行锁',
            );
        }
    }

    public function isSimpleBaseQuery(QueryState $state): bool
    {
        return $state->projection === []
            && $state->joins === []
            && $state->groupBy === []
            && $state->having === []
            && $state->unions === []
            && $state->derivedFrom === null
            && !$state->distinct
            && $state->tableAlias === null
            && $state->relationAlias === null
            && $state->relationCounts === [];
    }

    public function assertNoEagerRelations(QueryState $state, string $operation): void
    {
        if ($state->eagerRelations !== []) {
            throw new InvalidArgumentException($operation . ' 不支持 with() 预加载；请使用实体 get()/first()');
        }
    }

    public function assertDeferredRelationExecution(QueryState $state): void
    {
        if ($state->buildingRelationConstraint) {
            throw new RelationException('关系约束闭包只能声明查询条件，不得执行 get/rows/build/update/delete 等终结操作');
        }
    }

    public function assertRelationConstraintBuilderAllowed(QueryState $state): void
    {
        if ($state->buildingRelationConstraint) {
            throw new RelationException('关系约束不能通过 JOIN、UNION 或派生表改写子查询结构');
        }
    }

    public function assertSameGateway(Builder $other, string $context): void
    {
        if ($this->gateway !== $other->gateway()) {
            throw new InvalidArgumentException($context . '必须使用与父查询相同的 EntityGateway 连接');
        }
    }

    /** @return array{string, QueryState} */
    private function firstSelect(string $class, QueryState $state): array
    {
        if ($state->unions === []) {
            return [$class, $state];
        }
        $first = clone $state;
        $first->unions = [];
        $first->orderBy = $first->unionFirstOrderBy ?? [];
        $first->limit = $first->unionFirstLimit;
        $first->offset = $first->unionFirstOffset;

        return [$class, $first];
    }

    private function baseTable(string $class): string
    {
        return $this->gateway->meta($class)->table;
    }

    private function quote(string $identifier): string
    {
        return $this->gateway->quoteIdentifier($identifier);
    }

    private function castQueryValue(string $class, string $column, mixed $value): int|float|string|bool|null
    {
        if ($value instanceof Builder) {
            throw new InvalidArgumentException('标量值位置不允许子查询');
        }
        if (is_array($value) || is_resource($value)) {
            throw new InvalidArgumentException('条件值必须是标量，不允许 array/resource');
        }
        // 基表字段（不带表前缀且是模型列）走 castQueryValue。
        if (!str_contains($column, '.') && method_exists($this->gateway, 'castQueryValue')) {
            $casted = $this->gateway->castQueryValue($class, $column, $value);
            if ($casted !== null || $value === null) {
                return $casted;
            }
        }

        return $this->assertScalar($value);
    }

    private function castValue(string $class, string $column, mixed $value): int|float|string|bool|null
    {
        if (method_exists($this->gateway, 'castQueryValue')) {
            return $this->gateway->castQueryValue($class, $column, $value);
        }

        return $this->assertScalar($value);
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

    private function assertScalar(mixed $value): int|float|string|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        if (is_object($value)) {
            if ($value instanceof BackedEnum) {
                return $value->value;
            }
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if ($value instanceof Decimal) {
                return (string) $value;
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
        }
        throw new InvalidArgumentException('不支持的绑定值类型: ' . get_debug_type($value));
    }
}
