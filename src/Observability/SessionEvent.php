<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** Session 安装、清理以及作用域恢复的完成事件。 */
final readonly class SessionEvent implements Event
{
    /**
     * @param 'bind'|'discard'|'restore' $action
     * @param ContextInfo $context
     * @param bool $successful
     * @param class-string<\Throwable>|null $exceptionClass
     */
    public function __construct(
        public string $action,
        public ContextInfo $context,
        public bool $successful,
        public ?string $exceptionClass = null,
    ) {
    }
}
