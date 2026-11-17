<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Observability;

/** @internal 监听器彼此隔离，也不允许其异常改变数据库结果。 */
final class EventDispatcher
{
    /** @var array<int, EventListener|\Closure(Event): void> */
    private array $listeners = [];

    private int $nextId = 0;

    private bool $dispatching = false;

    public function hasListeners(): bool
    {
        return $this->listeners !== [];
    }

    /**
     * @param EventListener|callable(Event): void $listener
     * @return \Closure(): void 幂等取消订阅
     */
    public function subscribe(EventListener|callable $listener): \Closure
    {
        $id = $this->nextId++;
        $this->listeners[$id] = $listener instanceof EventListener
            ? $listener
            : $listener(...);

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    public function dispatch(Event $event): void
    {
        if ($this->listeners === [] || $this->dispatching) {
            return;
        }
        $this->dispatching = true;
        try {
            foreach ($this->listeners as $listener) {
                try {
                    $listener instanceof EventListener
                        ? $listener->onEvent($event)
                        : $listener($event);
                } catch (\Throwable) {
                    // 日志、指标、链路系统故障不能覆盖 SQL 或事务结果。
                }
            }
        } finally {
            $this->dispatching = false;
        }
    }
}
