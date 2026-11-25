<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

/** 物理 PDO 事务与 PdoConnection 逻辑深度必须始终一致。 */
final class TransactionStateConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        Session::discard();
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    /** @return array<string, array{string}> */
    public static function booleanFailureOperation(): array
    {
        return ['begin' => ['begin'], 'commit' => ['commit'], 'rollback' => ['rollback']];
    }

    #[DataProvider('booleanFailureOperation')]
    public function testFalseDriverResultIsNotAPhysicalSuccess(string $operation): void
    {
        $pdo = new TransactionStatePdo();
        $connection = new PdoConnection($pdo);
        $pdo->{$operation . 'Result'} = false;
        if ($operation !== 'begin') {
            $connection->beginTransaction();
        }

        $method = $operation === 'begin' ? 'beginTransaction' : $operation;
        self::assertFalse($connection->{$method}());
        self::assertSame($operation === 'begin' ? 0 : 1, $this->depth($connection));
        if ($pdo->inTransaction()) {
            $pdo->rollbackResult = true;
            self::assertTrue($connection->forceRollback());
        }
    }

    /** @return array<string, array{string}> */
    public static function rollbackOperation(): array
    {
        return ['rollback' => ['rollback'], 'forceRollback' => ['forceRollback']];
    }

    public function testBeginDriverExceptionIsPropagatedWithoutChangingDepth(): void
    {
        $pdo = new TransactionStatePdo();
        $connection = new PdoConnection($pdo);
        $failure = new \PDOException('injected begin exception');
        $pdo->beginFailure = $failure;

        try {
            $connection->beginTransaction();
            self::fail('begin 驱动异常不得被吞掉');
        } catch (\PDOException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(0, $this->depth($connection));
    }

    /** @return array<string, array{string, string, int}> */
    public static function outerExceptionOperation(): array
    {
        return ['commit' => ['commit', 'commitFailure', 1], 'rollback' => ['rollback', 'rollbackFailure', 1], 'forceRollback' => ['forceRollback', 'rollbackFailure', 2]];
    }

    #[DataProvider('outerExceptionOperation')]
    public function testOuterDriverExceptionIsPropagatedWithoutChangingDepth(
        string $operation,
        string $failureProperty,
        int $depth,
    ): void {
        $pdo = new TransactionStatePdo();
        $connection = new PdoConnection($pdo);
        $connection->beginTransaction();
        if ($depth === 2) {
            $connection->beginTransaction();
        }
        $failure = new \PDOException('injected ' . $operation . ' exception');
        $pdo->{$failureProperty} = $failure;

        try {
            $connection->{$operation}();
            self::fail($operation . ' 驱动异常不得被吞掉');
        } catch (\PDOException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame($depth, $this->depth($connection));
        if ($pdo->inTransaction()) {
            $pdo->rollbackFailure = null;
            $pdo->rollBack();
        }
    }

    /** @return array<string, array{string, string, int}> */
    public static function falseSuccessOperation(): array
    {
        return [
            'begin' => ['begin', 'beginSkipsTransaction', 1],
            'commit' => ['commit', 'commitSkipsTransaction', 0],
            'rollback' => ['rollback', 'rollbackSkipsTransaction', 0],
            'forceRollback' => ['forceRollback', 'rollbackSkipsTransaction', 0],
        ];
    }

    #[DataProvider('falseSuccessOperation')]
    public function testTrueDriverResultMustMatchPhysicalState(
        string $operation,
        string $skipProperty,
        int $expectedDepth,
    ): void {
        $pdo = new TransactionStatePdo();
        $connection = new PdoConnection($pdo);
        $pdo->{$skipProperty} = true;
        if ($operation !== 'begin') {
            $connection->beginTransaction();
        }
        $method = $operation === 'begin' ? 'beginTransaction' : $operation;

        try {
            $connection->{$method}();
            self::fail($operation . ' 返回 true 但物理状态未改变时必须失败');
        } catch (\LogicException $e) {
            self::assertStringContainsString($operation, $e->getMessage());
        }
        self::assertSame($expectedDepth, $this->depth($connection));
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    #[DataProvider('rollbackOperation')]
    public function testEmptyAndReadOnlyTransactionsCanStillFinish(string $operation): void
    {
        foreach ([false, true] as $readOnly) {
            $pdo = new TransactionStatePdo();
            $connection = new PdoConnection($pdo);
            self::assertTrue($connection->beginTransaction());
            self::assertTrue($pdo->inTransaction());
            if ($readOnly) {
                self::assertSame(1, $connection->queryOne('SELECT 1 AS value', [])['value']);
            }

            self::assertTrue($connection->{$operation}());
            self::assertFalse($pdo->inTransaction());
            self::assertTrue($connection->beginTransaction());
            self::assertTrue($connection->commit());
        }
    }

    #[DataProvider('rollbackOperation')]
    public function testLostOuterTransactionCannotBeReportedAsRolledBack(string $operation): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new PdoConnection($pdo);
        self::assertTrue($connection->beginTransaction());
        $pdo->rollBack();

        try {
            $connection->{$operation}();
            self::fail('PDO 无物理事务而内部仍有深度时必须抛异常');
        } catch (\LogicException $e) {
            self::assertStringContainsString('transaction state mismatch', $e->getMessage());
            self::assertStringContainsString($operation, $e->getMessage());
        }

        self::assertSame(1, $this->depth($connection), '未知事务不得被清成已确认回滚');
    }

    public function testImplicitlyCommittedNestedWorkCannotBeReleasedOrRolledBackAsSavepoint(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new PdoConnection($pdo);
        $pdo->exec('CREATE TABLE transaction_state (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->execute("INSERT INTO transaction_state (id, body) VALUES (1, 'uncommitted')", []);
        $pdo->commit();

        foreach (['commit', 'rollback', 'forceRollback'] as $operation) {
            try {
                $connection->{$operation}();
                self::fail($operation . ' 不得把已消失的保存点当作成功');
            } catch (\LogicException $e) {
                self::assertStringContainsString('transaction state mismatch', $e->getMessage());
                self::assertStringContainsString($operation, $e->getMessage());
            }
        }

        self::assertSame(2, $this->depth($connection));
        self::assertSame('uncommitted', $pdo->query('SELECT body FROM transaction_state')->fetchColumn());
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function savepointFailureOperation(): array
    {
        return [
            'create' => ['SAVEPOINT sp_2', 'beginTransaction', 'begin', 1],
            'release on commit' => ['RELEASE SAVEPOINT sp_2', 'commit', 'commit', 2],
            'rollback to' => ['ROLLBACK TO SAVEPOINT sp_2', 'rollback', 'rollback', 2],
            'release after rollback' => ['RELEASE SAVEPOINT sp_2', 'rollback', 'rollback', 2],
        ];
    }

    #[DataProvider('savepointFailureOperation')]
    public function testSavepointDriverExceptionIsPropagatedWithOperationAndDepth(
        string $sql,
        string $method,
        string $operation,
        int $expectedDepth,
    ): void {
        $pdo = new TransactionStatePdo();
        $connection = new PdoConnection($pdo);
        $connection->beginTransaction();
        if ($method !== 'beginTransaction') {
            $connection->beginTransaction();
        }
        $failure = new \PDOException('injected ' . $sql . ' exception');
        $pdo->savepointFailure = $failure;
        $pdo->failOnSql = $sql;

        try {
            $connection->{$method}();
            self::fail($sql . ' 驱动异常不得被吞掉');
        } catch (\PDOException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame($expectedDepth, $this->depth($connection), $operation);
        $pdo->savepointFailure = null;
        self::assertTrue($connection->forceRollback());
    }

    public function testMissingSavepointFailurePreservesDriverException(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new PdoConnection($pdo);
        $connection->beginTransaction();
        $connection->beginTransaction();
        $pdo->exec('ROLLBACK TO SAVEPOINT sp_2');
        $pdo->exec('RELEASE SAVEPOINT sp_2');

        try {
            $connection->rollback();
            self::fail('不存在的保存点不得被吞掉');
        } catch (\PDOException $e) {
            self::assertStringContainsString('no such savepoint', $e->getMessage());
        }
        self::assertSame(2, $this->depth($connection));
        self::assertTrue($connection->forceRollback());
    }

    public function testNestedLostPhysicalTransactionPoisonsConnectionAndRetainsEntity(): void
    {
        $connection = new MatrixConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $record = new MatrixRecord();
        $record->body = 'committed';
        $record->save();
        $gateway->clearCache(MatrixRecord::class);
        $business = new \DomainException('inner business failure');

        try {
            $gateway->transaction(static function () use ($gateway, $connection, $record, $business): void {
                $gateway->transaction(static function () use ($connection, $record, $business): void {
                    $record->body = 'uncommitted inner value';
                    $record->save();
                    $connection->pdo->commit();
                    throw $business;
                });
            });
            self::fail('内层状态不一致必须报告未知回滚并停止外层提交');
        } catch (TransactionException $e) {
            self::assertSame('rollback', $e->operation);
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
            self::assertStringContainsString('transaction state mismatch', $e->getPrevious()?->getMessage() ?? '');
        }

        self::assertSame(['begin', 'begin', 'rollback'], $connection->transactionCalls);
        self::assertSame('uncommitted inner value', $record->body);
        self::assertSame('uncommitted inner value', $record->takeOriginal()['body']);
        self::assertSame(2, $record->revision);
        self::assertSame('uncommitted inner value', $connection->pdo->query('SELECT body FROM matrix_record')->fetchColumn());
        try {
            $gateway->selectRaw('SELECT 1');
            self::fail('状态不一致后连接不可复用');
        } catch (\LogicException $e) {
            self::assertStringContainsString('do not reuse this connection', $e->getMessage());
        }
        Session::discard();
    }

    /** @return array<string, array{bool}> */
    public static function caughtSavepointFailureOutcome(): array
    {
        return ['outer returns' => [false], 'outer throws another business exception' => [true]];
    }

    #[DataProvider('caughtSavepointFailureOutcome')]
    public function testCaughtMissingSavepointPoisonsWholeOuterTransaction(bool $outerThrows): void
    {
        $connection = new MatrixConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $record = new MatrixRecord();
        $record->body = 'committed';
        $record->save();
        $gateway->clearCache(MatrixRecord::class);
        $business = new \DomainException('outer business failure');
        $innerFailure = null;

        try {
            $gateway->transaction(static function () use (
                $gateway,
                $connection,
                $record,
                $business,
                $outerThrows,
                &$innerFailure,
            ): void {
                $record->body = 'uncommitted outer value';
                $record->save();
                try {
                    $gateway->transaction(static function () use ($connection, $record): void {
                        $record->body = 'uncommitted inner value';
                        $record->save();
                        // 只移除 SAVEPOINT，最外层物理事务和外层写入仍然真实存在。
                        $connection->pdo->exec('ROLLBACK TO SAVEPOINT sp_2');
                        $connection->pdo->exec('RELEASE SAVEPOINT sp_2');
                        throw new \RuntimeException('trigger lost savepoint rollback');
                    });
                } catch (TransactionException $e) {
                    $innerFailure = $e;
                }
                if ($outerThrows) {
                    throw $business;
                }
            });
            self::fail('SAVEPOINT 消失被捕获后，外层不得提交或把后续回滚当作完整成功');
        } catch (TransactionException $e) {
            self::assertInstanceOf(TransactionException::class, $innerFailure);
            self::assertSame('rollback', $innerFailure->operation);
            self::assertInstanceOf(\PDOException::class, $innerFailure->getPrevious());
            self::assertStringContainsString('no such savepoint', $innerFailure->getPrevious()->getMessage());
            if ($outerThrows) {
                self::assertNotSame($innerFailure, $e);
                self::assertSame('rollback', $e->operation);
                self::assertSame($business, $e->businessException);
                self::assertSame($innerFailure, $e->getPrevious(), '第二次业务异常不得覆盖首次 SAVEPOINT 失败');
            } else {
                self::assertSame($innerFailure, $e, '外层正常返回也必须原样传播首次事务异常');
            }
        }

        self::assertSame(['begin', 'begin', 'rollback'], $connection->transactionCalls);
        self::assertSame('uncommitted inner value', $record->body);
        self::assertSame('uncommitted inner value', $record->takeOriginal()['body']);
        self::assertSame(3, $record->revision);
        self::assertTrue($connection->pdo->inTransaction(), '本用例的关键是外层 PDO 事务仍然存在');
        self::assertSame('uncommitted outer value', $connection->pdo->query('SELECT body FROM matrix_record')->fetchColumn());
        try {
            $gateway->selectRaw('SELECT 1');
            self::fail('未知 SAVEPOINT 结果必须熔断整个连接');
        } catch (\LogicException $e) {
            self::assertStringContainsString('do not reuse this connection', $e->getMessage());
        }

        // 测试环境清理由底层 PDO 执行，不能复用已熔断的 ORM 连接。
        $connection->pdo->rollBack();
        self::assertSame('committed', $connection->pdo->query('SELECT body FROM matrix_record')->fetchColumn());
        $calls = $connection->transactionCalls;
        Session::discard();
        self::assertSame($calls, $connection->transactionCalls);
    }

    public function testDiscardWithLostPhysicalTransactionReportsRollbackFailure(): void
    {
        $connection = new MatrixConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $record = new MatrixRecord();
        $record->body = 'committed';
        $record->save();
        $gateway->clearCache(MatrixRecord::class);

        try {
            $gateway->transaction(static function () use ($connection, $record): void {
                $record->body = 'uncommitted discard value';
                $record->save();
                $connection->pdo->rollBack();
                Session::discard();
            });
        } catch (TransactionException $e) {
            self::assertSame('rollback', $e->operation);
            self::assertNull($e->businessException);
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
            self::assertStringContainsString('forceRollback', $e->getPrevious()->getMessage());
        }

        self::assertSame(['begin', 'forceRollback'], $connection->transactionCalls);
        self::assertSame('uncommitted discard value', $record->body);
        self::assertSame('committed', $connection->pdo->query('SELECT body FROM matrix_record')->fetchColumn());
        try {
            $gateway->selectRaw('SELECT 1');
            self::fail('forceRollback 状态不一致后连接不可复用');
        } catch (\LogicException $e) {
            self::assertStringContainsString('do not reuse this connection', $e->getMessage());
        }
    }

    private function depth(PdoConnection $connection): int
    {
        return (new \ReflectionProperty(PdoConnection::class, 'transactionDepth'))->getValue($connection);
    }
}

/** 真实 PDO 事务与可控驱动失败，不替换 PdoConnection 自身的任何事务逻辑。 */
final class TransactionStatePdo extends PDO
{
    public bool $beginResult = true;
    public bool $commitResult = true;
    public bool $rollbackResult = true;
    public ?\Throwable $beginFailure = null;
    public ?\Throwable $commitFailure = null;
    public ?\Throwable $rollbackFailure = null;
    public bool $beginSkipsTransaction = false;
    public bool $commitSkipsTransaction = false;
    public bool $rollbackSkipsTransaction = false;
    public ?\Throwable $savepointFailure = null;
    public string $failOnSql = '';

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($query === $this->failOnSql && $this->savepointFailure !== null) {
            throw $this->savepointFailure;
        }

        return parent::prepare($query, $options);
    }

    public function beginTransaction(): bool
    {
        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }
        if (!$this->beginSkipsTransaction && !parent::beginTransaction()) {
            return false;
        }

        return $this->beginResult;
    }

    public function commit(): bool
    {
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }
        if (!$this->commitSkipsTransaction && !parent::commit()) {
            return false;
        }

        return $this->commitResult;
    }

    public function rollBack(): bool
    {
        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }
        if (!$this->rollbackSkipsTransaction && !parent::rollBack()) {
            return false;
        }

        return $this->rollbackResult;
    }
}
