<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cast;

/**
 * 十进数在对象上的形态。禁止用 float 中转。
 * PHP 8.4 的 BcMath\Number 可以直接实现它；8.3 用 BcDecimal。
 */
interface Decimal extends \Stringable
{
    /** 从十进文本构造。解析失败抛异常。 */
    public static function fromString(string|int $value): static;
}
