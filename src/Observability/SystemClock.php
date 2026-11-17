<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** @internal 单调时钟，避免系统时间调整污染 SQL 耗时。 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
