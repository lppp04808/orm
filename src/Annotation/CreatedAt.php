<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 标记创建时间列；未赋值时自动写入当前时间。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class CreatedAt
{
}
