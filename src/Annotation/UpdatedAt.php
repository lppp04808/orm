<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 标记更新时间列；未显式修改时随写入刷新为当前时间。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class UpdatedAt
{
}
