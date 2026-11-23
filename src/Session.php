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
    /** @var WeakMap<Context, array<string, self>>|null */
    private static ?WeakMap $contextSessions = null;

    /** @var WeakMap<Connection, Context>|null 一个连接同时只能被一个事务上下文持有。 */
    private static ?WeakMap $connectionContexts = null;

    /** @var WeakMap<EntityGateway, Context>|null 活跃上下文持有网关的全部读写生命周期。 */
    private static ?WeakMap $gatewayContexts = null;

    /** @var WeakMap<Context, WeakMap<EntityGateway, int>>|null 同上下文多次绑定同一网关的引用计数。 */
    private static ?WeakMap $gatewayReferences = null;

    /** @var array<string, self> */
    private static array $mainSessions = [];

    private static ?bool $hasSwoole = null;

    private static ?bool $hasSwow = null;

    private static bool $strict = false;

    /** @var \WeakMap<Context, array<string, array{rolledBack: bool, failed: bool}>>|null 各命名会话的独立回滚结果。 */
    private static ?\WeakMap $rollbackStatuses = null;

    /** @var \WeakMap<Context, true>|null discard 执行期间禁止观测回调重入清理。 */
    private static ?\WeakMap $discardingContexts = null;

    /** @var \WeakMap<Context, int>|null 当前上下文的嵌套观测事件深度。 */
    private static ?\WeakMap $observingContexts = null;

    public function __construct(
        public readonly EntityGateway $gateway,
    ) {
    }

    /** 为当前上下文登记指定数据源的独立连接网关。 */
    public static function use(EntityGateway $gateway, string $name = 'default'): self
    {
        self::assertSessionName($name);
        self::assertObservationBoundary();
        $context = self::currentContext();
        self::clearRollbackStatus($context, $name);
        $gateway->assertSessionUsable();
        $previous = self::lookup($context, $name);
        if ($previous !== null) {
            $previous->gateway->assertSessionReplaceable($context);
        }
        if ($previous === null || $previous->gateway !== $gateway) {
            self::assertGatewayAvailable($gateway, $context);
        }
        if ($previous !== null && $previous->gateway !== $gateway) {
            self::releaseGateway($previous->gateway, $context);
        }
        $session = new self($gateway);
        self::install($session, $name);
        $gateway->observeSession('bind', $context);

        return $session;
    }

    /**
     * 在当前上下文开启可自动恢复的作用域。
     *
     * @template T
     * @param (callable(): T)|null $operation 省略时返回手动关闭的作用域
     * @return ($operation is null ? SessionScope : T)
     */
    public static function scope(
        EntityGateway $gateway,
        ?callable $operation = null,
        string $name = 'default',
    ): mixed {
        self::assertSessionName($name);
        self::assertObservationBoundary();
        $context = self::currentContext();
        $previous = self::lookup($context, $name);
        if ($previous !== null) {
            $previous->gateway->assertSessionReplaceable($context);
            // use() 会释放当前绑定；作用域先保留一份外层引用，恢复时直接接续。
            self::assertGatewayAvailable($previous->gateway, $context);
        }
        $scope = new SessionScope($context, static function () use ($previous, $context, $name): void {
            $exception = null;
            try {
                self::discard($name);
            } catch (\Throwable $e) {
                $exception = $e;
            } finally {
                if ($previous !== null) {
                    // install() 不登记引用，这里接续 scope 创建时保留的外层引用。
                    self::install($previous, $name);
                    $previous->gateway->observeSession('restore', $context);
                }
            }
            if ($exception !== null) {
                throw $exception;
            }
        });
        try {
            self::use($gateway, $name);
        } catch (\Throwable $e) {
            $scope->abandon();
            if ($previous !== null) {
                self::releaseGateway($previous->gateway, $context);
            }
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

    public static function get(string $name = 'default'): self
    {
        self::assertSessionName($name);
        $context = self::currentContext();
        $session = self::lookup($context, $name);
        if ($session !== null) {
            return $session;
        }

        if (self::$strict && (\Fiber::getCurrent() !== null || self::runtimeCoroutine() !== null)) {
            throw new LogicException('当前 Fiber/协程还没有 Pixiaoka\Orm\Session::use()；严格模式禁止回退根连接：' . $name);
        }

        return self::$mainSessions[$name]
            ?? throw new LogicException($name === 'default'
                ? '还没有 Pixiaoka\Orm\Session::use()'
                : "还没有 Pixiaoka\\Orm\\Session::use(..., '$name')");
    }

    /**
     * 清理当前上下文。存在未结束事务时先回滚，防止把事务中的连接归还应用或连接池。
     * 即使连接已断开，也继续解除 Session 与网关事务状态，并保留原始异常。
     */
    public static function discard(?string $name = null): void
    {
        if ($name !== null) {
            self::assertSessionName($name);
        }
        $context = self::currentContext();
        if ((self::$discardingContexts !== null && isset(self::$discardingContexts[$context]))
            || (self::$observingContexts !== null && isset(self::$observingContexts[$context]))) {
            if (self::sessions($context, $name) !== []) {
                throw new LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
            }
        }
        $sessions = self::sessions($context, $name);
        if ($sessions === []) {
            self::clearRollbackStatus($context, $name);
            return;
        }
        self::$discardingContexts ??= new WeakMap();
        self::$discardingContexts[$context] = true;
        $exception = null;
        try {
            foreach ($sessions as $sessionName => $session) {
                self::discardSession($session, $sessionName, $context, $exception);
            }
        } finally {
            unset(self::$discardingContexts[$context]);
            // 保留回滚确认供尚未退出的事务栈读取；下一次 use/transaction 再清理。
            if ($exception !== null) {
                throw $exception;
            }
        }
    }

    private static function discardSession(
        self $session,
        string $name,
        Context $context,
        ?\Throwable &$firstException,
    ): void {
        $exception = null;
        try {
            $session->gateway->assertObservationBoundary();
            $session->gateway->releaseContext($context);
            if ($session->gateway->rollbackWasConfirmed($context)) {
                self::recordRollbackStatus($context, $name, true, false);
            }
        } catch (\Throwable $e) {
            $exception = $e;
            $firstException ??= $e;
            self::recordRollbackStatus($context, $name, false, true);
        } finally {
            if (self::$contextSessions !== null && isset(self::$contextSessions[$context])) {
                $sessions = self::$contextSessions[$context];
                unset($sessions[$name]);
                if ($sessions === []) {
                    unset(self::$contextSessions[$context]);
                } else {
                    self::$contextSessions[$context] = $sessions;
                }
            }
            self::releaseGateway($session->gateway, $context);
            if ($context === Context::root()) {
                unset(self::$mainSessions[$name]);
            }
            $session->gateway->observeSession('discard', $context, $exception);
        }
    }

    /** @internal 仅由事务边界确认 discard 是否已经完成数据库回滚。 */
    public static function rollbackConfirmed(Context $context, string $name = 'default'): bool
    {
        return (self::$rollbackStatuses[$context][$name]['rolledBack'] ?? false) === true;
    }

    /** @internal 已尝试 discard 但回滚失败，外层事务不得重复回滚或伪造业务异常。 */
    public static function rollbackAttempted(Context $context, string $name = 'default'): bool
    {
        return isset(self::$rollbackStatuses[$context][$name]);
    }

    /** @internal 当前网关的命名会话在事务开始时确定，并传递到最终事务边界。 */
    public static function transactionName(EntityGateway $gateway, Context $context): string
    {
        if (self::$contextSessions !== null && isset(self::$contextSessions[$context])) {
            foreach (self::$contextSessions[$context] as $name => $session) {
                if ($session->gateway === $gateway) {
                    return $name;
                }
            }
        }
        if ($context === Context::root()) {
            foreach (self::$mainSessions as $name => $session) {
                if ($session->gateway === $gateway) {
                    return $name;
                }
            }
        }

        // 兼容历史上直接通过 Gateway 开事务的调用；没有命名绑定时仍是 default。
        return 'default';
    }

    /** @internal 新物理事务不得继承上次 discard 的结果。 */
    public static function clearRollbackStatus(Context $context, ?string $name = null): void
    {
        if (self::$rollbackStatuses === null) {
            return;
        }
        if ($name === null) {
            unset(self::$rollbackStatuses[$context]);

            return;
        }
        $statuses = self::$rollbackStatuses[$context] ?? [];
        unset($statuses[$name]);
        if ($statuses === []) {
            unset(self::$rollbackStatuses[$context]);
        } else {
            self::$rollbackStatuses[$context] = $statuses;
        }
    }

    private static function recordRollbackStatus(
        Context $context,
        string $name,
        bool $rolledBack,
        bool $failed,
    ): void {
        self::$rollbackStatuses ??= new WeakMap();
        $statuses = self::$rollbackStatuses[$context] ?? [];
        $statuses[$name] = ['rolledBack' => $rolledBack, 'failed' => $failed];
        self::$rollbackStatuses[$context] = $statuses;
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

    private static function install(self $session, string $name): void
    {
        $context = self::currentContext();
        self::$contextSessions ??= new WeakMap();
        $sessions = self::$contextSessions[$context] ?? [];
        $sessions[$name] = $session;
        self::$contextSessions[$context] = $sessions;
        if ($context === Context::root()) {
            self::$mainSessions[$name] = $session;
        }
    }

    private static function lookup(Context $context, string $name): ?self
    {
        return self::$contextSessions === null ? null : (self::$contextSessions[$context][$name] ?? null);
    }

    /** @return array<string, self> */
    private static function sessions(Context $context, ?string $name): array
    {
        $sessions = self::$contextSessions[$context] ?? [];

        return $name === null ? $sessions : (isset($sessions[$name]) ? [$name => $sessions[$name]] : []);
    }

    private static function assertSessionName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Session 名称不能为空字符串');
        }
    }

    private static function assertGatewayAvailable(EntityGateway $gateway, Context $context): void
    {
        $owner = self::$gatewayContexts[$gateway] ?? null;
        if ($owner !== null && $owner !== $context) {
            throw new LogicException('同一网关已被另一个活跃 Fiber/协程持有，请 discard 原上下文会话或使用独立连接');
        }
        self::$gatewayContexts ??= new WeakMap();
        self::$gatewayContexts[$gateway] = $context;
        self::$gatewayReferences ??= new WeakMap();
        $references = self::$gatewayReferences[$context] ?? null;
        if (!$references instanceof WeakMap) {
            /** @var WeakMap<EntityGateway, int> $references */
            $references = new WeakMap();
        }
        $references[$gateway] = ($references[$gateway] ?? 0) + 1;
        self::$gatewayReferences[$context] = $references;
    }

    private static function releaseGateway(EntityGateway $gateway, Context $context): void
    {
        $references = self::$gatewayReferences[$context] ?? null;
        if ($references !== null && isset($references[$gateway])) {
            $count = $references[$gateway] - 1;
            if ($count === 0) {
                unset($references[$gateway]);
            } else {
                $references[$gateway] = $count;
            }
        }
        if (($references === null || !isset($references[$gateway]))
            && (self::$gatewayContexts[$gateway] ?? null) === $context) {
            unset(self::$gatewayContexts[$gateway]);
        }
    }

    /** Swoole 使用其 Context 对象，Swow 使用 Coroutine 对象作为弱引用身份。 */
    private static function runtimeCoroutine(): ?object
    {
        // 运行时不会在一次 PHP 进程内安装或卸载扩展，能力检测结果只需计算一次。
        self::$hasSwoole ??= class_exists('Swoole\\Coroutine');
        self::$hasSwow ??= class_exists('Swow\\Coroutine');
        if (self::$hasSwoole && \Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            if (is_object($context)) {
                return $context;
            }
        }
        if (self::$hasSwow) {
            /** @var object|null $coroutine */
            $coroutine = call_user_func(['Swow\\Coroutine', 'getCurrent']);
            if (is_object($coroutine)) {
                return $coroutine;
            }
        }

        return null;
    }
}
