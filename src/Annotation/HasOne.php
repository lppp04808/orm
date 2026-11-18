<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 当前模型键对应目标表外键；目标同一外键有多行时 first() 取排序后的第一行。 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class HasOne extends Relation
{
}
