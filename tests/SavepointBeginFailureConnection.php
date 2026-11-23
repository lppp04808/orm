<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use Pixiaoka\Orm\Gateway\ManagedConnection;
use Pixiaoka\Orm\Gateway\PdoConnection;

/** @internal 测试用：只允许最外层事务成功，模拟 SAVEPOINT 执行失败。 */
final class SavepointBeginFailureConnection implements ManagedConnection
{
    /** @var list<string> */
    public array $calls = [];

    private readonly PdoConnection $inner;

    public function __construct()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->inner = new PdoConnection($pdo);
    }

    public function execute(string $sql, array $params): int { return $this->inner->execute($sql, $params); }
    public function queryOne(string $sql, array $params): ?array { return $this->inner->queryOne($sql, $params); }
    public function queryAll(string $sql, array $params): array { return $this->inner->queryAll($sql, $params); }
    public function lastInsertId(): string { return $this->inner->lastInsertId(); }
    public function quoteIdentifier(string $identifier): string { return $this->inner->quoteIdentifier($identifier); }
    public function supportsReturning(): bool { return $this->inner->supportsReturning(); }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'begin';
        if (count($this->calls) > 1) {
            throw new \PDOException('simulated SAVEPOINT failure');
        }

        return $this->inner->beginTransaction();
    }

    public function commit(): bool { $this->calls[] = 'commit'; return $this->inner->commit(); }
    public function rollback(): bool { $this->calls[] = 'rollback'; return $this->inner->rollback(); }
    public function ping(): bool { return $this->inner->ping(); }
    public function forceRollback(): bool { $this->calls[] = 'forceRollback'; return $this->inner->forceRollback(); }
}
