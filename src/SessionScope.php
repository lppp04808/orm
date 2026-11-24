<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use LogicException;

/**
 * Session 的自动清理作用域。
 *
 * 退出作用域时归还当前上下文的连接所有权，并恢复进入前的 Session。
 * 不关闭 PDO：连接由应用或可选连接池创建、回收与销毁。
 */
final class SessionScope
{
    private bool $active = true;

    /** @var callable(): void */
    private readonly mixed $restore;

    /** @internal 使用 Session::scope() 创建。 */
    public function __construct(
        private readonly Context $owner,
        callable $restore,
    ) {
        $this->restore = $restore;
    }

    public function close(): void
    {
        if (!$this->active) {
            return;
        }
        if (Session::currentContext() !== $this->owner) {
            throw new LogicException('SessionScope must be closed in the Fiber/coroutine context where it was created');
        }

        $this->active = false;
        ($this->restore)();
    }

    /** @internal 创建作用域失败时尚无可清理的新 Session，禁止析构回滚旧事务。 */
    public function abandon(): void
    {
        if (Session::currentContext() !== $this->owner) {
            throw new LogicException('SessionScope must be closed in the Fiber/coroutine context where it was created');
        }
        $this->active = false;
    }

    public function __destruct()
    {
        if ($this->active) {
            $this->close();
        }
    }
}
