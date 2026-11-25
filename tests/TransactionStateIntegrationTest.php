<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

/** 仅在显式提供测试 DSN 时连接真实数据库；绝不回退到本机默认实例。 */
final class TransactionStateIntegrationTest extends TestCase
{
    public function testMysqlImplicitDdlCommitMakesRollbackUnknown(): void
    {
        $dsn = getenv('ORM_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set ORM_TEST_MYSQL_DSN to a disposable MySQL test database');
        }
        $pdo = $this->connect($dsn, 'mysql');
        $table = 'orm_transaction_state_' . bin2hex(random_bytes(6));
        $connection = new PdoConnection($pdo);
        $connection->beginTransaction();
        $connection->beginTransaction();

        try {
            $connection->execute('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY)', []);
            self::assertFalse($pdo->inTransaction(), 'MySQL DDL 必须产生隐式提交，否则此用例没有覆盖目标场景');
            foreach (['rollback', 'forceRollback'] as $operation) {
                try {
                    $connection->{$operation}();
                    self::fail($operation . ' must not mistake an implicit commit for a rollback');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('transaction state mismatch', $e->getMessage());
                    self::assertStringContainsString($operation, $e->getMessage());
                }
            }
            self::assertNotFalse($pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function testPostgresqlExternallyCommittedSavepointMakesRollbackUnknown(): void
    {
        $dsn = getenv('ORM_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set ORM_TEST_PGSQL_DSN to a disposable PostgreSQL test database');
        }
        $pdo = $this->connect($dsn, 'pgsql');
        $connection = new PdoConnection($pdo);
        $connection->beginTransaction();
        $connection->beginTransaction();
        $pdo->commit();

        self::assertFalse($pdo->inTransaction());
        foreach (['commit', 'rollback', 'forceRollback'] as $operation) {
            try {
                $connection->{$operation}();
                self::fail($operation . ' must not accept a missing physical transaction');
            } catch (\LogicException $e) {
                self::assertStringContainsString('transaction state mismatch', $e->getMessage());
                self::assertStringContainsString($operation, $e->getMessage());
            }
        }
    }

    public function testPostgresqlCaughtMissingSavepointRetainsFirstDriverFailure(): void
    {
        $dsn = getenv('ORM_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set ORM_TEST_PGSQL_DSN to a disposable PostgreSQL test database');
        }
        $pdo = $this->connect($dsn, 'pgsql');
        $table = 'orm_transaction_savepoint_' . bin2hex(random_bytes(6));
        $pdo->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $connection = new PdoConnection($pdo);
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $innerFailure = null;
        $business = new \DomainException('outer business failure');

        try {
            try {
                $gateway->transaction(static function () use (
                    $gateway,
                    $pdo,
                    $table,
                    $business,
                    &$innerFailure,
                ): void {
                    $gateway->executeRaw('INSERT INTO ' . $table . ' (id, body) VALUES (:id, :body)', [
                        'id' => 1,
                        'body' => 'outer uncommitted',
                    ]);
                    try {
                        $gateway->transaction(static function () use ($gateway, $pdo, $table): void {
                            $gateway->executeRaw('INSERT INTO ' . $table . ' (id, body) VALUES (:id, :body)', [
                                'id' => 2,
                                'body' => 'inner savepoint',
                            ]);
                            $pdo->exec('ROLLBACK TO SAVEPOINT sp_2');
                            $pdo->exec('RELEASE SAVEPOINT sp_2');
                            throw new \RuntimeException('trigger PostgreSQL missing savepoint');
                        });
                    } catch (TransactionException $e) {
                        $innerFailure = $e;
                    }
                    throw $business;
                });
                self::fail('PostgreSQL SAVEPOINT 首次失败不得被外层业务异常或回滚覆盖');
            } catch (TransactionException $e) {
                self::assertInstanceOf(TransactionException::class, $innerFailure);
                self::assertSame('rollback', $innerFailure->operation);
                self::assertInstanceOf(\PDOException::class, $innerFailure->getPrevious());
                self::assertStringContainsString('savepoint', $innerFailure->getPrevious()->getMessage());
                self::assertSame('rollback', $e->operation);
                self::assertSame($business, $e->businessException);
                self::assertSame($innerFailure, $e->getPrevious());
            }
            self::assertTrue($pdo->inTransaction());
            try {
                $gateway->selectRaw('SELECT 1');
                self::fail('PostgreSQL 保存点失败后连接不得复用');
            } catch (\LogicException $e) {
                self::assertStringContainsString('do not reuse this connection', $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::discard();
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }

        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname = current_schema() AND tablename = '
            . $pdo->quote($table),
        )->fetchColumn());
    }

    private function connect(string $dsn, string $expectedDriver): PDO
    {
        $prefix = 'ORM_TEST_' . strtoupper($expectedDriver);
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');
        $pdo = new PDO(
            $dsn,
            is_string($user) && $user !== '' ? $user : null,
            is_string($password) && $password !== '' ? $password : null,
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::assertSame($expectedDriver, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        return $pdo;
    }
}
