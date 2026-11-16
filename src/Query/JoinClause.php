<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;

/**
 * JOIN 子句。on() 为列=列比较（ON 条件）；where() 为 ON 值参数化（列 op 值）。
 * 表名/别名由 Builder 独立 quote，运算符走白名单，ON 值参数化。
 */
final class JoinClause
{
    /** @var list<array{kind: 'on'|'where', boolean: 'AND'|'OR', left: string, operator: string, right: mixed}> */
    private array $conditions = [];

    /**
     * 列与列的比较（ON leftColumn op rightColumn）。
     *
     * @return $this
     */
    public function on(string $leftColumn, string $operator, string $rightColumn): self
    {
        $this->conditions[] = [
            'kind' => 'on',
            'boolean' => 'AND',
            'left' => $leftColumn,
            'operator' => Operator::assertComparison($operator),
            'right' => $rightColumn,
        ];

        return $this;
    }

    /**
     * OR 列与列比较。
     *
     * @return $this
     */
    public function orOn(string $leftColumn, string $operator, string $rightColumn): self
    {
        $this->conditions[] = [
            'kind' => 'on',
            'boolean' => 'OR',
            'left' => $leftColumn,
            'operator' => Operator::assertComparison($operator),
            'right' => $rightColumn,
        ];

        return $this;
    }

    /**
     * ON 值参数化（ON column op :value）。
     *
     * @return $this
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $this->conditions[] = [
            'kind' => 'where',
            'boolean' => 'AND',
            'left' => $column,
            'operator' => Operator::assertComparison($operator),
            'right' => $value,
        ];

        return $this;
    }

    /** @return list<array{kind: 'on'|'where', boolean: 'AND'|'OR', left: string, operator: string, right: mixed}> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }
}
