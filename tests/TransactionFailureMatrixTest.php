<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\ManagedConnection;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SessionEvent;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Session;

/** 独立验证事务收尾，不复用 OrmObservabilityTest 只能返回 false 的桩。 */
final class TransactionFailureMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        Session::discard();
    }

    protected function tearDown(): void
    {
        // 失败事务已在各自断言中清理；这里不应再调用连接的事务 API。
        Session::discard();
    }

    /** @return array<string, array{bool}> */
    public static function booleanFailure(): array
    {
        return ['returns false' => [false], 'throws' => [true]];
    }

    #[DataProvider('booleanFailure')]
    public function testBeginFailurePreservesOriginalExceptionAndPoisonsConnection(bool $throws): void
    {
        $driverFailure = new \RuntimeException('begin driver failure');
        $connection = new MatrixConnection(
            beginResult: $throws ? null : false,
            beginFailure: $throws ? $driverFailure : null,
            beginBeforeFailure: $throws,
        );
        $gateway = $this->bind($connection);

        $callbackCalled = false;
        try {
            $gateway->transaction(static function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            self::fail('begin 失败必须报告事务结果未知');
        } catch (TransactionException $e) {
            self::assertSame('开始', $e->operation);
            self::assertNull($e->businessException);
            if ($throws) {
                self::assertSame($driverFailure, $e->getPrevious());
                self::assertTrue($connection->pdo->inTransaction(), '适配器可能已开始物理事务再抛异常');
                $connection->pdo->rollBack();
            } else {
                self::assertSame('数据库事务开始失败', $e->getPrevious()?->getMessage());
            }
        }

        self::assertFalse($callbackCalled, 'begin 失败时不得调用业务回调');
        self::assertSame(['begin'], $connection->transactionCalls);
        self::assertSame([
            ['begin', false, TransactionException::class],
        ], $this->transactionEvents($connection->events));
        $this->assertPoisonedConnection($connection, $gateway);
        self::assertSame(0, $this->observerWriteCount($connection));
        $this->assertDiscardCleansFailedSession($gateway, $connection);
    }

    public function testBusinessExceptionWithSuccessfulRollbackIsTransparent(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);
        $business = new \DomainException('business failure');

        try {
            $gateway->transaction(static function () use ($record, $business): void {
                $record->body = 'inside transaction';
                $record->save();
                throw $business;
            });
            self::fail('成功回滚必须透传业务异常');
        } catch (\DomainException $e) {
            self::assertSame($business, $e);
        }

        self::assertSame(['begin', 'rollback'], $connection->transactionCalls);
        self::assertSame('committed', $record->body);
        self::assertSame(1, $record->revision);
        self::assertSame('committed', $record->takeOriginal()['body']);
        self::assertSame(1, $record->takeOriginal()['revision']);
        self::assertSame([['committed', 1]], $this->rows($connection));
        self::assertSame([
            ['begin', true, null],
            ['rollback', true, null],
        ], $this->transactionEvents($connection->events));
        self::assertSame(0, $this->observerWriteCount($connection));
    }

    #[DataProvider('booleanFailure')]
    public function testBusinessExceptionWithFailedRollbackPoisonsConnectionAndRetainsEntity(bool $throws): void
    {
        $rollbackFailure = new \RuntimeException('rollback driver failure');
        $connection = new MatrixConnection(
            rollbackResult: false,
            rollbackFailure: $throws ? $rollbackFailure : null,
            performFailedRollback: false,
        );
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);
        $business = new \DomainException('business failure');

        try {
            $gateway->transaction(static function () use ($record, $business): void {
                $record->body = 'inside transaction';
                $record->save();
                throw $business;
            });
            self::fail('回滚失败必须取代业务异常');
        } catch (TransactionException $e) {
            self::assertSame('回滚', $e->operation);
            self::assertSame($business, $e->businessException);
            if ($throws) {
                self::assertSame($rollbackFailure, $e->getPrevious());
            } else {
                self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
                self::assertSame('数据库事务回滚失败', $e->getPrevious()?->getMessage());
            }
        }

        self::assertSame(['begin', 'rollback'], $connection->transactionCalls);
        $this->assertTransactionStateRetained($record);
        $this->assertPoisonedConnection($connection, $gateway);
        self::assertSame([
            ['begin', true, null],
            ['rollback', false, TransactionException::class],
        ], $this->transactionEvents($connection->events));
        self::assertSame(0, $this->observerWriteCount($connection));
        $this->assertDiscardCleansFailedSession($gateway, $connection);
    }

    /** @return array<string, array{bool, bool}> */
    public static function commitFailureMatrix(): array
    {
        $cases = [];
        foreach (['false' => false, 'throw' => true] as $commitLabel => $commitThrows) {
            foreach (['false' => false, 'throw' => true] as $rollbackLabel => $rollbackThrows) {
                $cases["commit $commitLabel / rollback $rollbackLabel"] = [$commitThrows, $rollbackThrows];
            }
        }

        return $cases;
    }

    #[DataProvider('commitFailureMatrix')]
    public function testCommitFailureHasPriorityOverRollbackAndNeverRestoresMemory(
        bool $commitThrows,
        bool $rollbackThrows,
    ): void {
        $commitFailure = new \RuntimeException('commit driver failure');
        $rollbackFailure = new \RuntimeException('secondary rollback driver failure');
        $connection = new MatrixConnection(
            commitResult: false,
            rollbackResult: false,
            commitFailure: $commitThrows ? $commitFailure : null,
            rollbackFailure: $rollbackThrows ? $rollbackFailure : null,
            performFailedRollback: false,
        );
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);

        try {
            $gateway->transaction(static function () use ($record): void {
                $record->body = 'inside transaction';
                $record->save();
            });
            self::fail('commit 返回 false 必须报告提交结果未知');
        } catch (TransactionException $e) {
            self::assertSame('提交', $e->operation);
            self::assertNull($e->businessException);
            if ($commitThrows) {
                self::assertSame($commitFailure, $e->getPrevious());
            } else {
                self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
                self::assertSame('数据库事务提交失败', $e->getPrevious()?->getMessage());
            }
            self::assertNotSame($rollbackFailure, $e->getPrevious());
        }

        self::assertSame(['begin', 'commit', 'rollback'], $connection->transactionCalls);
        $this->assertTransactionStateRetained($record);
        $this->assertPoisonedConnection($connection, $gateway);
        self::assertSame([
            ['begin', true, null],
            ['commit', false, \RuntimeException::class],
            ['rollback', false, TransactionException::class],
        ], $this->transactionEvents($connection->events));
        self::assertSame(0, $this->observerWriteCount($connection));
        $this->assertDiscardCleansFailedSession($gateway, $connection);
    }

    #[DataProvider('booleanFailure')]
    public function testCommitFailureWithSuccessfulRollbackStillPoisonsConnection(bool $throws): void
    {
        $commitFailure = new \RuntimeException('commit driver failure');
        $connection = new MatrixConnection(
            commitResult: false,
            commitFailure: $throws ? $commitFailure : null,
        );
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);

        try {
            $gateway->transaction(static function () use ($record): void {
                $record->body = 'inside transaction';
                $record->save();
            });
            self::fail('commit 失败必须报告未知结果');
        } catch (TransactionException $e) {
            self::assertSame('提交', $e->operation);
            self::assertNull($e->businessException);
            if ($throws) {
                self::assertSame($commitFailure, $e->getPrevious());
            } else {
                self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
                self::assertSame('数据库事务提交失败', $e->getPrevious()?->getMessage());
            }
        }

        self::assertSame(['begin', 'commit', 'rollback'], $connection->transactionCalls);
        $this->assertTransactionStateRetained($record);
        // 只有 SQL 包装器在失败前真正回滚了 PDO；不断言 false 回滚后的底层事务状态。
        self::assertFalse($connection->pdo->inTransaction());
        self::assertSame([['committed', 1]], $this->rows($connection));
        $this->assertPoisonedConnection($connection, $gateway);
        self::assertSame([
            ['begin', true, null],
            ['commit', false, \RuntimeException::class],
            ['rollback', true, null],
        ], $this->transactionEvents($connection->events));
        self::assertSame(0, $this->observerWriteCount($connection));
        $this->assertDiscardCleansFailedSession($gateway, $connection);
    }

    public function testCommittedInsertIsNotRolledBackToNewByLaterTransaction(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $record = new MatrixRecord();
        $record->body = 'committed in first transaction';
        $gateway->transaction(static function () use ($record): void {
            $record->save();
        });
        self::assertFalse($record->isNew());

        try {
            $gateway->transaction(static function () use ($record): void {
                $record->body = 'rollback second transaction';
                $record->save();
                throw new \DomainException('rollback committed insert');
            });
        } catch (\DomainException) {
        }

        self::assertFalse($record->isNew());
        self::assertSame('committed in first transaction', $record->body);
        self::assertSame(1, $record->revision);
        self::assertSame('committed in first transaction', $record->takeOriginal()['body']);
    }

    public function testInnerRollbackRestoresEntitiesFirstTouchedInsideSavepoint(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $other = $this->persistedRecord($gateway);
        $other->body = 'before outer transaction';
        $other->save();
        $new = new MatrixRecord();
        $new->body = 'inner only insert';
        $newId = null;

        $gateway->transaction(function () use ($gateway, $other, $new, &$newId): void {
            try {
                $gateway->transaction(static function () use ($other, $new, &$newId): void {
                    $other->body = 'inner only update';
                    $other->save();
                    $new->save();
                    $newId = $new->id;
                    throw new \DomainException('rollback new savepoint entities');
                });
            } catch (\DomainException) {
            }
            self::assertTrue($new->isNew());
            self::assertSame('before outer transaction', $other->body);
            self::assertSame('before outer transaction', $other->takeOriginal()['body']);
        });

        self::assertNotNull($newId);
        self::assertTrue($new->isNew());
        self::assertNull(MatrixRecord::find(['id' => $newId]));
        self::assertSame($other, MatrixRecord::find(['id' => $other->id]));
        self::assertSame(['before outer transaction', 2], $this->rows($connection)[0]);
    }

    public function testNestedSavepointBeginFailureRetainsOuterConnectionOwnership(): void
    {
        $connection = new SavepointBeginFailureConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        try {
            $gateway->transaction(static function () use ($gateway): void {
                try {
                    $gateway->transaction(static fn (): null => null);
                    self::fail('内层保存点开启必须失败');
                } catch (TransactionException $e) {
                    self::assertSame('开始', $e->operation);
                }
                $owners = (new \ReflectionProperty(Session::class, 'connectionContexts'))->getValue();
                self::assertSame(Session::context(), $owners[$gateway->connection()] ?? null);
                throw new \DomainException('rollback outer after savepoint failure');
            });
        } catch (\DomainException $e) {
            self::assertSame('rollback outer after savepoint failure', $e->getMessage());
        }
        self::assertSame(['begin', 'begin', 'rollback'], $connection->calls);
        $owners = (new \ReflectionProperty(Session::class, 'connectionContexts'))->getValue();
        self::assertFalse(isset($owners[$gateway->connection()]));
        Session::discard();
    }

    public function testNestedTransactionsCommitThroughSavepoints(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);

        $result = $gateway->transaction(function () use ($gateway): string {
            $gateway->executeRaw(
                'INSERT INTO matrix_record (body, revision) VALUES (:body, :revision)',
                ['body' => 'outer', 'revision' => 1],
            );
            $gateway->transaction(function () use ($gateway): void {
                $gateway->executeRaw(
                    'INSERT INTO matrix_record (body, revision) VALUES (:body, :revision)',
                    ['body' => 'inner', 'revision' => 1],
                );
                $gateway->transaction(function () use ($gateway): void {
                    $gateway->executeRaw(
                        'INSERT INTO matrix_record (body, revision) VALUES (:body, :revision)',
                        ['body' => 'innermost', 'revision' => 1],
                    );
                });
            });

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(['outer', 'inner', 'innermost'], array_column($this->rows($connection), 0));
        self::assertSame([
            'begin', 'begin', 'begin', 'commit', 'commit', 'commit',
        ], $connection->transactionCalls);
        self::assertSame([
            'SAVEPOINT sp_2',
            'SAVEPOINT sp_3',
            'RELEASE SAVEPOINT sp_3',
            'RELEASE SAVEPOINT sp_2',
        ], $connection->savepointSql());
        self::assertFalse($connection->pdo->inTransaction());
        self::assertTrue($connection->inner()->ping(), '提交后连接深度必须归零且仍可探测');
    }

    public function testInnerRollbackRestoresDatabaseEntityAndAllowsOuterCommit(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);
        $business = new \DomainException('rollback only inner');

        $gateway->transaction(function () use ($gateway, $record, $business): void {
            $record->body = 'outer write';
            $record->save();
            try {
                $gateway->transaction(function () use ($gateway, $record, $business): void {
                    $record->body = 'inner write';
                    $record->save();
                    $inner = new MatrixRecord();
                    $inner->body = 'inner insert';
                    $inner->save();
                    self::assertFalse($inner->isNew());
                    throw $business;
                });
                self::fail('内层业务异常必须在保存点回滚后透传');
            } catch (\DomainException $e) {
                self::assertSame($business, $e);
            }

            self::assertSame('outer write', $record->body);
            self::assertSame(2, $record->revision);
            self::assertSame('outer write', $record->takeOriginal()['body']);
            $gateway->executeRaw(
                'INSERT INTO matrix_record (body, revision) VALUES (:body, :revision)',
                ['body' => 'outer after rollback', 'revision' => 1],
            );
        });

        self::assertSame(['outer write', 'outer after rollback'], array_column($this->rows($connection), 0));
        self::assertSame([2, 1], array_map('intval', array_column($this->rows($connection), 1)));
        self::assertSame([
            'begin', 'begin', 'rollback', 'commit',
        ], $connection->transactionCalls);
        self::assertSame([
            'SAVEPOINT sp_2',
            'ROLLBACK TO SAVEPOINT sp_2',
            'RELEASE SAVEPOINT sp_2',
        ], $connection->savepointSql());
        self::assertFalse($connection->pdo->inTransaction());
        self::assertSame($record, MatrixRecord::find(['id' => $record->id]), '内层回滚不能清掉外层已提交缓存身份');
    }

    public function testUncaughtInnerBusinessExceptionRollsBackWholeChainAndPropagates(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);
        $business = new \DomainException('uncaught nested failure');

        try {
            $gateway->transaction(function () use ($gateway, $record, $business): void {
                $record->body = 'outer must roll back';
                $record->save();
                $gateway->transaction(static function () use ($business): void {
                    throw $business;
                });
            });
            self::fail('未捕获的内层异常必须传播到调用方');
        } catch (\DomainException $e) {
            self::assertSame($business, $e);
        }

        self::assertSame('committed', $record->body);
        self::assertSame(1, $record->revision);
        self::assertSame([['committed', 1]], $this->rows($connection));
        self::assertSame(['begin', 'begin', 'rollback', 'rollback'], $connection->transactionCalls);
        self::assertSame([
            'SAVEPOINT sp_2',
            'ROLLBACK TO SAVEPOINT sp_2',
            'RELEASE SAVEPOINT sp_2',
        ], $connection->savepointSql());
        self::assertFalse($connection->pdo->inTransaction());
        self::assertTrue($connection->ping());
    }

    public function testDiscardInsideNestedTransactionRollsBackEntireChainAndResetsDepth(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $record = $this->persistedRecord($gateway);

        try {
            $gateway->transaction(function () use ($gateway, $record): void {
                $record->body = 'discard outer';
                $record->save();
                $gateway->transaction(function () use ($gateway, $record): void {
                    $record->body = 'discard inner';
                    $record->save();
                    Session::discard();
                });
            });
            self::fail('整条链已被 discard 回滚后，外层不得提交');
        } catch (\LogicException $e) {
            self::assertStringContainsString('操作已回滚，不能继续提交', $e->getMessage());
        }

        self::assertSame(['begin', 'begin', 'forceRollback'], $connection->transactionCalls);
        self::assertSame(['SAVEPOINT sp_2'], $connection->savepointSql());
        self::assertSame([['committed', 1]], $this->rows($connection));
        self::assertSame('committed', $record->body);
        self::assertSame(1, $record->revision);
        self::assertFalse($connection->pdo->inTransaction());
        self::assertTrue($connection->ping(), 'discard 后旧连接必须已归零并可被新 Session 复用');
        Session::use($gateway);
        $gateway->transaction(function () use ($gateway): void {
            $gateway->executeRaw(
                'INSERT INTO matrix_record (body, revision) VALUES (:body, :revision)',
                ['body' => 'after discard', 'revision' => 1],
            );
        });
        self::assertSame(['committed', 'after discard'], array_column($this->rows($connection), 0));
    }

    public function testPingReportsConnectionLossAsFalse(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $pdo->expects(self::exactly(2))->method('query')->with('SELECT 1')->willReturnCallback(
            static fn () => throw new \PDOException('server has gone away'),
        );
        $connection = new PdoConnection($pdo);

        self::assertFalse($connection->ping());
        self::assertFalse($connection->ping());
    }

    /** 新连接、新 Gateway；所有 SQL 监听入口同时尝试真实写入并隔离重入。 */
    private function bind(MatrixConnection $connection): EntityGateway
    {
        $gateway = new EntityGateway($connection);
        $connection->subscribeReentrantObserver($gateway);
        Session::use($gateway);

        return $gateway;
    }

    private function persistedRecord(EntityGateway $gateway): MatrixRecord
    {
        $record = new MatrixRecord();
        $record->body = 'committed';
        $record->save();
        self::assertSame(1, $record->revision);
        self::assertSame($record, MatrixRecord::find(['id' => $record->id]));
        // 清缓存确保失败后的 find 必须执行 SQL 检查而不是命中缓存；保留原实体强引用。
        $gateway->clearCache(MatrixRecord::class);

        return $record;
    }

    private function assertTransactionStateRetained(MatrixRecord $record): void
    {
        self::assertSame('inside transaction', $record->body);
        self::assertSame(2, $record->revision);
        self::assertFalse($record->isNew());
        self::assertSame('inside transaction', $record->takeOriginal()['body']);
        self::assertSame(2, $record->takeOriginal()['revision']);
    }

    private function assertPoisonedConnection(MatrixConnection $connection, EntityGateway $gateway): void
    {
        $otherGateway = new EntityGateway($connection);
        $operations = [
            'selectRaw' => static fn (): array => $gateway->selectRaw('SELECT * FROM matrix_record'),
            'find' => static fn (): ?MatrixRecord => MatrixRecord::find(['id' => 1]),
            'Builder' => static fn (): ?MatrixRecord => MatrixRecord::query()->where('id', 1)->first(),
            'Session::use' => static fn (): Session => Session::use($gateway),
            'another Gateway' => static fn (): array => $otherGateway->selectRaw('SELECT * FROM matrix_record'),
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                self::fail($name . ' 必须拒绝复用结果未知的连接');
            } catch (\LogicException $e) {
                self::assertStringContainsString('禁止复用', $e->getMessage(), $name);
            }
        }
        self::assertSame([], array_filter(
            $connection->transactionCalls,
            static fn (string $call): bool => !in_array($call, ['begin', 'commit', 'rollback'], true),
        ));
    }

    public function testCommitListenerCannotDiscardBusinessSession(): void
    {
        $connection = new MatrixConnection();
        $gateway = $this->bind($connection);
        $gateway->subscribe(static function (Event $event): void {
            if ($event instanceof TransactionEvent && $event->phase === 'commit') {
                Session::discard();
            }
        });
        $record = $this->persistedRecord($gateway);
        $gateway->transaction(static function () use ($record): void {
            $record->body = 'committed transaction';
            $record->save();
        });
        self::assertSame($gateway, Session::get()->gateway);
        self::assertSame([['committed transaction', 2]], $this->rows($connection));
    }

    /** @return list<array{string, bool, class-string<\Throwable>|null}> */
    private function transactionEvents(array $events): array
    {
        return array_values(array_map(
            static fn (TransactionEvent $event): array => [$event->phase, $event->successful, $event->exceptionClass],
            array_filter($events, static fn (Event $event): bool => $event instanceof TransactionEvent),
        ));
    }

    private function observerWriteCount(MatrixConnection $connection): int
    {
        return (int) $connection->pdo->query("SELECT COUNT(*) FROM matrix_record WHERE body = 'observer side effect'")->fetchColumn();
    }

    /** @return list<array{string, int}> */
    private function rows(MatrixConnection $connection): array
    {
        return $connection->pdo->query('SELECT body, revision FROM matrix_record ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    }

    private function assertDiscardCleansFailedSession(EntityGateway $gateway, MatrixConnection $connection): void
    {
        $calls = $connection->transactionCalls;
        $events = $connection->events;
        Session::discard();
        self::assertSame($calls, $connection->transactionCalls, '失败连接清理不得再次提交或回滚');
        self::assertSame(count($events) + 1, count($connection->events));
        $event = $connection->events[array_key_last($connection->events)];
        self::assertInstanceOf(SessionEvent::class, $event);
        self::assertSame('discard', $event->action);
        self::assertTrue($event->successful);
        self::assertNull($event->exceptionClass);
    }
}

/** 记录保存点 SQL，真实执行仍完全交给 PDO SQLite 驱动。 */
final class MatrixPdo extends PDO
{
    /** @var list<string> */
    public array $loggedSql = [];

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (preg_match('/^(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/', $query) === 1) {
            $this->loggedSql[] = $query;
        }

        return parent::prepare($query, $options);
    }
}

/** 真实 PdoConnection 包装器：驱动操作照常执行，仅指定收尾动作被替换为 false 或原异常。 */
final class MatrixConnection implements ManagedConnection
{
    public readonly MatrixPdo $pdo;

    /** @var list<string> */
    public array $transactionCalls = [];

    /** @var list<string> */
    public array $savepointSql = [];

    /** @var list<Event> */
    public array $events = [];

    private readonly PdoConnection $inner;

    private int $beginAttempts = 0;

    public function __construct(
        private readonly ?bool $beginResult = true,
        private readonly ?bool $commitResult = true,
        private readonly ?bool $rollbackResult = true,
        private ?\Throwable $beginFailure = null,
        private readonly ?\Throwable $commitFailure = null,
        private readonly ?\Throwable $rollbackFailure = null,
        private readonly bool $performFailedRollback = true,
        private readonly bool $beginBeforeFailure = false,
    ) {
        $this->pdo = new MatrixPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE matrix_record (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->inner = new PdoConnection($this->pdo);
    }

    public function subscribeReentrantObserver(EntityGateway $gateway): void
    {
        $gateway->subscribe(function (Event $event) use ($gateway): void {
            $this->events[] = $event;
            try {
                $gateway->executeRaw(
                    "INSERT INTO matrix_record (body, revision) VALUES ('observer side effect', 1)",
                );
            } catch (\LogicException $e) {
                if (!str_contains($e->getMessage(), '观测监听器')
                    && !str_contains($e->getMessage(), '禁止复用')) {
                    throw $e;
                }
            }
            // EventDispatcher 也必须隔离监听器自身异常，不能让后续事件丢失。
            if ($event instanceof SqlEvent || $event instanceof TransactionEvent || $event instanceof SessionEvent) {
                throw new \Error('isolated observer failure');
            }
        });
    }

    public function execute(string $sql, array $params): int
    {
        if (preg_match('/^(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/', $sql) === 1) {
            $this->savepointSql[] = $sql;
        }

        return $this->inner->execute($sql, $params);
    }

    public function queryOne(string $sql, array $params): ?array
    {
        return $this->inner->queryOne($sql, $params);
    }

    public function queryAll(string $sql, array $params): array
    {
        return $this->inner->queryAll($sql, $params);
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }

    public function supportsReturning(): bool
    {
        return $this->inner->supportsReturning();
    }

    public function beginTransaction(): bool
    {
        $this->transactionCalls[] = 'begin';
        $attempt = ++$this->beginAttempts;
        if ($attempt === 1 && $this->beginBeforeFailure) {
            $this->inner->beginTransaction();
        }
        if ($attempt === 1 && $this->beginFailure !== null) {
            $failure = $this->beginFailure;
            $this->beginFailure = null;
            throw $failure;
        }
        if ($attempt === 1 && $this->beginResult === false) {
            return false;
        }

        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        $this->transactionCalls[] = 'commit';
        if ($this->commitFailure !== null || $this->commitResult === false) {
            if ($this->commitFailure !== null) {
                throw $this->commitFailure;
            }

            return false;
        }

        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        $this->transactionCalls[] = 'rollback';
        if (!$this->performFailedRollback && ($this->rollbackFailure !== null || $this->rollbackResult === false)) {
            if ($this->rollbackFailure !== null) {
                throw $this->rollbackFailure;
            }

            return false;
        }

        return $this->inner->rollback();
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function forceRollback(): bool
    {
        $this->transactionCalls[] = 'forceRollback';

        return $this->inner->forceRollback();
    }

    public function inner(): PdoConnection
    {
        return $this->inner;
    }

    /** @return list<string> */
    public function savepointSql(): array
    {
        return $this->pdo->loggedSql;
    }

}

final class MatrixRecord extends \Pixiaoka\Orm\Entity
{
    #[\Pixiaoka\Orm\Annotation\Column]
    #[\Pixiaoka\Orm\Annotation\PrimaryKey(generated: true)]
    public int $id;

    #[\Pixiaoka\Orm\Annotation\Column]
    public string $body;

    #[\Pixiaoka\Orm\Annotation\Column]
    #[\Pixiaoka\Orm\Annotation\Version]
    public int $revision;

    public static function table(): string
    {
        return 'matrix_record';
    }
}
