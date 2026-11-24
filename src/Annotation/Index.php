<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;
use InvalidArgumentException;

/** 模型级索引声明。用于 Schema 差异比较；不会在查询时自动建索引。 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Index
{
    /** @var list<string> */
    public array $columns;

    public string $name;

    /** @param list<string> $columns 属性名或真实列名，顺序即索引列顺序。 */
    public function __construct(array $columns, ?string $name = null, public bool $unique = false)
    {
        if ($columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException('Index columns must be a non-empty ordered array');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
                throw new InvalidArgumentException('Index columns must be valid identifiers');
            }
        }
        $this->columns = $columns;
        $this->name = $name ?? ($unique ? 'uniq_' : 'idx_') . implode('_', $columns);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $this->name) !== 1) {
            throw new InvalidArgumentException('Index name must be a valid identifier');
        }
    }
}
