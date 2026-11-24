<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cast;

/**
 * 8.3 上的十进数。只接受整数字符串或小数文本，内部走 bcmath。
 */
final readonly class BcDecimal implements Decimal
{
    public string $value;

    public function __construct(string|int $value)
    {
        $text = (string) $value;
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new \InvalidArgumentException('Invalid decimal number: ' . $text);
        }
        $this->value = $text;
    }

    public static function fromString(string|int $value): static
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
