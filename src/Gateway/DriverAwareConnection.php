<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

/**
 * 可选的数据库方言能力。旧 Connection 装饰器无需实现；
 * UPSERT 和行锁等方言相关操作需要 PdoConnection 或显式实现本接口。
 */
interface DriverAwareConnection
{
    /** @return 'mysql'|'pgsql'|'sqlite'|string PDO 驱动名 */
    public function driver(): string;
}
