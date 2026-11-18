<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 当前模型键对应目标表外键，返回目标实体列表。 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class HasMany extends Relation
{
}
