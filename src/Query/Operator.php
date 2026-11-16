<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;

/**
 * 比较/空值运算符白名单。所有运算符经归一化后必须命中白名单，绝不拼接用户输入的运算符。
 */
final class Operator
{
    /** @var list<string> */
    private const array COMPARISON = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
    ];

    /** @var list<string> */
    private const array SPECIAL = ['BETWEEN', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

    /** 归一化（大写 + 去首尾空格）。 */
    public static function normalize(string $operator): string
    {
        return strtoupper(trim($operator));
    }

    /** 是否合法比较运算符（不含 IN/BETWEEN/IS NULL 等特殊形态）。 */
    public static function isComparison(string $operator): bool
    {
        return in_array(self::normalize($operator), self::COMPARISON, true);
    }

    /** 是否是全部允许的条件运算符。 */
    public static function isAllowed(string $operator): bool
    {
        $op = self::normalize($operator);

        return in_array($op, self::COMPARISON, true) || in_array($op, self::SPECIAL, true);
    }

    /** 校验并返回归一化运算符，非法抛 InvalidArgumentException。 */
    public static function assertAllowed(string $operator): string
    {
        $op = self::normalize($operator);
        if (!self::isAllowed($op)) {
            throw new InvalidArgumentException('非法运算符: ' . $operator);
        }

        return $op;
    }

    /** 校验 ON 子句的比较运算符（不允许 IN/BETWEEN/IS NULL 形态，只允许比较）。 */
    public static function assertComparison(string $operator): string
    {
        $op = self::normalize($operator);
        if (!self::isComparison($op)) {
            throw new InvalidArgumentException('ON 子句只允许比较运算符，收到: ' . $operator);
        }

        return $op;
    }
}
