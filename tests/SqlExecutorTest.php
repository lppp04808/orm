<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\SqlExecutor;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\SystemClock;
use Pixiaoka\Orm\Session;

final class SqlExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testFailedConnectionBlocksOtherGatewaysAndExecutors(): void
    {
        $connection = new SqlExecutorConnection();
        $executor = $this->executor($connection);
        $executor->invalidateConnection();

        foreach ([
            new EntityGateway($connection),
            new EntityGateway($connection),
        ] as $gateway) {
            try {
                $gateway->selectRaw('SELECT 1');
                self::fail('同一物理连接熔断后，其他 Gateway 必须拒绝执行 SQL');
            } catch (\LogicException $e) {
                self::assertSame(
                    '数据库开始、提交或回滚结果未知，禁止复用当前连接；请丢弃该连接、Gateway 和关联实体',
                    $e->getMessage(),
                );
            }
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('禁止复用当前连接');
        $this->executor($connection)->selectRaw('SELECT 1');
    }

    public function testObservationReentryIsRejectedForSqlAndObservationBoundaries(): void
    {
        $connection = new SqlExecutorConnection();
        $gateway = new EntityGateway($connection);
        $other = new EntityGateway($connection);
        $attempts = 0;
        $gateway->subscribe(static function (Event $event) use ($other, &$attempts): void {
            if (!$event instanceof SqlEvent) {
                return;
            }
            $attempts++;
            try {
                $other->selectRaw('SELECT 1');
                self::fail('观测监听器不得通过其他 Gateway 重入同一连接');
            } catch (\LogicException $e) {
                self::assertSame('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期', $e->getMessage());
            }
            try {
                $other->assertObservationBoundary();
                self::fail('观测监听器不得重入观测边界');
            } catch (\LogicException $e) {
                self::assertSame('观测监听器不得重入同一连接执行 ORM 操作或改变 Session/事务生命周期', $e->getMessage());
            }
        });

        self::assertSame([], $gateway->selectRaw('SELECT 1'));
        self::assertSame(1, $attempts);
        self::assertSame([['value' => 1]], $gateway->selectRaw('SELECT :value AS value', ['value' => 1]));
    }

    public function testNamedParamLexerIgnoresStringsCastsAndPostgresDollarQuotes(): void
    {
        $connection = new SqlExecutorConnection();
        $sql = <<<'SQL'
SELECT ':string' AS literal_value,
       ":identifier" AS quoted_identifier,
       '$tag$:dollar$tag$' AS dollar_value,
       :value::int AS typed_value
       /* :block_comment */
       -- :line_comment
SQL;

        self::assertSame(
            [['value' => 7]],
            $this->executor($connection)->selectRaw($sql, ['value' => 7]),
        );
        self::assertSame([$sql], $connection->queries);
    }

    public function testNamedParamLexerRejectsPositionalPlaceholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('原生 SQL 请使用命名占位符，避免与复杂查询参数合并时错位');
        $this->executor(new SqlExecutorConnection())->selectRaw('SELECT :value + ?', ['value' => 1]);
    }

    private function executor(Connection $connection): SqlExecutor
    {
        return new SqlExecutor($connection, new EventDispatcher(), new SystemClock());
    }
}

/** 不解析 SQL 的连接桩，确保方言词法校验独立于具体 PDO 驱动。 */
final class SqlExecutorConnection implements Connection
{
    /** @var list<string> */
    public array $queries = [];

    public function execute(string $sql, array $params): int { return 0; }

    public function queryOne(string $sql, array $params): ?array { return null; }

    public function queryAll(string $sql, array $params): array
    {
        $this->queries[] = $sql;

        return $params === [] ? [] : [$params];
    }

    public function lastInsertId(): string { return '0'; }
    public function quoteIdentifier(string $identifier): string { return '"' . $identifier . '"'; }
    public function supportsReturning(): bool { return false; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollback(): bool { return true; }
}
