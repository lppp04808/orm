<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** 一次数据库查询或写入的完成事件；绝不携带 SQL 参数值。 */
final readonly class SqlEvent implements Event
{
    /**
     * @param class-string|null $model 原生 SQL 没有模型时为 null。
     * @param list<string> $parameterNames 仅参数名，不包含参数值。
     * @param class-string<\Throwable>|null $exceptionClass
     */
    public function __construct(
        public string $sql,
        public string $operation,
        public ?string $model,
        public ContextInfo $context,
        public float $durationMs,
        public bool $successful,
        public array $parameterNames,
        public ?int $affectedRows = null,
        public ?int $resultRows = null,
        public ?string $exceptionClass = null,
    ) {
    }
}
