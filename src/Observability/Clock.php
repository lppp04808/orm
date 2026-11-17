<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

interface Clock
{
    public function now(): float;
}
