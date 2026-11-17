<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Clock;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\EventListener;
use Pixiaoka\Orm\Observability\SessionEvent;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Session;

final class OrmObservabilityTest extends TestCase
{
    private EntityGateway $gateway;

    private PDO $pdo;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE version_record (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testSqlSuccessRowsTimingAndSensitiveValueExclusion(): void
    {
        $events = [];
        $clock = new FixedObservabilityClock();
        $gateway = new EntityGateway(new PdoConnection($this->pdo), clock: $clock);
        $unsubscribe = $gateway->subscribe(function (Event $event) use (&$events, $clock): void {
            $events[] = $event;
            $clock->now += 1000;
        });
        Session::use($gateway);
        $secret = 'unique-token-not-for-logging';
        $gateway->executeRaw('INSERT INTO version_record (body, revision) VALUES (:body, :revision)', ['body' => $secret, 'revision' => 1]);
        self::assertSame(['body' => $secret], $gateway->selectOneRaw('SELECT body FROM version_record WHERE body = :body', ['body' => $secret]));
        self::assertCount(3, $events); // bind, INSERT, SELECT
        $sql = array_values(array_filter($events, static fn (Event $event): bool => $event instanceof SqlEvent));
        self::assertCount(2, $sql);
        self::assertSame(['INSERT', 'SELECT'], array_map(static fn (SqlEvent $e): string => $e->operation, $sql));
        self::assertSame(1, $sql[0]->affectedRows);
        self::assertSame(1, $sql[1]->resultRows);
        self::assertSame(2.5, $sql[0]->durationMs);
        self::assertSame(['body', 'revision'], $sql[0]->parameterNames);
        self::assertNull($sql[0]->model);
        self::assertTrue($sql[0]->successful);
        self::assertStringNotContainsString($secret, json_encode($events, JSON_THROW_ON_ERROR));
        self::assertSame(VersionRecord::class, $this->modelEvent($gateway));
        $unsubscribe();
        $unsubscribe();
        $count = count($events);
        $gateway->selectRaw('SELECT * FROM version_record');
        self::assertCount($count, $events);
    }

    public function testFailureIsObservedWithoutThrowableOrParameterValues(): void
    {
        $events = [];
        $failure = new \RuntimeException('driver failed sensitive text');
        $gateway = new EntityGateway(new FailingObservabilityConnection($failure));
        $gateway->subscribe(function (Event $event) use (&$events): void { $events[] = $event; });
        try {
            $gateway->selectRaw('SELECT secret FROM missing WHERE token = :token', ['token' => 'private-value']);
            self::fail('原驱动异常必须透传');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(SqlEvent::class, $event);
        self::assertFalse($event->successful);
        self::assertSame(\RuntimeException::class, $event->exceptionClass);
        self::assertSame(['token'], $event->parameterNames);
        self::assertNull($event->affectedRows);
        self::assertNull($event->resultRows);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-value', $serialized);
        self::assertStringNotContainsString('driver failed', $serialized);
    }

    public function testListenersCannotBreakCommitOrLaterListeners(): void
    {
        $events = [];
        $this->gateway->subscribe(static function (Event $event): void {
            if ($event instanceof TransactionEvent && $event->phase === 'commit') {
                throw new \Error('listener failed');
            }
        });
        $this->gateway->subscribe(new ObservabilityListener($events));
        Session::use($this->gateway);
        $record = new VersionRecord();
        $record->body = 'committed';
        VersionRecord::transaction(static fn () => $record->save());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM version_record')->fetchColumn());
        $phases = array_map(static fn (TransactionEvent $e): string => $e->phase, array_values(array_filter($events, static fn (Event $e): bool => $e instanceof TransactionEvent)));
        self::assertSame(['begin', 'commit'], $phases);
        self::assertGreaterThan(0, $events[0]->context->id);
        self::assertSame('root', $events[0]->context->type);
        self::assertSame('discard', $this->sessionAction());
    }

    public function testNestedAndRollbackTransactionsEmitOnlyPhysicalEvents(): void
    {
        $events = [];
        $this->gateway->subscribe(function (Event $event) use (&$events): void { $events[] = $event; });
        Session::use($this->gateway);
        try {
            VersionRecord::transaction(function (): void {
                VersionRecord::transaction(static fn (): null => null);
                throw new \DomainException('business rollback');
            });
        } catch (\DomainException $e) {
            self::assertSame('business rollback', $e->getMessage());
        }
        $phases = array_map(static fn (Event $e): string => $e->phase, array_values(array_filter($events, static fn (Event $e): bool => $e instanceof TransactionEvent)));
        self::assertSame(['begin', 'rollback'], $phases);
    }

    public function testFiberEventsCarryOwnContextAndScopeRestoresRoot(): void
    {
        $events = new ObservabilityEventCollector();
        Session::use($this->gateway);
        $this->gateway->subscribe($events);
        $fiber = new \Fiber(function () use ($events): void {
            $pdo = new PDO('sqlite::memory:');
            $gateway = new EntityGateway(new PdoConnection($pdo));
            $gateway->subscribe($events);
            Session::scope($gateway, function (): void {
                VersionRecord::transaction(static fn (): null => null);
            });
        });
        $fiber->start();
        $this->gateway->executeRaw('INSERT INTO version_record (body, revision) VALUES (:body, :revision)', ['body' => 'root', 'revision' => 1]);
        $types = array_map(static fn (Event $e): string => $e->context->type, $events->items);
        self::assertContains('fiber', $types);
        self::assertContains('root', $types);
        self::assertSame($this->gateway, Session::get()->gateway);
        $phases = array_map(static fn (Event $e): string => $e->phase, array_values(array_filter($events->items, static fn (Event $e): bool => $e instanceof TransactionEvent)));
        self::assertSame(['begin', 'commit'], $phases);
    }

    public function testBeginFailureAndListenerReentrancyAreReportedSafely(): void
    {
        $events = [];
        $gateway = new EntityGateway(new TransactionFailureConnection());
        $gateway->subscribe(function (Event $event) use (&$events, $gateway): void {
            $events[] = $event;
            $gateway->executeRaw('DELETE FROM recursive_observation');
        });
        Session::use($gateway);
        $gateway->connection()->fail = 'begin';
        try {
            $gateway->transaction(static fn (): null => null);
            self::fail('begin 返回 false 必须拒绝');
        } catch (TransactionException $e) {
            self::assertSame('开始', $e->operation);
            self::assertSame('数据库事务开始失败', $e->getPrevious()?->getMessage());
        }
        $transactions = array_values(array_filter($events, static fn (Event $e): bool => $e instanceof TransactionEvent));
        self::assertCount(1, $transactions);
        self::assertSame('begin', $transactions[0]->phase);
        self::assertFalse($transactions[0]->successful);
        self::assertSame(TransactionException::class, $transactions[0]->exceptionClass);
        self::assertSame([], array_values(array_filter($events, static fn (Event $e): bool => $e instanceof SqlEvent)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('禁止复用');
        $gateway->selectRaw('SELECT 1');
    }

    public function testCommitFailureEmitsFailureAndRollbackBeforePoisoningConnection(): void
    {
        $events = [];
        $gateway = new EntityGateway(new TransactionFailureConnection());
        $gateway->subscribe(function (Event $event) use (&$events, $gateway): void {
            $events[] = $event;
            $gateway->executeRaw('DELETE FROM recursive_observation');
        });
        Session::use($gateway);
        $gateway->connection()->fail = 'commit';
        try {
            $gateway->transaction(static fn (): null => null);
            self::fail('commit 返回 false 必须拒绝');
        } catch (TransactionException $e) {
            self::assertSame('提交', $e->operation);
            self::assertSame('数据库事务提交失败', $e->getPrevious()?->getMessage());
        }
        $transactions = array_values(array_filter($events, static fn (Event $e): bool => $e instanceof TransactionEvent));
        self::assertSame(['begin', 'commit', 'rollback'], array_map(static fn (TransactionEvent $e): string => $e->phase, $transactions));
        self::assertFalse($transactions[1]->successful);
        self::assertSame(\RuntimeException::class, $transactions[1]->exceptionClass);
        self::assertTrue($transactions[2]->successful);
        self::assertNull($transactions[2]->exceptionClass);
        self::assertSame([], array_values(array_filter($events, static fn (Event $e): bool => $e instanceof SqlEvent)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('禁止复用');
        $gateway->selectRaw('SELECT 1');
    }

    public function testRollbackFailurePoisonsConnectionAndPreservesInfrastructureFailure(): void
    {
        $connection = new FailingRollbackConnection(new PdoConnection($this->pdo));
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $connection->failNextRollback = true;
        $business = new \DomainException('business failure');
        try {
            $gateway->transaction(function () use ($record, $business): void {
                $record->body = 'transient';
                $record->save();
                throw $business;
            });
            self::fail('回滚失败必须优先于业务异常');
        } catch (TransactionException $e) {
            self::assertSame('回滚', $e->operation);
            self::assertSame($business, $e->businessException);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame('transient', $record->body);
        self::assertSame(2, $record->revision);
        self::assertSame(2, $record->takeOriginal()['revision']);
        foreach ([
            static fn (): array => $gateway->selectRaw('SELECT * FROM version_record'),
            static fn (): ?VersionRecord => VersionRecord::find(['id' => $record->id]),
            static fn () => Session::use($gateway),
        ] as $operation) {
            try {
                $operation();
                self::fail('未知事务状态禁止重新读取或绑定');
            } catch (\LogicException $e) {
                self::assertStringContainsString('禁止复用', $e->getMessage());
            }
        }
        self::assertSame('transient', $this->pdo->query('SELECT body FROM version_record')->fetchColumn());
        $this->pdo->rollBack();
    }

    public function testDiscardRollbackFailureIsUnsuccessfulAndDoesNotMaskCallback(): void
    {
        $events = [];
        $gateway = new EntityGateway(new TransactionFailureConnection());
        $gateway->subscribe(function (Event $event) use (&$events): void { $events[] = $event; });
        Session::use($gateway);
        $scope = Session::scope($gateway);
        try {
            $gateway->transaction(static function (): void { Session::discard(); });
            self::fail('Session 清理后不能继续提交');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Session 已被清理', $e->getMessage());
        }
        $scope->close();
        self::assertInstanceOf(SessionEvent::class, $events[array_key_last($events)]);
        $events = [];
        Session::use($gateway);
        $gateway->connection()->fail = 'rollback';
        $scope = Session::scope($gateway);
        try {
            $gateway->transaction(static function (): void { Session::discard(); });
            self::fail('回滚失败必须透传');
        } catch (TransactionException $e) {
            self::assertSame('回滚', $e->operation);
            self::assertNull($e->businessException);
            self::assertSame('数据库事务回滚失败', $e->getPrevious()?->getMessage());
        } finally {
            $scope->close();
        }
        $transactions = array_values(array_filter($events, static fn (Event $e): bool => $e instanceof TransactionEvent));
        $lastTransaction = $transactions[array_key_last($transactions)];
        self::assertSame('rollback', $lastTransaction->phase);
        self::assertFalse($lastTransaction->successful);
        $sessions = array_values(array_filter($events, static fn (Event $e): bool => $e instanceof SessionEvent && $e->action === 'discard'));
        self::assertNotSame([], $sessions);
        self::assertFalse($sessions[array_key_last($sessions)]->successful);
    }

    public function testDiscardRollbackFailureEventRetainsFailedConnectionUntilDispatchFinishes(): void
    {
        $attempts = 0;
        $gateway = new EntityGateway(new FailingRollbackConnection(new PdoConnection($this->pdo)));
        $gateway->subscribe(function (Event $event) use (&$attempts): void {
            if (!$event instanceof TransactionEvent || $event->successful) {
                return;
            }
            $attempts++;
            try {
                VersionRecord::query()->where('id', 1)->first();
                self::fail('失败连接在 discard 事件内也必须保持禁止复用');
            } catch (\LogicException $e) {
                self::assertStringContainsString('禁止复用', $e->getMessage());
            }
        });
        Session::use($gateway);
        $gateway->connection()->failNextRollback = true;
        try {
            VersionRecord::transaction(static function (): void { Session::discard(); });
            self::fail('discard 回滚失败必须报告');
        } catch (TransactionException $e) {
            self::assertSame('回滚', $e->operation);
        }
        self::assertSame(1, $attempts);
        try {
            Session::get();
            self::fail('失败 Session 必须已解绑');
        } catch (\LogicException $e) {
            self::assertStringContainsString('还没有', $e->getMessage());
        }
        $replacement = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        Session::use($replacement);
        self::assertSame($replacement, Session::get()->gateway);
    }

    public function testCommitFailureNeverRestoresMemoryAndCannotBeRetriedOnTheSameConnection(): void
    {
        $connection = new TransactionFailureConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $record = VersionRecord::query()->where('id', 1)->first();
        self::assertNotNull($record);
        $record->body = 'uncommitted';
        $connection->fail = 'commit';
        try {
            $gateway->transaction(static fn () => $record->save());
            self::fail('提交失败必须报告未知结果');
        } catch (TransactionException $e) {
            self::assertSame('提交', $e->operation);
            self::assertNull($e->businessException);
            self::assertSame('数据库事务提交失败', $e->getPrevious()?->getMessage());
        }
        self::assertSame('uncommitted', $record->body);
        self::assertSame(2, $record->revision);
        try {
            $gateway->transaction(static fn (): null => null);
            self::fail('提交结果未知后不能盲目重试');
        } catch (\LogicException $e) {
            self::assertStringContainsString('禁止复用', $e->getMessage());
        }
        $events = [];
        $gateway->subscribe(function (Event $event) use (&$events): void { $events[] = $event; });
        Session::discard();
        self::assertInstanceOf(SessionEvent::class, $events[array_key_last($events)]);
        $this->assertTrue($events[array_key_last($events)]->successful);
    }

    public function testListenersCannotWriteOrDiscardDuringSqlOrCommitEvents(): void
    {
        $attempts = 0;
        $this->gateway->subscribe(function (Event $event) use (&$attempts): void {
            if (!$event instanceof SqlEvent && !($event instanceof TransactionEvent && $event->phase === 'commit')) {
                return;
            }
            $attempts++;
            try {
                $this->gateway->executeRaw('INSERT INTO version_record (body, revision) VALUES (:body, 1)', ['body' => 'observer side effect']);
                self::fail('监听器不能重入写库');
            } catch (\LogicException $e) {
                self::assertStringContainsString('观测监听器', $e->getMessage());
            }
            if ($event instanceof TransactionEvent) {
                Session::discard();
            }
        });
        Session::use($this->gateway);
        $record = new VersionRecord();
        $record->body = 'committed';
        VersionRecord::transaction(static fn () => $record->save());
        self::assertGreaterThanOrEqual(2, $attempts);
        self::assertFalse($record->isNew());
        self::assertSame('committed', $record->body);
        self::assertSame($this->gateway, Session::get()->gateway);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM version_record WHERE body = 'observer side effect'")->fetchColumn());
        self::assertNotNull(VersionRecord::find(['id' => $record->id]));
        self::assertSame($record, VersionRecord::find(['id' => $record->id]));
    }

    public function testDiscardRemainsUsableWhenListenerFails(): void
    {
        $this->gateway->subscribe(static function (Event $event): void {
            if ($event instanceof SessionEvent && $event->action === 'discard') {
                Session::discard();
            }
        });
        Session::use($this->gateway);
        Session::discard();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('还没有');
        Session::get();
    }

    public function testIndependentConnectionMayBeUsedByObserver(): void
    {
        $observerPdo = new PDO('sqlite::memory:');
        $observerPdo->exec('CREATE TABLE observation (body TEXT NOT NULL)');
        $observer = new EntityGateway(new PdoConnection($observerPdo));
        $this->gateway->subscribe(static function (Event $event) use ($observer): void {
            if ($event instanceof SqlEvent) {
                $observer->executeRaw('INSERT INTO observation (body) VALUES (:body)', ['body' => 'independent']);
            }
        });
        Session::use($this->gateway);
        $record = new VersionRecord();
        $record->body = 'business';
        $record->save();
        self::assertSame(1, (int) $observerPdo->query('SELECT COUNT(*) FROM observation')->fetchColumn());
        self::assertSame($this->gateway, Session::get()->gateway);
    }

    public function testListenersCannotSwitchOrScopeSessionDuringAnyEvent(): void
    {
        $observerPdo = new PDO('sqlite::memory:');
        $observerPdo->exec('CREATE TABLE observation (body TEXT NOT NULL)');
        $observer = new EntityGateway(new PdoConnection($observerPdo));
        $events = [];
        $this->gateway->subscribe(static function (Event $event) use ($observer, &$events): void {
            $events[] = $event;
            foreach ([
                static fn () => Session::use($observer),
                static fn () => Session::scope($observer),
                static fn () => Session::discard(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('所有事件都必须禁止改变当前 Session 生命周期');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('观测监听器', $e->getMessage());
                }
            }
        });
        Session::use($this->gateway);
        $record = new VersionRecord();
        $record->body = 'committed';
        VersionRecord::transaction(static fn () => $record->save());
        $this->gateway->executeRaw('INSERT INTO version_record (body, revision) VALUES (:body, 1)', ['body' => 'second']);
        try {
            $this->gateway->executeRaw('INSERT INTO missing_table (body) VALUES (:body)', ['body' => 'failure']);
            self::fail('失败 SQL 应透传驱动异常');
        } catch (\PDOException) {
        }
        self::assertSame($this->gateway, Session::get()->gateway);
        self::assertFalse($record->isNew());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM version_record')->fetchColumn());
        self::assertSame([
            'bind', 'begin', 'INSERT', 'commit', 'INSERT', 'INSERT',
        ], array_map($this->eventName(...), $events));
    }

    public function testRollbackFailureListenerCannotSwitchSession(): void
    {
        $observer = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        $events = [];
        $gateway = new EntityGateway(new TransactionFailureConnection());
        $gateway->subscribe(static function (Event $event) use ($observer, &$events): void {
            if (!$event instanceof TransactionEvent) {
                return;
            }
            $events[] = $event;
            foreach ([
                static fn () => Session::use($observer),
                static fn () => Session::scope($observer),
                static fn () => Session::discard(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('回滚失败事件不能换绑或清理 Session');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('观测监听器', $e->getMessage());
                }
            }
        });
        Session::use($gateway);
        $gateway->connection()->fail = 'rollback';
        try {
            $gateway->transaction(static function (): void {
                throw new \DomainException('business rollback');
            });
            self::fail('回滚失败必须优先报告');
        } catch (TransactionException $e) {
            self::assertSame('回滚', $e->operation);
            self::assertSame('business rollback', $e->businessException?->getMessage());
        }
        $events = array_values(array_filter($events, static fn (TransactionEvent $event): bool => !$event->successful));
        self::assertSame(['rollback'], array_map(static fn (TransactionEvent $event): string => $event->phase, $events));
        self::assertSame($gateway, Session::get()->gateway);
        Session::discard();
    }

    public function testBeginAndCommitFailureListenersCannotSwitchSession(): void
    {
        foreach (['begin', 'commit'] as $phase) {
            $observer = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
            $events = [];
            $gateway = new EntityGateway(new TransactionFailureConnection());
            $gateway->subscribe(static function (Event $event) use ($observer, &$events): void {
                if (!$event instanceof TransactionEvent || $event->successful) {
                    return;
                }
                $events[] = $event;
                foreach ([
                    static fn () => Session::use($observer),
                    static fn () => Session::scope($observer),
                    static fn () => Session::discard(),
                ] as $operation) {
                    try {
                        $operation();
                        self::fail('开始或提交失败事件不能换绑或清理 Session');
                    } catch (\LogicException $e) {
                        self::assertStringContainsString('观测监听器', $e->getMessage());
                    }
                }
            });
            Session::use($gateway);
            $gateway->connection()->fail = $phase;
            try {
                $gateway->transaction(static fn (): null => null);
                self::fail('事务边界失败必须报告');
            } catch (TransactionException $e) {
                self::assertSame($phase === 'begin' ? '开始' : '提交', $e->operation);
            }
            self::assertSame([$phase], array_map(static fn (TransactionEvent $event): string => $event->phase, $events));
            self::assertSame($gateway, Session::get()->gateway);
            Session::discard();
        }
    }

    public function testNestedIndependentEventsRetainSessionProtection(): void
    {
        $observerPdo = new PDO('sqlite::memory:');
        $observerPdo->exec('CREATE TABLE observation (body TEXT NOT NULL)');
        $observer = new EntityGateway(new PdoConnection($observerPdo));
        $events = [];
        $this->gateway->subscribe(static function (Event $event) use ($observer): void {
            if ($event instanceof SqlEvent && $event->sql === 'SELECT 1') {
                $observer->executeRaw('INSERT INTO observation (body) VALUES (:body)', ['body' => 'nested']);
            }
        });
        $observer->subscribe(static function (Event $event) use (&$events): void {
            $events[] = $event;
            foreach ([
                static fn (): EntityGateway => Session::get()->gateway,
                static fn () => Session::discard(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('嵌套观测不能换绑或清理业务 Session');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('观测监听器', $e->getMessage());
                }
            }
        });
        Session::use($this->gateway);
        $this->gateway->selectRaw('SELECT 1');
        self::assertCount(1, $events);
        self::assertSame($this->gateway, Session::get()->gateway);
        self::assertSame(1, (int) $observerPdo->query('SELECT COUNT(*) FROM observation')->fetchColumn());
    }

    public function testDiscardListenerCannotSwitchSessionAndFailedSessionCanBeDetached(): void
    {
        $observer = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        $attempts = 0;
        $this->gateway->subscribe(static function (Event $event) use ($observer, &$attempts): void {
            if (!$event instanceof SessionEvent || $event->action !== 'discard') {
                return;
            }
            $attempts++;
            foreach ([
                static fn () => Session::use($observer),
                static fn () => Session::scope($observer),
                static fn () => Session::discard(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('discard 完成事件不能重新绑定 Session');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('观测监听器', $e->getMessage());
                }
            }
        });
        Session::use($this->gateway);
        Session::discard();
        self::assertSame(1, $attempts);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('还没有');
        Session::get();
    }

    public function testNestedObserverEventsMayWriteWithoutChangingBusinessSession(): void
    {
        $observerPdo = new PDO('sqlite::memory:');
        $observerPdo->exec('CREATE TABLE observation (body TEXT NOT NULL)');
        $observer = new EntityGateway(new PdoConnection($observerPdo));
        $secondObserverPdo = new PDO('sqlite::memory:');
        $secondObserverPdo->exec('CREATE TABLE observation (body TEXT NOT NULL)');
        $secondObserver = new EntityGateway(new PdoConnection($secondObserverPdo));
        $observer->subscribe(static function (Event $event) use ($secondObserver): void {
            if ($event instanceof SqlEvent) {
                $secondObserver->executeRaw('INSERT INTO observation (body) VALUES (:body)', ['body' => 'nested']);
            }
        });
        $this->gateway->subscribe(static function (Event $event) use ($observer): void {
            if ($event instanceof SqlEvent) {
                $observer->executeRaw('INSERT INTO observation (body) VALUES (:body)', ['body' => 'outer']);
            }
        });
        Session::use($this->gateway);
        $record = new VersionRecord();
        $record->body = 'business';
        $record->save();
        self::assertSame(1, (int) $observerPdo->query('SELECT COUNT(*) FROM observation')->fetchColumn());
        self::assertSame(1, (int) $secondObserverPdo->query('SELECT COUNT(*) FROM observation')->fetchColumn());
        self::assertSame($this->gateway, Session::get()->gateway);
        self::assertFalse($record->isNew());
    }

    private function eventName(Event $event): string
    {
        return match (true) {
            $event instanceof SqlEvent => $event->operation,
            $event instanceof TransactionEvent => $event->phase,
            $event instanceof SessionEvent => $event->action,
        };
    }

    /** @param list<Event> $events */
    private function modelEvent(EntityGateway $gateway): string
    {
        $model = null;
        $gateway->subscribe(static function (Event $event) use (&$model): void {
            if ($event instanceof SqlEvent) {
                $model = $event->model;
            }
        });
        $record = new VersionRecord();
        $record->body = 'model';
        $record->save();
        return $model;
    }

    private function sessionAction(): string
    {
        $event = null;
        $this->gateway->subscribe(static function (Event $received) use (&$event): void { $event = $received; });
        Session::discard();
        self::assertInstanceOf(SessionEvent::class, $event);
        return $event->action;
    }
}

final class FixedObservabilityClock implements Clock
{
    public float $now = 100.0;
    public function now(): float
    {
        $value = $this->now;
        $this->now += 2.5;
        return $value;
    }
}

final class ObservabilityEventCollector implements EventListener
{
    /** @var list<Event> */
    public array $items = [];
    public function onEvent(Event $event): void { $this->items[] = $event; }
}

final class ObservabilityListener implements EventListener
{
    public function __construct(private array &$events) {}
    public function onEvent(Event $event): void { $this->events[] = $event; }
}

final class TransactionFailureConnection implements Connection
{
    public ?string $fail = null;
    public function execute(string $sql, array $params): int { return 1; }
    public function queryOne(string $sql, array $params): ?array { return ['id' => 1, 'body' => 'committed', 'revision' => 1]; }
    public function queryAll(string $sql, array $params): array
    {
        return [['id' => 1, 'body' => 'committed', 'revision' => 1]];
    }
    public function lastInsertId(): string { return '1'; }
    public function quoteIdentifier(string $identifier): string { return '"' . $identifier . '"'; }
    public function supportsReturning(): bool { return false; }
    public function beginTransaction(): bool { return $this->operation('begin'); }
    public function commit(): bool { return $this->operation('commit'); }
    public function rollback(): bool { return $this->operation('rollback'); }
    private function operation(string $operation): bool
    {
        if ($this->fail !== $operation) { return true; }
        return false;
    }
}

final class FailingRollbackConnection implements Connection
{
    public bool $failNextRollback = false;
    public function __construct(private readonly Connection $inner) {}
    public function execute(string $sql, array $params): int { return $this->inner->execute($sql, $params); }
    public function queryOne(string $sql, array $params): ?array { return $this->inner->queryOne($sql, $params); }
    public function queryAll(string $sql, array $params): array { return $this->inner->queryAll($sql, $params); }
    public function lastInsertId(): string { return $this->inner->lastInsertId(); }
    public function quoteIdentifier(string $identifier): string { return $this->inner->quoteIdentifier($identifier); }
    public function supportsReturning(): bool { return $this->inner->supportsReturning(); }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollback(): bool
    {
        if ($this->failNextRollback) {
            $this->failNextRollback = false;
            return false;
        }
        return $this->inner->rollback();
    }
}

final class FailingObservabilityConnection implements Connection
{
    public function __construct(private readonly \Throwable $failure) {}
    public function execute(string $sql, array $params): int { throw $this->failure; }
    public function queryOne(string $sql, array $params): ?array { throw $this->failure; }
    public function queryAll(string $sql, array $params): array { throw $this->failure; }
    public function lastInsertId(): string { return ''; }
    public function quoteIdentifier(string $identifier): string { return '"' . $identifier . '"'; }
    public function supportsReturning(): bool { return true; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollback(): bool { return true; }
}
