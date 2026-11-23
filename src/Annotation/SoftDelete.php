<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 将此可空时间列作为软删除标记；NULL 表示未删除。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class SoftDelete
{
}
