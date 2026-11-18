<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/** 当前模型外键对应目标模型键；可空外键为 NULL 时不执行 SQL。 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class BelongsTo extends Relation
{
}
