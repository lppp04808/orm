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

    /**
     * @param PDO $pdo
     * @param \Closure|null $insertId 代替 lastInsertId，测试用
     */
    public function __construct(
        private readonly PDO       $pdo,
        private readonly ?\Closure $insertId = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $params): int
    {
        $statement = $this->prepare($sql, $params);
        $count = $statement->rowCount();
        $statement->closeCursor();

        return $count;
    }

    public function queryOne(string $sql, array $params): ?array
    {
        $statement = $this->prepare($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function queryAll(string $sql, array $params): array
    {
        $statement = $this->prepare($sql, $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * execute($params) 在部分 PDO 驱动中会把所有值按字符串绑定。
     * 显式保留 PHP 标量类型，避免 SQLite 数值比较和 LIMIT/OFFSET 出现
     * 与字面量 SQL 不同的结果，同时让 MySQL/PostgreSQL 行为保持一致。
     *
     * @param array<int|string, int|float|string|bool|null> $params
     */
    private function prepare(string $sql, array $params): PDOStatement
    {
        if ($this->driver === 'pgsql') {
            $sql = $this->normalizePgDollarQuotes($sql);
        }
        $statement = $this->pdo->prepare($sql);
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

        return $statement;
    }

    /**
     * pdo_pgsql 会误把 dollar-quoted 文本里的 :name 当成绑定参数，导致服务端参数编号错位。
     * 在交给 PDO 前把完整文本转换为等价的 SQL 字符串字面量；不改变开发者 SQL 或参数值。
     * 未闭合的 $tag$ 原样保留，由数据库报告语法错误，不能悄悄吞掉后续 SQL。
     */
    private function normalizePgDollarQuotes(string $sql): string
    {
        if (!str_contains($sql, '$')) {
            return $sql;
        }

        $out = '';
        $length = strlen($sql);
        $state = 'sql';
        $blockDepth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($state === 'line') {
                $out .= $char;
                if ($char === "\n" || $char === "\r") {
                    $state = 'sql';
                }
                continue;
            }
            if ($state === 'block') {
                if ($char === '/' && $next === '*') {
                    $out .= '/*';
                    $blockDepth++;
                    $i++;
                } elseif ($char === '*' && $next === '/') {
                    $out .= '*/';
                    $i++;
                    if (--$blockDepth === 0) {
                        $state = 'sql';
                    }
                } else {
                    $out .= $char;
                }
                continue;
            }
            if ($state === "'" || $state === '"' || $state === 'E') {
                $out .= $char;
                if ($char === '\\' && $state === 'E' && $next !== '') {
                    // PostgreSQL E'...' 使用反斜杠转义；普通标准字符串不把反斜杠当转义。
                    $out .= $next;
                    $i++;
                    continue;
                }
                $quote = $state === 'E' ? "'" : $state;
                if ($char === $quote) {
                    if ($next === $char) {
                        $out .= $next;
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }

            if ($char === "'") {
                $prefix = $i > 0 ? $sql[$i - 1] : '';
                $beforePrefix = $i > 1 ? $sql[$i - 2] : '';
                $state = ($prefix === 'E' || $prefix === 'e')
                    && !preg_match('/[A-Za-z0-9_$]/', $beforePrefix) ? 'E' : "'";
                $out .= $char;
            } elseif ($char === '"') {
                $state = $char;
                $out .= $char;
            } elseif ($char === '-' && $next === '-') {
                $out .= '--';
                $i++;
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $out .= '/*';
                $i++;
                $blockDepth = 1;
                $state = 'block';
            } elseif ($char === '$'
                && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i) === 1) {
                $delimiter = $match[0];
                $contentStart = $i + strlen($delimiter);
                $closing = strpos($sql, $delimiter, $contentStart);
                if ($closing === false) {
                    $out .= substr($sql, $i);
                    break;
                }
                $content = substr($sql, $contentStart, $closing - $contentStart);
                // E 字符串在 standard_conforming_strings=off 时也能原样保留反斜杠。
                $out .= "E'" . str_replace(["\\", "'"], ["\\\\", "''"], $content) . "'";
                $i = $closing + strlen($delimiter) - 1;
            } else {
                $out .= $char;
            }
        }

        return $out;
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
                throw new \InvalidArgumentException('非法标识符: ' . $identifier);
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
                throw new \LogicException('PDO 已存在未由 PdoConnection 开启的事务，禁止混用事务边界');
            }
            $begun = $this->pdo->beginTransaction();
            if ($begun) {
                $this->transactionDepth = 1;
            }

            return $begun;
        }

        $depth = $this->transactionDepth + 1;
        $this->execute('SAVEPOINT sp_' . $depth, []);
        $this->transactionDepth = $depth;

        return true;
    }

    public function commit(): bool
    {
        $depth = $this->transactionDepth;
        if ($depth === 0) {
            throw new \LogicException('当前连接没有可提交的事务');
        }
        if ($depth > 1) {
            $this->execute('RELEASE SAVEPOINT sp_' . $depth, []);
            $this->transactionDepth--;

            return true;
        }

        $committed = $this->pdo->commit();
        if ($committed) {
            $this->transactionDepth = 0;
        }

        return $committed;
    }

    public function rollback(): bool
    {
        $depth = $this->transactionDepth;
        if ($depth === 0) {
            return true;
        }
        if ($depth > 1) {
            // MySQL、PostgreSQL、SQLite 均支持：回滚保存点后释放同名保存点。
            // 两条语句都成功才减少深度；失败交由 Gateway 禁止复用该连接。
            $this->execute('ROLLBACK TO SAVEPOINT sp_' . $depth, []);
            $this->execute('RELEASE SAVEPOINT sp_' . $depth, []);
            $this->transactionDepth--;

            return true;
        }

        if ($this->pdo->inTransaction()) {
            $rolledBack = $this->pdo->rollBack();
            if ($rolledBack) {
                $this->transactionDepth = 0;
            }

            return $rolledBack;
        }

        $this->transactionDepth = 0;

        return true;
    }

    public function forceRollback(): bool
    {
        if ($this->transactionDepth === 0) {
            return true;
        }

        $rolledBack = true;
        if ($this->pdo->inTransaction()) {
            $rolledBack = $this->pdo->rollBack();
        }
        if ($rolledBack) {
            $this->transactionDepth = 0;
        }

        return $rolledBack;
    }
}
