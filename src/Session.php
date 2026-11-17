<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use LogicException;
use WeakMap;

/**
 * Active Record 的数据库入口。
 *
 * 核心库不绑定框架、不拥有 PDO，也不强制连接池。应用或适配器负责连接生命周期，
 * Session 只负责当前执行上下文内的连接所有权。
 */
final class Session
{
    /** @var WeakMap<Context, self>|null */
    private static ?WeakMap $contextSessions = null;

    /** @var WeakMap<Connection, Context>|null 一个连接同时只能被一个事务上下文持有。 */
    private static ?WeakMap $connectionContexts = null;

    private static ?self $mainSession = null;

    private static bool $strict = false;

    /** @var \WeakMap<Context, array{rolledBack: bool, failed: bool}>|null discard 结束时的事务结果。 */
    private static ?\WeakMap $confirmedRollbacks = null;

    /** @var \WeakMap<Context, true>|null discard 执行期间禁止观测回调重入清理。 */
    private static ?\WeakMap $discardingContexts = null;

    /** @var \WeakMap<Context, int>|null 当前上下文的嵌套观测事件深度。 */
    private static ?\WeakMap $observingContexts = null;

    public function __construct(
        public readonly EntityGateway $gateway,
    ) {
    }

    /** 为当前上下文登记独立连接对应的网关。 */
    public static function use(EntityGateway $gateway): self
    {
        self::assertObservationBoundary();
        self::clearRollbackStatus(self::currentContext());
        $gateway->assertSessionUsable();
        $context = self::currentContext();
        $session = new self($gateway);
        $previous = self::lookup($context);
        if ($previous !== null) {
            $previous->gateway->assertSessionReplaceable($context);
        }
        self::install($session);
        $gateway->observeSession('bind', self::currentContext());

        return $session;
    }

    /**
     * 在当前上下文开启可自动恢复的作用域。
     *
     * @template T
     * @param (callable(): T)|null $operation 省略时返回手动关闭的作用域
     * @return ($operation is null ? SessionScope : T)
     */
    public static function scope(EntityGateway $gateway, ?callable $operation = null): mixed
    {
        self::assertObservationBoundary();
        $context = self::currentContext();
        $previous = self::lookup($context);
        if ($previous !== null) {
            $previous->gateway->assertSessionReplaceable($context);
        }
        $scope = new SessionScope($context, static function () use ($previous, $context): void {
            try {
                self::discard();
            } finally {
                if ($previous !== null) {
                    self::install($previous);
                    $previous->gateway->observeSession('restore', $context);
                }
            }
        });
        try {
            self::use($gateway);
        } catch (\Throwable $e) {
            $scope->abandon();
            throw $e;
        }

        if ($operation === null) {
            return $scope;
        }

        try {
            return $operation();
        } finally {
            $scope->close();
        }
    }

    /**
     * 严格模式下 Fiber/协程不得隐式使用根 Session。
     * 默认关闭以保持旧应用兼容；常驻进程和并发应用建议开启。
     */
    public static function strict(bool $enabled = true): void
    {
        self::$strict = $enabled;
    }

    public static function isStrict(): bool
    {
        return self::$strict;
    }

    public static function get(): self
    {
        $context = self::currentContext();
        $session = self::lookup($context);
        if ($session !== null) {
            return $session;
        }

        if (self::$strict && (\Fiber::getCurrent() !== null || self::runtimeCoroutine() !== null)) {
            throw new LogicException('当前 Fiber/协程还没有 Pixiaoka\Orm\Session::use()；严格模式禁止回退根连接');
        }

        return self::$mainSession ?? throw new LogicException('还没有 Pixiaoka\Orm\Session::use()');
    }

    /**
     * 清理当前上下文。存在未结束事务时先回滚，防止把事务中的连接归还应用或连接池。
     * 即使连接已断开，也继续解除 Session 与网关事务状态，并保留原始异常。
     */
    public static function discard(): void
    {
        $context = self::currentContext();
        $session = self::lookup($context);
        if ($session !== null && ((self::$discardingContexts !== null && isset(self::$discardingContexts[$context]))
            || (self::$observingContexts !== null && isset(self::$observingContexts[$context])))) {
            throw new LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
        }
        if ($session === null) {
            self::clearRollbackStatus($context);
            return;
        }
        self::$discardingContexts ??= new WeakMap();
        self::$discardingContexts[$context] = true;
        $exception = null;
        try {
            $session->gateway->assertObservationBoundary();
            $session->gateway->releaseContext($context);
            if ($session->gateway->rollbackWasConfirmed($context)) {
                self::$confirmedRollbacks ??= new WeakMap();
                self::$confirmedRollbacks[$context] = ['rolledBack' => true, 'failed' => false];
            }
        } catch (\Throwable $e) {
            $exception = $e;
            self::$confirmedRollbacks ??= new WeakMap();
            self::$confirmedRollbacks[$context] = ['rolledBack' => false, 'failed' => true];
            throw $e;
        } finally {
            if (self::$contextSessions !== null) {
                unset(self::$contextSessions[$context]);
            }
            if ($context === Context::root()) {
                self::$mainSession = null;
            }
            $session->gateway->observeSession('discard', $context, $exception);
            unset(self::$discardingContexts[$context]);
        }
    }

    /** @internal 仅由事务边界确认 discard 是否已经完成数据库回滚。 */
    public static function rollbackConfirmed(Context $context): bool
    {
        return (self::$confirmedRollbacks[$context]['rolledBack'] ?? false) === true;
    }

    /** @internal 已尝试 discard 但回滚失败，外层事务不得重复回滚或伪造业务异常。 */
    public static function rollbackAttempted(Context $context): bool
    {
        return self::$confirmedRollbacks !== null && isset(self::$confirmedRollbacks[$context]);
    }

    /** @internal 新物理事务不得继承上次 discard 的结果。 */
    public static function clearRollbackStatus(Context $context): void
    {
        if (self::$confirmedRollbacks !== null) {
            unset(self::$confirmedRollbacks[$context]);
        }
    }

    /** @internal 独立连接可执行观测 SQL，但不能改变当前上下文的 Session 生命周期。 */
    public static function observe(callable $dispatch): void
    {
        $context = self::currentContext();
        self::$observingContexts ??= new WeakMap();
        $depth = (self::$observingContexts[$context] ?? 0) + 1;
        self::$observingContexts[$context] = $depth;
        try {
            $dispatch();
        } finally {
            if ($depth === 1) {
                unset(self::$observingContexts[$context]);
            } else {
                self::$observingContexts[$context] = $depth - 1;
            }
        }
    }

    private static function assertObservationBoundary(): void
    {
        $context = self::currentContext();
        if (self::$observingContexts !== null && isset(self::$observingContexts[$context])) {
            throw new LogicException('观测监听器不得切换或清理当前上下文的 Session');
        }
    }

    /** @internal 网关事务状态按对象身份隔离，不使用可复用的 Fiber/协程数字 ID。 */
    public static function context(): Context
    {
        return self::currentContext();
    }

    /** @internal SessionScope 跨上下文关闭保护。 */
    public static function currentContext(): Context
    {
        $runtime = self::runtimeCoroutine();
        if ($runtime !== null) {
            return Context::coroutine($runtime);
        }
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? Context::root() : Context::fiber($fiber);
    }

    /** @internal 同一网关被用于另一个并发事务时立即拒绝，不混淆连接事务所有权。 */
    public static function claimConnection(EntityGateway $gateway, Context $context): void
    {
        self::$connectionContexts ??= new WeakMap();
        $connection = $gateway->connection();
        $owner = self::$connectionContexts[$connection] ?? null;
        if ($owner !== null && $owner !== $context) {
            throw new LogicException('同一连接已被另一个 Fiber/协程事务持有，请为并发事务提供独立连接');
        }
        self::$connectionContexts[$connection] = $context;
    }

    /** @internal */
    public static function releaseConnection(EntityGateway $gateway, Context $context): void
    {
        if (self::$connectionContexts === null) {
            return;
        }
        $connection = $gateway->connection();
        if ((self::$connectionContexts[$connection] ?? null) === $context) {
            unset(self::$connectionContexts[$connection]);
        }
    }

    private static function install(self $session): void
    {
        $context = self::currentContext();
        self::$contextSessions ??= new WeakMap();
        self::$contextSessions[$context] = $session;
        if ($context === Context::root()) {
            self::$mainSession = $session;
        }
    }

    private static function lookup(Context $context): ?self
    {
        return self::$contextSessions === null ? null : (self::$contextSessions[$context] ?? null);
    }

    /** Swoole 使用其 Context 对象，Swow 使用 Coroutine 对象作为弱引用身份。 */
    private static function runtimeCoroutine(): ?object
    {
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            if (is_object($context)) {
                return $context;
            }
        }
        if (class_exists('Swow\Coroutine') && \Swow\Coroutine::getCurrent() !== null) {
            return \Swow\Coroutine::getCurrent();
        }

        return null;
    }
}
