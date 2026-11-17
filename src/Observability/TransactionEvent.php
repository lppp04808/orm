<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** 一次真实物理事务的开始、提交或回滚；嵌套调用不重复发出。 */
final readonly class TransactionEvent implements Event
{
    /**
     * @param 'begin'|'commit'|'rollback' $phase
     * @param ContextInfo $context
     * @param float $durationMs
     * @param bool $successful
     * @param class-string<\Throwable>|null $exceptionClass
     */
    public function __construct(
        public string $phase,
        public ContextInfo $context,
        public float $durationMs,
        public bool $successful,
        public ?string $exceptionClass = null,
    ) {
    }
}
