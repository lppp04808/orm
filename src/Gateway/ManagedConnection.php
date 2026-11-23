<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

/**
 * 可选的连接生命周期能力。
 *
 * 实现方必须让 beginTransaction()/commit()/rollback() 支持 SAVEPOINT 嵌套，
 * 并提供不随嵌套层级退化的整链强制回滚。旧 Connection 装饰器无需实现。
 */
interface ManagedConnection extends Connection
{
    /** 执行轻量探测；断线或探测失败时返回 false，不向连接池泄漏驱动异常。 */
    public function ping(): bool;

    /** 回滚整条物理事务链；成功后必须将嵌套深度重置为零。 */
    public function forceRollback(): bool;
}
