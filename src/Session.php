<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use Pixiaoka\Orm\Gateway\EntityGateway;
use WeakMap;

/**
 * Active Record 的数据库入口。
 * 多进程模式和协程模式通过 context() 取得当前 Session。
 */
final class Session
{
    /** P1-1：Fiber => Session。Fiber 回收后条目自动释放，不再泄漏。 */
    private static ?WeakMap $fiberSessions = null;

    /** 非协程兜底（同步 / 多进程 worker 模式下共享的根 Session）。 */
    private static ?self $mainSession = null;

    /** @var array<int, self> Swoole/Swow 协程 id => Session */
    private static array $coroutineSessions = [];

    public function __construct(
        public readonly EntityGateway $gateway,
    ) {
    }

    /**
     * 登记当前请求的网关。多进程里在 onWorkerStart / onMessage 调用；
     * 协程里每个上下文调一次。
     */
    public static function use(EntityGateway $gateway): self
    {
        // Swoole/Swow 协程：协程 id 做键存数组（协程退出时由 discard 或下次复用覆盖）
        if (self::inCoroutine()) {
            $self = new self($gateway);
            self::$coroutineSessions[self::coroutineKey()] = $self;

            return $self;
        }

        // Fiber：WeakMap 键随 Fiber 回收自动释放，不泄漏
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            self::$fiberSessions ??= new WeakMap();
            $self = new self($gateway);
            self::$fiberSessions[$fiber] = $self;

            return $self;
        }

        // 非协程兜底
        return self::$mainSession = new self($gateway);
    }

    /** 取当前请求的 Session。协程安全：按上下文隔离。 */
    public static function get(): self
    {
        if (self::inCoroutine()) {
            return self::$coroutineSessions[self::coroutineKey()]
                ?? throw new \LogicException('还没有 Pixiaoka\Orm\Session::use()');
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && self::$fiberSessions !== null) {
            $session = self::$fiberSessions[$fiber] ?? null;
            if ($session !== null) {
                return $session;
            }
        }

        return self::$mainSession ?? throw new \LogicException('还没有 Pixiaoka\Orm\Session::use()');
    }

    /** 逃离协程时清理上下文。 */
    public static function discard(): void
    {
        if (self::inCoroutine()) {
            unset(self::$coroutineSessions[self::coroutineKey()]);

            return;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && self::$fiberSessions !== null) {
            unset(self::$fiberSessions[$fiber]);

            return;
        }

        self::$mainSession = null;
    }

    /**
     * P0-4：暴露给 EntityGateway 做事务状态隔离的上下文 id。
     * Swoole/Swow 协程返回协程 id；Fiber 用 spl_object_id；非协程返回 0。
     */
    public static function contextId(): int
    {
        return self::contextKey();
    }

    private static function inCoroutine(): bool
    {
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return true;
        }

        return class_exists('Swow\Coroutine') && \Swow\Coroutine::getCurrent() !== null;
    }

    private static function coroutineKey(): int
    {
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getCid();
        }
        if (class_exists('Swow\Coroutine') && \Swow\Coroutine::getCurrent() !== null) {
            return \Swow\Coroutine::getCurrent()->getId();
        }

        return 0;
    }

    private static function contextKey(): int
    {
        // 协程模式：Swoole/Swow 返回协程 id
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getCid();
        }
        if (class_exists('Swow\Coroutine') && \Swow\Coroutine::getCurrent() !== null) {
            return \Swow\Coroutine::getCurrent()->getId();
        }
        // webman + revolt: Fiber 模式下用 spl_object_id
        if (class_exists('Fiber') && \Fiber::getCurrent() !== null) {
            return spl_object_id(\Fiber::getCurrent());
        }

        return 0;
    }
}
