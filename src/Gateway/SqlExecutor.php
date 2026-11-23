<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use Pixiaoka\Orm\Context;
use Pixiaoka\Orm\Observability\Clock;
use Pixiaoka\Orm\Observability\ContextInfo;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Query\Expression;
use Pixiaoka\Orm\Session;

/** @internal 连接级 SQL 执行、观测与原生参数守卫；不负责实体或缓存状态。 */
final class SqlExecutor
{
    /** @var \WeakMap<Connection, string> 开始、提交或回滚结果未知后，连接及所有 Gateway 都不可复用。 */
    private static ?\WeakMap $failedConnections = null;

    /** @var \WeakMap<Connection, int> 观测回调不是查询或业务写入入口，嵌套事件保留外层保护。 */
    private static ?\WeakMap $observingConnections = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EventDispatcher $events,
        private readonly Clock $clock,
    ) {
    }

    public function assertConnectionUsable(): void
    {
        if (self::$failedConnections !== null && isset(self::$failedConnections[$this->connection])) {
            throw new \LogicException(self::$failedConnections[$this->connection]);
        }
        if (self::$observingConnections !== null && isset(self::$observingConnections[$this->connection])) {
            throw new \LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
        }
    }

    /** @internal discard() 应继续清理失败 Session，但禁止监听器重入清理。 */
    public function assertObservationBoundary(): void
    {
        if (self::$observingConnections !== null && isset(self::$observingConnections[$this->connection])) {
            throw new \LogicException('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期');
        }
    }

    /** 标记结果未知的物理连接；实体和缓存失效仍由 EntityGateway 负责。 */
    public function invalidateConnection(): void
    {
        self::$failedConnections ??= new \WeakMap();
        self::$failedConnections[$this->connection] = '数据库开始、提交或回滚结果未知，禁止复用当前连接；请丢弃该连接、Gateway 和关联实体';
    }

    /** 事件同步发送，但连接级隔离禁止监听器重入 ORM；监听器异常仍由 Dispatcher 隔离。 */
    public function dispatchObserved(callable $dispatch): void
    {
        if (!$this->events->hasListeners()) {
            return;
        }
        self::$observingConnections ??= new \WeakMap();
        $depth = (self::$observingConnections[$this->connection] ?? 0) + 1;
        self::$observingConnections[$this->connection] = $depth;
        try {
            Session::observe($dispatch);
        } finally {
            if ($depth === 1) {
                unset(self::$observingConnections[$this->connection]);
            } else {
                self::$observingConnections[$this->connection] = $depth - 1;
            }
        }
    }

    public function contextInfo(Context $context): ContextInfo
    {
        return new ContextInfo(
            $context->type(),
            spl_object_id($context),
        );
    }

    /** @return list<array<string, mixed>> 原生 SELECT/CTE 查询，调用方负责 SQL 的正确性。 */
    public function selectRaw(string|Expression $sql, array $params = []): array
    {
        $query = $this->rawQuery($sql, $params);

        return $this->queryAll($query['sql'], $query['params']);
    }

    /** @return array<string, mixed>|null */
    public function selectOneRaw(string|Expression $sql, array $params = []): ?array
    {
        $query = $this->rawQuery($sql, $params);

        return $this->queryOne($query['sql'], $query['params']);
    }

    /** 原生 INSERT/UPDATE/DELETE/DDL；SELECT 请使用 selectRaw() 以获取结果。 */
    public function executeRaw(string|Expression $sql, array $params = []): int
    {
        $query = $this->rawQuery($sql, $params);

        return $this->execute($query['sql'], $query['params']);
    }

    /** @return array{sql: string, params: array<string, mixed>} */
    private function rawQuery(string|Expression $sql, array $params): array
    {
        if ($sql instanceof Expression) {
            if ($params !== []) {
                throw new \InvalidArgumentException('Expression 已携带参数，不要再传 $params');
            }
            $query = $sql->build();
            $this->assertNamedParams($query['sql'], $query['params']);
            foreach ($query['params'] as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('原生 SQL 参数值必须是标量或 null');
                }
            }

            return $query;
        }
        if (trim($sql) === '') {
            throw new \InvalidArgumentException('原生 SQL 不能为空');
        }
        $this->assertNamedParams($sql, $params);
        foreach ($params as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('原生 SQL 参数值必须是标量或 null');
            }
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function assertNamedParams(string $sql, array $params): void
    {
        foreach ($params as $key => $_) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new \InvalidArgumentException('原生 SQL 参数必须使用命名占位符');
            }
        }
        // 用于识别命名占位符的状态机：忽略字符串、标识符、注释、PG dollar quote 和 :: 类型转换。
        $used = [];
        $length = strlen($sql);
        $state = 'sql';
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($state !== 'sql') {
                if (str_starts_with($state, '$')) {
                    if (substr($sql, $i, strlen($state)) === $state) {
                        $i += strlen($state) - 1;
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'line') {
                    if ($char === "\n" || $char === "\r") {
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'block') {
                    if ($char === '*' && $next === '/') {
                        $state = 'sql';
                        $i++;
                    }
                    continue;
                }
                if ($char === $state) {
                    if ($next === $char && $state !== '`') {
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }
            if ($char === '$' && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $match) === 1) {
                $state = $match[0];
                $i += strlen($state) - 1;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $state = $char;
            } elseif ($char === '-' && $next === '-') {
                $state = 'line';
                $i++;
            } elseif ($char === '#') {
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $state = 'block';
                $i++;
            } elseif ($char === ':' && $next === ':') {
                $i++;
            } elseif ($char === ':' && preg_match('/[A-Za-z_]/', $next) === 1) {
                $j = $i + 1;
                while ($j + 1 < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j + 1]) === 1) {
                    $j++;
                }
                $used[] = substr($sql, $i + 1, $j - $i);
                $i = $j;
            } elseif ($char === '?') {
                throw new \InvalidArgumentException('原生 SQL 请使用命名占位符，避免与复杂查询参数合并时错位');
            }
        }
        $used = array_values(array_unique($used));
        sort($used);
        $provided = array_keys($params);
        sort($provided);
        if ($used !== $provided) {
            throw new \InvalidArgumentException(sprintf(
                '原生 SQL 参数不匹配，SQL 需要 [%s]，实际提供 [%s]',
                implode(', ', $used),
                implode(', ', $provided),
            ));
        }
    }

    public function assertLockSupported(string $sql): void
    {
        if ($this->connection instanceof DriverAwareConnection
            && $this->connection->driver() === 'sqlite'
            && preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $sql) === 1) {
            throw new \LogicException('SQLite 不支持 FOR UPDATE / FOR SHARE，请使用事务');
        }
    }

    /** @return list<string> 只收集键名，不读取、复制或序列化任何参数值。 */
    private function parameterNames(array $params): array
    {
        return array_map(
            static fn (int|string $name): string => is_int($name) ? (string) ($name + 1) : ltrim($name, ':'),
            array_keys($params),
        );
    }

    public function execute(string $sql, array $params, ?string $model = null): int
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $affected = $this->connection->execute($sql, $params);
            $this->observeSql($sql, $params, $model, $started, $affected, null);

            return $affected;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function queryAll(string $sql, array $params, ?string $model = null): array
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $rows = $this->connection->queryAll($sql, $params);
            $this->observeSql($sql, $params, $model, $started, null, count($rows));

            return $rows;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function queryOne(string $sql, array $params, ?string $model = null): ?array
    {
        $this->assertConnectionUsable();
        $started = $this->clock->now();
        try {
            $row = $this->connection->queryOne($sql, $params);
            $this->observeSql($sql, $params, $model, $started, null, $row === null ? 0 : 1);

            return $row;
        } catch (\Throwable $e) {
            $this->observeSql($sql, $params, $model, $started, null, null, $e);
            throw $e;
        }
    }

    private function observeSql(
        string $sql,
        array $params,
        ?string $model,
        float $started,
        ?int $affected,
        ?int $rows,
        ?\Throwable $exception = null,
    ): void {
        if (!$this->events->hasListeners()) {
            return;
        }
        $operation = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: 'SQL');
        $this->dispatchObserved(fn () => $this->events->dispatch(new SqlEvent(
            $sql,
            $operation,
            $model,
            $this->contextInfo(Session::context()),
            max(0.0, $this->clock->now() - $started),
            $exception === null,
            $this->parameterNames($params),
            $affected,
            $rows,
            $exception === null ? null : $exception::class,
        )));
    }
}
