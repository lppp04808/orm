<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

interface EventListener
{
    public function onEvent(Event $event): void;
}
