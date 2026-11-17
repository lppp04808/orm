<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 显式启用实体更新的乐观锁；版本列必须是非空 int。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Version
{
}
