<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use InvalidArgumentException;

/** 一张表的列及有序主键定义。 */
final readonly class TableSchema
{
    /** @var list<ColumnSchema> */
    public array $columns;

    /** @var list<ColumnSchema> */
    public array $primaryKeys;

    /**
     * @param list<ColumnSchema> $columns
     */
    public function __construct(
        public string $name,
        array $columns,
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException("表 $name 没有可生成的字段");
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
