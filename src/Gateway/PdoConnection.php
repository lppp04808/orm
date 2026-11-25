<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use PDO;
use PDOStatement;

final class PdoConnection implements ManagedConnection, DriverAwareConnection
{
    private readonly string $driver;

    /** 当前 PDO 物理连接上的事务深度；SAVEPOINT 名称只由这个受控整数生成。 */
    private int $transactionDepth = 0;

    /** @var array<string, PDOStatement> 原始 SQL 到服务端预备语句的 LRU 映射。 */
    private array $statementCache = [];

    private readonly int $statementCacheSize;

    /**
     * @param PDO $pdo
     * @param \Closure|null $insertId 代替 lastInsertId，测试用
     */
    public function __construct(
        private readonly PDO       $pdo,
        private readonly ?\Closure $insertId = null,
        int $statementCacheSize = 64,
    ) {
        if ($statementCacheSize < 0) {
            throw new \InvalidArgumentException('Statement cache size must not be negative');
        }
        $this->statementCacheSize = $statementCacheSize;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $params, bool $cacheable = true): int
    {
        $statement = $this->prepare($sql, $params, $cacheable);
        try {
            return $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }
    }

    public function queryOne(string $sql, array $params): ?array
    {
        $statement = $this->prepare($sql, $params);
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } finally {
            $statement->closeCursor();
        }
    }

    public function queryAll(string $sql, array $params): array
    {
        $statement = $this->prepare($sql, $params);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows === false ? [] : $rows;
        } finally {
            $statement->closeCursor();
        }
    }

    public function clearStatementCache(): void
    {
        $this->statementCache = [];
    }

    /**
     * execute($params) 在部分 PDO 驱动中会把所有值按字符串绑定。
     * 显式保留 PHP 标量类型，避免 SQLite 数值比较和 LIMIT/OFFSET 出现
     * 与字面量 SQL 不同的结果，同时让 MySQL/PostgreSQL 行为保持一致。
     *
     * @param array<int|string, int|float|string|bool|null> $params
     */
    private function prepare(string $sql, array $params, bool $cacheable = true): PDOStatement
    {
        $cacheable = $cacheable && $this->statementCacheSize > 0 && strlen($sql) <= 4096;
        $statement = $cacheable ? ($this->statementCache[$sql] ?? null) : null;
        if ($statement !== null) {
            unset($this->statementCache[$sql]);
            $this->statementCache[$sql] = $statement;
        } else {
            $preparedSql = $this->driver === 'pgsql' ? $this->normalizePgDollarQuotes($sql) : $sql;
            $statement = $this->pdo->prepare($preparedSql);
        }
        try {
            foreach ($params as $name => $value) {
                $parameter = is_int($name) ? $name + 1 : ':' . ltrim($name, ':');
                $type = match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    // PDO 没有通用的浮点绑定类型；字符串绑定保留十进制文本。
                    default => PDO::PARAM_STR,
                };
                $statement->bindValue($parameter, $value, $type);
            }
            $statement->execute();
        } catch (\Throwable $exception) {
            if ($cacheable) {
                unset($this->statementCache[$sql]);
            }
            throw $exception;
        }
        if ($cacheable && !isset($this->statementCache[$sql])) {
            $this->statementCache[$sql] = $statement;
            while (count($this->statementCache) > $this->statementCacheSize) {
                $oldest = array_key_first($this->statementCache);
                if ($oldest === null) {
                    break;
                }
                unset($this->statementCache[$oldest]);
            }
        }

        return $statement;
    }

    /**
     * pdo_pgsql 会误把 dollar-quoted 文本和块注释里的 :name 当成绑定参数，导致
     * 服务端参数编号错位。交给 PDO 前将完整文本转换为等价的转义字符串字面量，
     * 并屏蔽注释内的伪参数标记；不改变开发者 SQL、注释内容或有效参数值。
     * 未闭合的 $tag$ 原样保留，由数据库报告语法错误，不能悄悄吞掉后续 SQL。
     */
    private function normalizePgDollarQuotes(string $sql): string
    {
        return \Pixiaoka\Orm\Sql\SqlLexer::normalizePostgresForPdo($sql);
    }

    public function lastInsertId(): string
    {
        if ($this->insertId !== null) {
            return ($this->insertId)();
        }

        return $this->pdo->lastInsertId();
    }

    /**
     * P2-5：支持 schema.table 形式的标识符。按 "." 分段，每段独立校验后包裹，
     * 再用 "." 连接。任何一段含非法字符仍然抛 InvalidArgumentException。
     */
    public function quoteIdentifier(string $identifier): string
    {
        $segments = explode('.', $identifier);
        $quoted = [];
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException('Invalid identifier: ' . $identifier);
            }
            $quoted[] = match ($this->driver) {
                'mysql' => '`' . $segment . '`',
                default => '"' . $segment . '"',
            };
        }

        return implode('.', $quoted);
    }

    public function supportsReturning(): bool
    {
        // 注入 insertId 闭包（测试用）时闭包优先：强制走 lastInsertId 路径，
        // 让闭包的返回值（含模拟失败的空串）决定回填结果。
        if ($this->insertId !== null) {
            return false;
        }

        // mysql 的 INSERT ... RETURNING 不可依赖（MariaDB 才有），统一走 lastInsertId。
        return in_array($this->driver, ['pgsql', 'sqlite'], true);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function ping(): bool
    {
        try {
            $statement = $this->pdo->query('SELECT 1');
            $alive = $statement !== false && $statement->fetchColumn() !== false;
            if ($statement !== false) {
                $statement->closeCursor();
            }

            return $alive;
        } catch (\Throwable) {
            return false;
        }
    }

    public function beginTransaction(): bool
    {
        if ($this->transactionDepth === 0) {
            // 未受管的 PDO 事务不能悄悄冒充本适配器的最外层事务。
            if ($this->pdo->inTransaction()) {
                throw new \LogicException('PDO has a transaction not started by PdoConnection; mixing transaction boundaries is forbidden');
            }
            $begun = $this->pdo->beginTransaction();
            if ($begun) {
                $this->transactionDepth = 1;
                $this->assertConsistentTransactionState('begin');
            }

            return $begun;
        }

        $this->assertConsistentTransactionState('begin');
        $depth = $this->transactionDepth + 1;
        $this->execute('SAVEPOINT sp_' . $depth, [], cacheable: false);
        $this->transactionDepth = $depth;

        return true;
    }

    public function commit(): bool
    {
        $depth = $this->transactionDepth;
        if ($depth === 0) {
            throw new \LogicException('The connection has no transaction to commit');
        }
        if ($depth > 1) {
            $this->assertConsistentTransactionState('commit');
            $this->execute('RELEASE SAVEPOINT sp_' . $depth, [], cacheable: false);
            $this->transactionDepth--;

            return true;
        }

        $this->assertConsistentTransactionState('commit');
        $committed = $this->pdo->commit();
        if (!$committed) {
            return false;
        }
        $this->transactionDepth = 0;
        $this->assertConsistentTransactionState('commit');

        return true;
    }

    public function rollback(): bool
    {
        $depth = $this->transactionDepth;
        if ($depth === 0) {
            return true;
        }
        if ($depth > 1) {
            $this->assertConsistentTransactionState('rollback');
            // MySQL、PostgreSQL、SQLite 均支持：回滚保存点后释放同名保存点。
            // 两条语句都成功才减少深度；失败交由 Gateway 禁止复用该连接。
            $this->execute('ROLLBACK TO SAVEPOINT sp_' . $depth, [], cacheable: false);
            $this->execute('RELEASE SAVEPOINT sp_' . $depth, [], cacheable: false);
            $this->transactionDepth--;

            return true;
        }

        $this->assertConsistentTransactionState('rollback');
        $rolledBack = $this->pdo->rollBack();
        if (!$rolledBack) {
            return false;
        }
        $this->transactionDepth = 0;
        $this->assertConsistentTransactionState('rollback');

        return true;
    }

    public function forceRollback(): bool
    {
        $this->clearStatementCache();

        if ($this->transactionDepth === 0) {
            return true;
        }

        $this->assertConsistentTransactionState('forceRollback');
        $rolledBack = $this->pdo->rollBack();
        if (!$rolledBack) {
            return false;
        }
        $this->transactionDepth = 0;
        $this->assertConsistentTransactionState('forceRollback');

        return true;
    }

    /** 非零逻辑深度必须对应真实 PDO 事务；隐式提交后的回滚结果只能视为未知。 */
    private function assertConsistentTransactionState(string $operation): void
    {
        $inTransaction = $this->pdo->inTransaction();
        if ($inTransaction !== ($this->transactionDepth > 0)) {
            throw new \LogicException(sprintf(
                'Database transaction state mismatch during %s: managed depth is %d but PDO::inTransaction() is %s',
                $operation,
                $this->transactionDepth,
                $inTransaction ? 'true' : 'false',
            ));
        }
    }
}
