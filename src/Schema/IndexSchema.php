<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use InvalidArgumentException;

/** 一个有序的二级索引，不包含已由 ColumnSchema 表达的主键。 */
final readonly class IndexSchema
{
    /** @var list<string> */
    public array $columns;

    /** @param list<string> $columns */
    public function __construct(public string $name, array $columns, public bool $unique = false)
    {
        if ($columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException("索引 $name 必须包含有序字段");
        }
        foreach ([$name, ...$columns] as $identifier) {
            if (!is_string($identifier) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('索引名称与字段必须是合法标识符');
            }
        }
        $this->columns = $columns;
    }
}
