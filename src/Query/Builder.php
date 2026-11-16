<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;

/**
 * 结构化查询构建器：单表 / 聚合 / JOIN / 子查询 / 派生表 / UNION / 行锁 / 写操作。
 *
 * 基础单表查询（无投影、无 JOIN、无 UNION、无派生表）的 SQL 与历史实现精确兼容：
 * WHERE 值走 :qN 命名参数、参数从 q0 起每次 build 独立编号、OR 分组括号语义不变。
 *
 * @template T of Entity
 */
final class Builder
{
    private const ALLOWED_AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
    private const LOCK_MODES = ['UPDATE', 'SHARE'];

    /** @var list<array{column: string, operator: string, value: mixed}> */
    private array $where = [];

    /** @var list<array{conditions: list<array{column: string, operator: string, value: mixed}>, groups: array}> */
    private array $orGroups = [];

    /** @var list<array{kind: 'column'|'as'|'raw'|'aggregate', column?: string, alias?: string, sql?: string, params?: array, function?: string, distinct?: bool}> */
    private array $orderBy = [];

    /** @var list<array{kind: 'column'|'as'|'raw'|'aggregate', column?: string, alias?: string, sql?: string, params?: array, function?: string, distinct?: bool}> */
    private array $projection = [];

    private bool $distinct = false;
    private ?string $tableAlias = null;

    /** @var array{builder: self, alias: string}|null */
    private ?array $derivedFrom = null;

    /** @var list<array{type: string, table?: string, alias?: string, on?: JoinClause, raw?: string, params?: array}> */
    private array $joins = [];

    /** @var list<string> */
    private array $groupBy = [];

    /** @var list<array{kind: 'structured'|'raw', expression?: string, operator?: string, value?: mixed, sql?: string, params?: array}> */
    private array $having = [];

    /** @var list<array{builder: self, all: bool}> */
    private array $unions = [];

    /**
     * 调用 union()/unionAll() 时冻结首分支的局部排序分页。
     * 之后继续链式调用的 orderBy()/limit()/offset() 属于 UNION 顶层。
     *
     * @var list<array{kind: string, column?: string, direction?: string, sql?: string, params?: array}>|null
     */
    private ?array $unionFirstOrderBy = null;
    private ?int $unionFirstLimit = null;
    private int $unionFirstOffset = 0;

    private int $offset = 0;
    private ?int $limit = null;
    private ?string $lockMode = null;

    /** 嵌套 UNION 作为另一复合查询的分支时，必须隔离分支边界和顶层排序分页。 */
    private bool $nestedUnion = false;

    /**
     * @param class-string<T> $class
     */
    public function __construct(
        private readonly string $class,
        private readonly EntityGateway $gateway,
    ) {
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
        foreach ($columns as $column) {
            $this->projection[] = ['kind' => 'column', 'column' => $column];
        }

        return $this;
    }

    /** @return $this */
    public function selectAs(string $column, string $alias): self
    {
        $this->projection[] = ['kind' => 'as', 'column' => $column, 'alias' => $alias];

        return $this;
    }

    /** @return $this */
    public function selectRaw(string $sql, array $params = []): self
    {
        $this->projection[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /** @return $this */
    public function aggregate(string $function, string $column = '*', ?string $alias = null, bool $distinct = false): self
    {
        $function = strtoupper(trim($function));
        if (!in_array($function, self::ALLOWED_AGGREGATES, true)) {
            throw new InvalidArgumentException('非法聚合函数: ' . $function);
        }
        if ($column === '*' && $function !== 'COUNT') {
            throw new InvalidArgumentException('只有 COUNT 允许 * 列');
        }
        $this->projection[] = [
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
        $this->assertIdentifier($alias, '表别名');
        $this->tableAlias = $alias;

        return $this;
    }

    /** 派生表 FROM (子查询) AS alias。 @return $this */
    public function from(Builder $subquery, string $alias): self
    {
        $subquery->assertNoLock('派生表子查询');
        $this->assertSameGateway($subquery, '派生表');
        $this->assertIdentifier($alias, '派生表别名');
        $this->derivedFrom = ['builder' => $subquery, 'alias' => $alias];

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
        $this->joins[] = ['type' => 'RAW', 'raw' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    private function addJoin(string $type, string $table, string $alias, callable $on): self
    {
        $this->assertIdentifier($alias, 'JOIN 别名');
        $clause = new JoinClause();
        $on($clause);
        $this->joins[] = ['type' => $type, 'table' => $table, 'alias' => $alias, 'on' => $clause];

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
        if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
            if ($value !== null) {
                throw new InvalidArgumentException($operator . ' 不接受比较值');
            }
            $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => null];

            return $this;
        }
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value) && !$value instanceof self) {
                throw new InvalidArgumentException($operator . ' 需要数组或子查询，请用 whereIn()/whereNotIn()');
            }
            $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

            return $this;
        }
        Operator::assertAllowed($operator);
        if ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException('BETWEEN 需要恰好两个值的数组 [min, max]');
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
            throw new InvalidArgumentException($operator . ' 不能与 null 比较，请使用 whereNull()/whereNotNull()');
        }
        $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

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

    /**
     * @param array|Builder $values 数组或子查询
     * @return $this
     */
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
        $this->assertSameGateway($subquery, 'EXISTS 子查询');
        $this->where[] = ['column' => '', 'operator' => 'EXISTS', 'value' => $subquery];

        return $this;
    }

    /** @return $this */
    public function whereNotExists(Builder $subquery): self
    {
        $this->assertSameGateway($subquery, 'NOT EXISTS 子查询');
        $this->where[] = ['column' => '', 'operator' => 'NOT EXISTS', 'value' => $subquery];

        return $this;
    }

    /** @return $this */
    public function whereRaw(string $sql, array $params = []): self
    {
        $this->where[] = ['column' => '', 'operator' => 'RAW', 'value' => new Expression($sql, $params)];

        return $this;
    }

    /**
     * OR 条件分组。传入闭包，里面用 $q->where(...) 串联；闭包内可再嵌套 orGroup。
     * 最终产生 (a = 1 OR b = 2 OR (c = 3)) 的效果。
     *
     * @return $this
     */
    public function orGroup(callable $group): self
    {
        $sub = new self($this->class, $this->gateway);
        $group($sub);
        $this->orGroups[] = ['conditions' => $sub->where, 'groups' => $sub->orGroups];

        return $this;
    }

    // ==================== GROUP BY / HAVING ====================

    /** @return $this */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $column;
        }

        return $this;
    }

    /** 受控 HAVING：expression 必须是合法聚合表达式或标识符，不能放开任意注入。 @return $this */
    public function having(string $expression, string $operator, mixed $value): self
    {
        $this->assertHavingExpression($expression);
        $operator = Operator::assertComparison($operator);
        $this->assertScalar($value, false);
        $this->having[] = ['kind' => 'structured', 'expression' => $expression, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    /** @return $this */
    public function havingRaw(string $sql, array $params = []): self
    {
        $this->having[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    // ==================== UNION ====================

    /** @return $this */
    public function union(Builder $other, bool $all = false): self
    {
        $other->assertNoLock('UNION 子查询');
        if ($this->lockMode !== null) {
            throw new InvalidArgumentException('行锁查询不允许 UNION');
        }
        $this->assertUnionCompatibility($other);
        if ($this->unions === []) {
            $this->unionFirstOrderBy = $this->orderBy;
            $this->unionFirstLimit = $this->limit;
            $this->unionFirstOffset = $this->offset;
            $this->orderBy = [];
            $this->limit = null;
            $this->offset = 0;
        }
        $this->unions[] = ['builder' => $other, 'all' => $all];

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
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('ORDER BY 方向只允许 ASC/DESC');
        }
        $this->orderBy[] = ['kind' => 'column', 'column' => $column, 'direction' => $direction];

        return $this;
    }

    /** @return $this */
    public function orderByRaw(string $sql, array $params = []): self
    {
        $this->orderBy[] = ['kind' => 'raw', 'sql' => $sql, 'params' => $params];

        return $this;
    }

    /** @return $this */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT 不能为负数');
        }
        $this->limit = $limit;

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET 不能为负数');
        }
        $this->offset = $offset;

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
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, self::LOCK_MODES, true)) {
            throw new InvalidArgumentException('行锁模式只允许 UPDATE/SHARE');
        }
        if ($this->unions !== []) {
            throw new InvalidArgumentException('UNION 查询不支持行锁');
        }
        $this->lockMode = $mode;

        return $this;
    }

    // ==================== 执行 ====================

    /** @return list<T> */
    public function get(): array
    {
        $this->assertHydratable();

        return $this->gateway->executeQuery($this->class, $this->build());
    }

    /** @return T|null */
    public function first(): ?Entity
    {
        $this->assertHydratable();
        $clone = clone $this;
        $clone->limit = 1;

        return $this->gateway->executeQueryOne($this->class, $clone->build());
    }

    /**
     * 原始行列表（聚合 / JOIN / 投影 / UNION / 派生表走这里，不强转实体）。
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->gateway->executeRows($this->class, $this->build());
    }

    /** 单行原始结果（强制 LIMIT 1 clone）。 */
    public function row(): ?array
    {
        $clone = clone $this;
        $clone->limit = 1;

        return $this->gateway->executeRow($this->class, $clone->build());
    }

    /** 标量值（COUNT/SUM/单值列）。$column 指定返回列，null 取首列。 */
    public function value(?string $column = null): mixed
    {
        $clone = clone $this;
        $clone->limit = 1;

        return $this->gateway->executeValue($this->class, $clone->build(), $column);
    }

    public function count(string $column = '*'): int
    {
        return $this->gateway->executeCountQuery($this->countSql($column));
    }

    /** 按条件更新，返回受影响行数。无 WHERE、空 SET 或主键更新会被拒绝。 */
    public function update(array $values): int
    {
        return $this->gateway->executeUpdate($this, $values);
    }

    /** 按条件删除，返回受影响行数。无 WHERE 会被拒绝。 */
    public function delete(): int
    {
        return $this->gateway->executeDelete($this);
    }

    /** @internal @return class-string<T> */
    public function modelClass(): string
    {
        return $this->class;
    }

    // ==================== SQL 构建 ====================

    /** @return array{sql: string, params: array<string, int|float|string|bool|null>} */
    public function build(): array
    {
        $ctx = new RenderContext();
        $sql = $this->renderSelect($ctx);

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    public function __clone()
    {
        // nestedUnion 只描述本次渲染位置，不应随后续 clone 泄漏到其他上下文。
        $this->nestedUnion = false;
    }

    /**
     * COUNT 查询：
     * - 基础单表：SELECT COUNT(*) FROM table WHERE ...（去掉 ORDER/LIMIT/OFFSET/lock，保持旧行为）。
     * - 复杂查询/GROUP/UNION/派生表：SELECT COUNT(*) FROM (<去 ORDER/LIMIT/OFFSET/lock 的结构化 SELECT>) AS "orm_count"。
     *
     * @return array{sql: string, params: array<string, int|float|string|bool|null>}
     */
    public function countSql(string $column = '*'): array
    {
        if ($this->isSimpleBaseQuery()) {
            $ctx = new RenderContext();
            $where = $this->renderWhereClause($ctx);
            $expression = $column === '*' ? '*' : $this->quote($column);
            $sql = 'SELECT COUNT(' . $expression . ') FROM ' . $this->quote($this->baseTable()) . $where;

            return ['sql' => $sql, 'params' => $ctx->params()];
        }

        // 复杂查询：包裹去 ORDER/LIMIT/OFFSET/lock 的完整 SELECT
        $clone = clone $this;
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = 0;
        $clone->lockMode = null;
        $ctx = new RenderContext();
        $inner = $clone->renderSelect($ctx);
        $sql = 'SELECT COUNT(*) FROM (' . $inner . ') AS ' . $this->quote('orm_count');

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    /**
     * 结构化 UPDATE。SET 值走基表 Caster，禁止修改主键，必须有 WHERE，
     * 不允许 JOIN/GROUP/UNION/alias/派生表。
     *
     * @param array<string, mixed> $set 列名(或属性名) => 值
     * @return array{sql: string, params: array<string, int|float|string|bool|null>}
     */
    public function buildUpdate(array $set): array
    {
        $this->assertWritable();
        if ($set === []) {
            throw new InvalidArgumentException('UPDATE SET 不能为空');
        }
        if ($this->where === [] && $this->orGroups === []) {
            throw new InvalidArgumentException('UPDATE 必须有 WHERE 条件，拒绝全表更新');
        }

        $meta = $this->gateway->meta($this->class);
        $ctx = new RenderContext();
        $sets = [];
        foreach ($set as $column => $value) {
            $field = $this->resolveField($column);
            if ($field === null) {
                throw new InvalidArgumentException('UPDATE 未知列: ' . $column);
            }
            if ($field->primary) {
                throw new InvalidArgumentException('禁止通过 UPDATE 修改主键列: ' . $field->column);
            }
            $casted = $this->castValue($field->column, $value);
            $sets[] = $this->quote($field->column) . ' = ' . $ctx->bind($casted);
        }
        $where = $this->renderWhereClause($ctx);
        if ($where === '') {
            throw new InvalidArgumentException('UPDATE 的 WHERE 不能为空，拒绝全表更新');
        }
        $sql = 'UPDATE ' . $this->quote($meta->table) . ' SET ' . implode(', ', $sets) . $where;

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    /**
     * 结构化 DELETE。必须有 WHERE，不允许 JOIN/GROUP/UNION/alias/派生表。
     *
     * @return array{sql: string, params: array<string, int|float|string|bool|null>}
     */
    public function buildDelete(): array
    {
        $this->assertWritable();
        if ($this->where === [] && $this->orGroups === []) {
            throw new InvalidArgumentException('DELETE 必须有 WHERE 条件，拒绝全表删除');
        }
        $meta = $this->gateway->meta($this->class);
        $ctx = new RenderContext();
        $where = $this->renderWhereClause($ctx);
        if ($where === '') {
            throw new InvalidArgumentException('DELETE 的 WHERE 不能为空，拒绝全表删除');
        }
        $sql = 'DELETE FROM ' . $this->quote($meta->table) . $where;

        return ['sql' => $sql, 'params' => $ctx->params()];
    }

    // ==================== 内部：SELECT 渲染 ====================

    private function renderSelect(RenderContext $ctx): string
    {
        if ($this->unions !== []) {
            if ($this->lockMode !== null) {
                throw new InvalidArgumentException('UNION 查询不支持行锁');
            }
            $union = $this->renderUnionSql($ctx);
            // MySQL 的派生表不能可靠保留末尾复合查询 ORDER BY 的作用域，
            // 明确在 UNION 整体外再包一层，再应用本 Builder 的顶层排序。
            $sql = $this->gateway->driver() === 'mysql'
                ? 'SELECT * FROM (' . $union . ') AS ' . $this->quote('orm_union_result')
                : $union;
            if ($this->orderBy !== [] && $this->gateway->driver() !== 'mysql') {
                $sql = 'SELECT * FROM (' . $union . ') AS ' . $this->quote('orm_union_result');
            }
            if ($this->nestedUnion) {
                // 分支自身的排序分页先在自己的派生表内消费，整体再成为外层 UNION 分支。
                // SQLite 不能依赖复合分支括号；MySQL/PG 也统一此结构，避免分页泄漏到父级。
                $sql .= $this->renderOrderBy($ctx, $this->orderBy)
                    . $this->renderLimitOffset($this->limit, $this->offset);

                return 'SELECT * FROM (' . $sql . ') AS ' . $this->quote('orm_union_branch');
            }
        } else {
            $sql = $this->renderSelectCore($ctx);
        }

        // ORDER BY
        if ($this->orderBy !== []) {
            $sql .= $this->renderOrderBy($ctx, $this->orderBy);
        }

        $lock = $this->lockMode === null ? '' : ($this->lockMode === 'UPDATE' ? ' FOR UPDATE' : ' FOR SHARE');
        // PostgreSQL 的锁子句在 LIMIT/OFFSET 前；MySQL 在分页后。SQLite 执行前会明确拒绝。
        if ($lock !== '' && $this->gateway->driver() === 'pgsql') {
            $sql .= $lock . $this->renderLimitOffset($this->limit, $this->offset);
        } else {
            $sql .= $this->renderLimitOffset($this->limit, $this->offset) . $lock;
        }

        return $sql;
    }

    /** 递归展平 UNION 分支。带局部分页的分支按方言选择合法表达。 */
    private function renderUnionSql(RenderContext $ctx): string
    {
        if ($this->lockMode !== null) {
            throw new InvalidArgumentException('UNION 查询不支持行锁');
        }
        $sql = $this->renderUnionBranch(
            $ctx,
            $this->unionFirstOrderBy ?? [],
            $this->unionFirstLimit,
            $this->unionFirstOffset ?? 0,
        );
        foreach ($this->unions as $union) {
            $other = $union['builder'];
            $other->assertNoLock('UNION 子查询');
            $this->assertUnionCompatibility($other);
            $sql .= ($union['all'] ? ' UNION ALL ' : ' UNION ') . $other->renderUnion($ctx);
        }

        return $sql;
    }

    /** 子查询中的 UNION 必须保持为独立表达式，不能把后续分支泄漏给父级。 */
    private function renderUnion(RenderContext $ctx): string
    {
        $branch = clone $this;
        $branch->nestedUnion = true;
        if ($branch->unions !== []) {
            return $branch->renderSelect($ctx);
        }

        return $branch->renderUnionBranch($ctx, $branch->orderBy, $branch->limit, $branch->offset);
    }

    /** 分支局部分页用派生表表达，避免 SQLite 的复合查询分支语法限制。 */
    private function renderUnionBranch(
        RenderContext $ctx,
        array $orderBy,
        ?int $limit,
        int $offset,
    ): string {
        $sql = $this->renderSelectCore($ctx);
        if ($orderBy !== [] || $limit !== null || $offset > 0) {
            $sql .= $this->renderOrderBy($ctx, $orderBy) . $this->renderLimitOffset($limit, $offset);
            // 各分支排序分页都先成为派生表；MySQL 尤其不能把排序留在复合分支外。
            $sql = 'SELECT * FROM (' . $sql . ') AS ' . $this->quote('orm_union');
        }

        return $sql;
    }

    private function renderOrderBy(RenderContext $ctx, array $orderBy): string
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

    /** 渲染 SELECT ... [GROUP BY/HAVING]（不含 ORDER/LIMIT/OFFSET/lock/UNION）。 */
    private function renderSelectCore(RenderContext $ctx): string
    {
        $sql = 'SELECT ';
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }
        $sql .= $this->renderProjection($ctx) . ' FROM ' . $this->renderFrom($ctx);
        $sql .= $this->renderJoins($ctx);
        $sql .= $this->renderWhereClause($ctx);

        if ($this->groupBy !== []) {
            $cols = array_map(fn (string $c): string => $this->quote($c), $this->groupBy);
            $sql .= ' GROUP BY ' . implode(', ', $cols);
        }

        if ($this->having !== []) {
            $parts = [];
            foreach ($this->having as $having) {
                if ($having['kind'] === 'raw') {
                    $parts[] = $ctx->mergeRaw($having['sql'], $having['params'] ?? []);
                } else {
                    $expr = $this->renderHavingExpression($having['expression']);
                    $parts[] = $expr . ' ' . $having['operator'] . ' ' . $ctx->bind($this->assertScalar($having['value'], true));
                }
            }
            $sql .= ' HAVING ' . implode(' AND ', $parts);
        }

        return $sql;
    }

    private function renderProjection(RenderContext $ctx): string
    {
        $this->assertProjectionAliasesUnique();
        if ($this->projection === []) {
            $meta = $this->gateway->meta($this->class);
            $qualifier = null;
            if ($this->joins !== []) {
                $qualifier = $this->tableAlias;
                if ($qualifier === null) {
                    $segments = explode('.', $meta->table);
                    $qualifier = end($segments);
                }
            }
            $columns = array_map(
                fn ($f) => $this->quote($qualifier === null ? $f->column : $qualifier . '.' . $f->column),
                array_values($meta->fields),
            );

            return implode(', ', $columns);
        }

        $parts = [];
        foreach ($this->projection as $proj) {
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
                    $inner = $proj['column'] === '*' ? '*' : $this->quote($proj['column']);
                    $agg = $proj['function'] . '(' . ($proj['distinct'] ? 'DISTINCT ' : '') . $inner . ')';
                    $parts[] = $agg . ($proj['alias'] !== null ? ' AS ' . $this->quote($proj['alias']) : '');
                    break;
            }
        }

        return implode(', ', $parts);
    }

    private function renderFrom(RenderContext $ctx): string
    {
        if ($this->derivedFrom !== null) {
            $sub = $this->derivedFrom['builder']->renderSelect($ctx);

            return '(' . $sub . ') AS ' . $this->quote($this->derivedFrom['alias']);
        }

        $from = $this->quote($this->baseTable());
        if ($this->tableAlias !== null) {
            $from .= ' AS ' . $this->quote($this->tableAlias);
        }

        return $from;
    }

    private function renderJoins(RenderContext $ctx): string
    {
        if ($this->joins === []) {
            return '';
        }
        $sql = '';
        foreach ($this->joins as $join) {
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
            if (!$clause->isEmpty()) {
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
                            'sql' => $this->quote($cond['left']) . ' ' . $cond['operator'] . ' ' . $ctx->bind($this->assertScalar($cond['right'], false)),
                        ];
                    }
                }
                $sql .= ' ON ' . $this->joinBoolean($parts);
            }
        }

        return $sql;
    }

    /** @param list<array{boolean: string, sql: string}> $parts */
    private function joinBoolean(array $parts): string
    {
        $sql = '';
        foreach ($parts as $i => $part) {
            $sql .= ($i === 0 ? '' : ' ' . $part['boolean'] . ' ') . $part['sql'];
        }

        return $sql;
    }

    // ==================== 内部：WHERE 渲染 ====================

    /** 返回 " WHERE ..."（含前导空格）或空串。 */
    private function renderWhereClause(RenderContext $ctx): string
    {
        $clauses = [];
        foreach ($this->where as $condition) {
            $rendered = $this->renderCondition($condition, $ctx);
            if ($rendered !== null) {
                $clauses[] = $rendered;
            }
        }

        foreach ($this->orGroups as $group) {
            $rendered = $this->renderOrGroup($group, $ctx);
            if ($rendered !== null) {
                $clauses[] = $rendered;
            }
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    private function renderCondition(array $condition, RenderContext $ctx): ?string
    {
        $column = $condition['column'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        switch ($operator) {
            case 'RAW':
                /** @var Expression $expr */
                $expr = $value;

                return $ctx->mergeRaw($expr->sql, $expr->params);

            case 'EXISTS':
            case 'NOT EXISTS':
                /** @var self $sub */
                $sub = $value;
                $sub->assertNoLock('EXISTS 子查询');
                $inner = $sub->renderSelect($ctx);

                return $operator . ' (' . $inner . ')';

            case 'IS NULL':
                return $this->quote($column) . ' IS NULL';

            case 'IS NOT NULL':
                return $this->quote($column) . ' IS NOT NULL';

            case 'IN':
            case 'NOT IN':
                return $this->renderIn($column, $operator, $value, $ctx);

            case 'BETWEEN':
                $lo = $this->castQueryValue($column, $value[0]);
                $hi = $this->castQueryValue($column, $value[1]);

                return $this->quote($column) . ' BETWEEN ' . $ctx->bind($lo) . ' AND ' . $ctx->bind($hi);
        }

        // 标量子查询
        if ($value instanceof self) {
            $this->assertSameGateway($value, '标量子查询');
            $value->assertSingleColumn('标量子查询');
            $value->assertNoLock('标量子查询');
            $inner = $value->renderSelect($ctx);

            return $this->quote($column) . ' ' . $operator . ' (' . $inner . ')';
        }

        $casted = $this->castQueryValue($column, $value);

        return $this->quote($column) . ' ' . $operator . ' ' . $ctx->bind($casted);
    }

    private function renderIn(string $column, string $operator, mixed $value, RenderContext $ctx): string
    {
        if ($value instanceof self) {
            $this->assertSameGateway($value, 'IN 子查询');
            $value->assertSingleColumn('IN 子查询');
            $value->assertNoLock('IN 子查询');
            $inner = $value->renderSelect($ctx);

            return $this->quote($column) . ' ' . $operator . ' (' . $inner . ')';
        }
        if ($value === []) {
            return $operator === 'IN' ? '1 = 0' : '1 = 1';
        }
        $placeholders = [];
        foreach ($value as $v) {
            $placeholders[] = $ctx->bind($this->castQueryValue($column, $v));
        }

        return $this->quote($column) . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
    }

    /** @param array{conditions: list, groups: array} $group */
    private function renderOrGroup(array $group, RenderContext $ctx): ?string
    {
        $orParts = [];
        foreach ($group['conditions'] as $condition) {
            $rendered = $this->renderCondition($condition, $ctx);
            if ($rendered !== null) {
                $orParts[] = $rendered;
            }
        }
        foreach ($group['groups'] as $subGroup) {
            $rendered = $this->renderOrGroup($subGroup, $ctx);
            if ($rendered !== null) {
                $orParts[] = $rendered;
            }
        }

        return $orParts === [] ? null : '(' . implode(' OR ', $orParts) . ')';
    }

    // ==================== 内部：LIMIT / OFFSET ====================

    private function renderLimitOffset(?int $limit = null, int $offset = 0): string
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

    // ==================== 内部：校验与辅助 ====================

    private function baseTable(): string
    {
        return $this->gateway->meta($this->class)->table;
    }

    private function quote(string $identifier): string
    {
        return $this->gateway->quoteIdentifier($identifier);
    }

    private function driver(): string
    {
        return $this->gateway->driver();
    }

    /** 是否是可 hydrate 的基础单表查询（无投影/JOIN/GROUP/HAVING/UNION/派生表/distinct）。 */
    private function isSimpleBaseQuery(): bool
    {
        return $this->projection === []
            && $this->joins === []
            && $this->groupBy === []
            && $this->having === []
            && $this->unions === []
            && $this->derivedFrom === null
            && !$this->distinct
            && $this->tableAlias === null;
    }

    private function assertHydratable(): void
    {
        if (!$this->isSimpleBaseQuery()) {
            throw new InvalidArgumentException(
                '该查询包含投影/JOIN/GROUP/UNION/派生表，无法 hydrate 为实体，请使用 rows()/row()/value()'
            );
        }
    }

    private function assertWritable(): void
    {
        if ($this->joins !== [] || $this->groupBy !== [] || $this->having !== []
            || $this->unions !== [] || $this->derivedFrom !== null || $this->tableAlias !== null
            || $this->projection !== [] || $this->distinct || $this->orderBy !== []
            || $this->limit !== null || $this->offset > 0 || $this->lockMode !== null) {
            throw new InvalidArgumentException(
                'UPDATE/DELETE 仅支持基表 WHERE 条件，不支持投影/JOIN/GROUP/HAVING/UNION/alias/派生表/排序/分页/行锁',
            );
        }
    }

    private function assertSameGateway(self $other, string $context): void
    {
        if ($this->gateway !== $other->gateway) {
            throw new InvalidArgumentException($context . '必须使用与父查询相同的 EntityGateway 连接');
        }
    }

    private function assertUnionCompatibility(self $other): void
    {
        $this->assertSameGateway($other, 'UNION 分支');
        $left = $this->firstSelect();
        $right = $other->firstSelect();
        if ($left->projectionShape() !== $right->projectionShape()) {
            throw new InvalidArgumentException('UNION 每个分支必须返回相同数量的列，且输出列名/别名必须一致');
        }
    }

    /** 获取当前查询（不含 UNION）首个 SELECT 分支的输出形状。 */
    private function firstSelect(): self
    {
        if ($this->unions === []) {
            return $this;
        }
        $first = clone $this;
        $first->unions = [];
        $first->orderBy = $first->unionFirstOrderBy ?? [];
        $first->limit = $first->unionFirstLimit;
        $first->offset = $first->unionFirstOffset;

        return $first;
    }

    /** @return list<string> */
    private function projectionShape(): array
    {
        if ($this->projection === []) {
            return array_map(
                static fn ($field): string => strtolower($field->column),
                array_values($this->gateway->meta($this->class)->fields),
            );
        }
        $shape = [];
        foreach ($this->projection as $projection) {
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
        if (count($shape) !== count(array_unique($shape))) {
            throw new InvalidArgumentException('UNION 分支不能包含重复的输出列名/别名');
        }

        return $shape;
    }

    private function assertProjectionAliasesUnique(): void
    {
        $names = [];
        foreach ($this->projection as $projection) {
            if (!in_array($projection['kind'], ['as', 'aggregate'], true) || $projection['alias'] === null) {
                continue;
            }
            $name = strtolower($projection['alias']);
            if (isset($names[$name])) {
                throw new InvalidArgumentException('SELECT 输出别名重复: ' . $projection['alias']);
            }
            $names[$name] = true;
        }
    }

    private function assertNoLock(string $context): void
    {
        if ($this->lockMode !== null) {
            throw new InvalidArgumentException($context . '不允许携带行锁');
        }
        if ($this->derivedFrom !== null) {
            $this->derivedFrom['builder']->assertNoLock($context);
        }
        foreach ($this->unions as $union) {
            $union['builder']->assertNoLock($context);
        }
        foreach ($this->where as $condition) {
            if ($condition['value'] instanceof self) {
                $condition['value']->assertNoLock($context);
            }
        }
        foreach ($this->orGroups as $group) {
            $this->assertGroupNoLock($group, $context);
        }
    }

    private function assertGroupNoLock(array $group, string $context): void
    {
        foreach ($group['conditions'] as $condition) {
            if ($condition['value'] instanceof self) {
                $condition['value']->assertNoLock($context);
            }
        }
        foreach ($group['groups'] as $nested) {
            $this->assertGroupNoLock($nested, $context);
        }
    }

    private function assertSingleColumn(string $context): void
    {
        if ($this->projection === []) {
            throw new InvalidArgumentException($context . '必须显式 select() 选择一个列');
        }
        $columns = 0;
        foreach ($this->projection as $projection) {
            if ($projection['kind'] === 'raw') {
                throw new InvalidArgumentException($context . '使用 selectRaw() 时请确保只返回一列，或显式 select() 单列');
            }
            $columns++;
        }
        if ($columns !== 1) {
            throw new InvalidArgumentException($context . '必须恰好返回一列');
        }
    }

    private function assertIdentifier(string $identifier, string $what): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException($what . '不是合法标识符: ' . $identifier);
        }
    }

    /**
     * HAVING 表达式：只允许受控聚合表达式或标识符。
     * 聚合形如 COUNT(*)/SUM(col)/AVG(col)/MIN(col)/MAX(col)，列必须是合法标识符（可带表前缀）。
     */
    private function assertHavingExpression(string $expression): void
    {
        $trimmed = trim($expression);
        // 标识符（可带 . 前缀）
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $trimmed) === 1) {
            return;
        }
        // 受控聚合表达式
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(DISTINCT\s+)?(\*|[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\)$/i', $trimmed) === 1) {
            return;
        }
        throw new InvalidArgumentException('HAVING 表达式只允许标识符或受控聚合表达式: ' . $expression);
    }

    private function renderHavingExpression(string $expression): string
    {
        $trimmed = trim($expression);
        // 标识符：直接 quote
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $trimmed) === 1) {
            return $this->quote($trimmed);
        }
        // 聚合：拆出函数、DISTINCT、列，重新结构化
        preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(DISTINCT\s+)?(\*|[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\)$/i', $trimmed, $m);
        $function = strtoupper($m[1]);
        $distinct = isset($m[2]) && trim($m[2]) !== '' ? 'DISTINCT ' : '';
        $inner = $m[3] === '*' ? '*' : $this->quote($m[3]);

        return $function . '(' . $distinct . $inner . ')';
    }

    /** 把 WHERE/IN/BETWEEN 值按基表字段 cast；join/未知列原样标量绑定。 */
    private function castQueryValue(string $column, mixed $value): int|float|string|bool|null
    {
        if ($value instanceof self) {
            throw new InvalidArgumentException('标量值位置不允许子查询');
        }
        if (is_array($value) || is_resource($value)) {
            throw new InvalidArgumentException('条件值必须是标量，不允许 array/resource');
        }
        // 基表字段（不带表前缀且是模型列）走 castQueryValue
        if (!str_contains($column, '.') && method_exists($this->gateway, 'castQueryValue')) {
            $casted = $this->gateway->castQueryValue($this->class, $column, $value);
            if ($casted !== null || $value === null) {
                return $casted;
            }
        }

        return $this->assertScalar($value, true);
    }

    /** SET 值：必须命中基表字段。 */
    private function castValue(string $column, mixed $value): int|float|string|bool|null
    {
        if (method_exists($this->gateway, 'castQueryValue')) {
            return $this->gateway->castQueryValue($this->class, $column, $value);
        }

        return $this->assertScalar($value, true);
    }

    /** @return \Pixiaoka\Orm\Metadata\FieldMeta|null */
    private function resolveField(string $column)
    {
        $meta = $this->gateway->meta($this->class);
        foreach ($meta->fields as $field) {
            if ($field->column === $column || $field->property === $column) {
                return $field;
            }
        }

        return null;
    }

    /** 校验值为可绑定标量（int/float/string/bool/null 或可 cast 对象）。 */
    private function assertScalar(mixed $value, bool $allowObject): int|float|string|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        if ($allowObject && is_object($value)) {
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
