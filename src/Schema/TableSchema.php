<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use InvalidArgumentException;

/** 一张表的列、有序主键及二级索引定义。 */
final readonly class TableSchema
{
    /** @var list<ColumnSchema> */
    public array $columns;

    /** @var list<ColumnSchema> */
    public array $primaryKeys;

    /**
     * @param list<ColumnSchema> $columns
     * @param list<IndexSchema> $indexes
     */
    public function __construct(
        public string $name,
        array $columns,
        public array $indexes = [],
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException("Table $name has no fields to generate");
        }

        $this->columns = $columns;
        $primaryKeys = array_values(array_filter(
            $columns,
            static fn (ColumnSchema $column): bool => $column->primary(),
        ));
        usort($primaryKeys, static fn (ColumnSchema $left, ColumnSchema $right): int =>
            $left->primaryOrder <=> $right->primaryOrder);
        $this->primaryKeys = $primaryKeys;
    }

    public function hasGeneratedKey(): bool
    {
        return count($this->primaryKeys) === 1 && $this->primaryKeys[0]->generated;
    }
}
