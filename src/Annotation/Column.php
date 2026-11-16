<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/**
 * 持久化字段。没贴这个注解的属性不是列。
 * 列名默认等于属性名。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
