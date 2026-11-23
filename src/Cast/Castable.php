<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cast;

/**
 * 自定义持久化值对象。实现类必须是不可变或可自行保证一致性的具体类型。
 * fromDatabase() 的失败由 Caster 统一包装为 CastException。
 */
interface Castable
{
    public function toDatabase(): int|float|string|null;

    public static function fromDatabase(int|float|string $value): static;
}
