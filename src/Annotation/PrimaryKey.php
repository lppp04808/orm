<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/**
 * 主键列。一张表的全部主键列组成一组身份。
 * generated=true 表示数据库生成（代理键），整组只允许一个，且不能和自然键混用。
 * order 决定身份顺序；不写则按属性声明顺序。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class PrimaryKey
{
    public function __construct(
        public bool $generated = false,
        public int $order = 0,
    ) {
    }
}
