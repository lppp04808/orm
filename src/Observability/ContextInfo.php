<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** 执行上下文的进程内身份；不是跨请求或分布式追踪 ID。 */
final readonly class ContextInfo
{
    /**
     * @param 'root'|'fiber'|'coroutine' $type
     * @param int $id 仅用于当前进程内关联事件，Fiber/协程销毁后可能复用。
     */
    public function __construct(
        public string $type,
        public int $id,
    ) {
    }
}
