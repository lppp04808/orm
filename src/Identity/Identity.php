<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Identity;

/**
 * 一组主键值。列顺序与模型主键顺序一致。
 * 0、""、"0"、false 都是合法值。null 不是。
 *
 * @phpstan-type KeyValue int|string|float|bool
 */
final readonly class Identity
{
    /**
     * @param array<string, int|string|float|bool> $parts 列名 => 已转成 PHP 的键值
     */
    public function __construct(
        public array $parts,
    ) {
    }

    public function complete(int $expected): bool
    {
        return count($this->parts) === $expected;
    }
}
