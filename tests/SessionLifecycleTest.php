<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;
use Pixiaoka\Orm\SessionScope;
use Pixiaoka\Orm\Context;

final class SessionLifecycleTest extends TestCase
{
    private EntityGateway $rootGateway;

    protected function setUp(): void
    {
        Session::discard();
        $this->rootGateway = self::gateway(':memory:');
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testScopeReleasesGatewayAndPreservesOuterOwnershipAcrossFibers(): void
    {
        $scoped = self::gateway(':memory:');
        foreach ([static fn (): null => null, static function (): void { throw new \DomainException('scope failure'); }] as $operation) {
            try {
                Session::scope($scoped, $operation);
            } catch (\DomainException $e) {
                self::assertSame('scope failure', $e->getMessage());
            }
            $fiber = new \Fiber(static function () use ($scoped): void {
                Session::use($scoped);
                Session::discard();
            });
            $fiber->start();
            self::assertTrue($fiber->isTerminated(), '正常和异常作用域都必须完全释放新网关');
        }

        Session::use($this->rootGateway);
        $inner = self::gateway(':memory:');
        $probe = new \Fiber(function (): void {
            try {
                Session::use($this->rootGateway);
                self::fail('外层网关在作用域期间及恢复后均须保持独占');
            } catch (\LogicException $e) {
                self::assertStringContainsString('another active Fiber/coroutine', $e->getMessage());
            }
        });
        Session::scope($inner, function () use ($inner, $probe): void {
            self::assertSame($inner, Session::get()->gateway);
            $probe->start();
            self::assertTrue($probe->isTerminated());
        });
        self::assertSame($this->rootGateway, Session::get()->gateway);
        $probe = new \Fiber(function (): void {
            try {
                Session::use($this->rootGateway);
                self::fail('恢复后的外层网关必须重新保持引用');
            } catch (\LogicException $e) {
                self::assertStringContainsString('another active Fiber/coroutine', $e->getMessage());
            }
        });
        $probe->start();
        Session::discard();
        $fiber = new \Fiber(function (): void {
            Session::use($this->rootGateway);
            Session::discard();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
    }

    public function testSameGatewayNestedScopesBalanceReferences(): void
    {
        Session::use($this->rootGateway);
        Session::scope($this->rootGateway, function (): void {
            Session::scope($this->rootGateway, static fn (): null => null);
            self::assertSame($this->rootGateway, Session::get()->gateway);
        });
        $probe = new \Fiber(function (): void {
            try {
                Session::use($this->rootGateway);
                self::fail('同网关嵌套作用域退出后，外层会话仍须保持独占');
            } catch (\LogicException $e) {
                self::assertStringContainsString('another active Fiber/coroutine', $e->getMessage());
            }
        });
        $probe->start();
        self::assertTrue($probe->isTerminated());
        Session::discard();
        $fiber = new \Fiber(function (): void {
            Session::use($this->rootGateway);
            Session::discard();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated(), '外层 discard 后网关必须完全释放');
    }

    public function testFailedScopeInitializationDoesNotLeakNewGateway(): void
    {
        Session::use($this->rootGateway);
        $replacement = self::gateway(':memory:');
        try {
            LifecycleRecord::transaction(static function () use ($replacement): void {
                Session::scope($replacement, static fn (): null => null);
            });
        } catch (\LogicException) {
        }
        $fiber = new \Fiber(static function () use ($replacement): void {
            Session::use($replacement);
            Session::discard();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        Session::discard();
    }

    public function testStaticEntityQueryRequiresSessionInCurrentFiber(): void
    {
        Session::use($this->rootGateway);
        $fiber = new \Fiber(static fn () => LifecycleRecord::query());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('in the current Fiber/coroutine');
        $fiber->start();
    }

    public function testExplicitFiberAndRootSessionsRemainIndependent(): void
    {
        $rootSession = Session::use($this->rootGateway);
        $rootNamed = Session::use(self::gateway(':memory:'), 'readonly');
        $gateway = self::gateway(':memory:');
        $fiber = new \Fiber(static function () use ($gateway): void {
            $session = Session::use($gateway);
            $named = Session::use($gateway, 'readonly');
            \Fiber::suspend();
            self::assertSame($session, Session::get());
            self::assertSame($named, Session::get('readonly'));
            Session::discard();
        });
        $fiber->start();
        self::assertSame($rootSession, Session::get());
        self::assertSame($rootNamed, Session::get('readonly'));
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertSame($rootSession, Session::get());
        self::assertSame($rootNamed, Session::get('readonly'));
    }

    public function testScopeRestoresPreviousSessionAndReturnsCallbackValue(): void
    {
        Session::use($this->rootGateway);
        $gateway = self::gateway(':memory:');
        $result = Session::scope($gateway, static function () use ($gateway): string {
            self::assertSame($gateway, Session::get()->gateway);

            return 'result';
        });
        self::assertSame('result', $result);
        self::assertSame($this->rootGateway, Session::get()->gateway);
    }

    public function testNestedScopeRestoresCorrectSessionAfterException(): void
    {
        Session::use($this->rootGateway);
        $first = self::gateway(':memory:');
        $second = self::gateway(':memory:');
        try {
            Session::scope($first, function () use ($first, $second): void {
                Session::scope($second, static function () use ($second): void {
                    self::assertSame($second, Session::get()->gateway);
                    throw new \RuntimeException('scope failure');
                });
            });
            self::fail('应透传业务异常');
        } catch (\RuntimeException $e) {
            self::assertSame('scope failure', $e->getMessage());
        }
        self::assertSame($this->rootGateway, Session::get()->gateway);

        Session::scope($first, static function () use ($first, $second): void {
            Session::scope($second, static fn (): null => null);
            self::assertSame($first, Session::get()->gateway);
        });
        self::assertSame($this->rootGateway, Session::get()->gateway);
    }

    public function testManualScopeDestructorAndIdempotentClose(): void
    {
        $scope = Session::scope($this->rootGateway);
        self::assertInstanceOf(SessionScope::class, $scope);
        self::assertSame($this->rootGateway, Session::get()->gateway);
        $scope->close();
        $scope->close();
        $this->expectException(\LogicException::class);
        Session::get();
    }

    public function testManualScopeDestructorRestoresPreviousSession(): void
    {
        Session::use($this->rootGateway);
        $scope = Session::scope(self::gateway(':memory:'));
        unset($scope);
        self::assertSame($this->rootGateway, Session::get()->gateway);
    }

    public function testScopeCannotCloseInAnotherFiber(): void
    {
        $scope = Session::scope($this->rootGateway);
        $fiber = new \Fiber(static fn () => $scope->close());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('where it was created');
        $fiber->start();
    }

    public function testDiscardRollsBackUnfinishedOuterTransaction(): void
    {
        $path = self::databasePath();
        $gateway = self::gateway($path);
        $connection = new RecordingLifecycleConnection($gateway->connection());
        $recordingGateway = new EntityGateway($connection);
        Session::use($recordingGateway);
        try {
            LifecycleRecord::transaction(static function (): void {
                $row = new LifecycleRecord();
                $row->id = 1;
                $row->name = 'rollback';
                $row->save();
                Session::discard();
            });
            self::fail('清理事务 Session 后必须拒绝提交');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot be committed', $e->getMessage());
        }
        self::assertSame(1, $connection->rollbacks);
        self::assertSame([], self::databaseRows($path));
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testDiscardRollsBackTransactionAfterCallbackThrowsDuringFiberExit(): void
    {
        $path = self::databasePath();
        $gateway = self::gateway($path);
        $fiber = new \Fiber(static function () use ($gateway): void {
            Session::use($gateway);
            LifecycleRecord::transaction(static function (): void {
                $row = new LifecycleRecord();
                $row->id = 2;
                $row->name = 'unfinished';
                $row->save();
                \Fiber::suspend();
            });
        });
        $fiber->start();
        unset($fiber);
        gc_collect_cycles();

        self::assertSame([], self::databaseRows($path));
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testScopeCloseRollsBackTransactionAndRestoresPreviousSession(): void
    {
        $path = self::databasePath();
        $gateway = self::gateway($path);
        $connection = new RecordingLifecycleConnection($gateway->connection());
        Session::use($this->rootGateway);
        $scope = Session::scope(new EntityGateway($connection));
        try {
            LifecycleRecord::transaction(static function () use ($scope): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 3, 'name' => 'scope-rollback']);
                $scope->close();
            });
            self::fail('作用域清理事务后必须拒绝提交');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot be committed', $e->getMessage());
        }
        self::assertSame($this->rootGateway, Session::get()->gateway);
        self::assertSame(1, $connection->rollbacks);
        self::assertSame([], self::databaseRows($path));
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testScopeRestoresPreviousSessionWhenRollbackFails(): void
    {
        $gateway = self::gateway(':memory:');
        $connection = new RecordingLifecycleConnection($gateway->connection());
        $connection->failRollback = true;
        Session::use($this->rootGateway);
        $scope = Session::scope(new EntityGateway($connection));
        try {
            LifecycleRecord::transaction(static function () use ($scope): void {
                $scope->close();
            });
            self::fail('必须优先报告事务回滚结果未知');
        } catch (TransactionException $e) {
            self::assertSame('rollback', $e->operation);
            self::assertNull($e->businessException);
            self::assertSame('rollback failure', $e->getPrevious()?->getMessage());
        }
        self::assertSame($this->rootGateway, Session::get()->gateway);
        self::assertSame(1, $connection->rollbacks);
    }

    public function testUseRejectsReplacingActiveTransactionWithoutTouchingConnection(): void
    {
        $path = self::databasePath();
        $gateway = self::gateway($path);
        $connection = new RecordingLifecycleConnection($gateway->connection());
        $recordingGateway = new EntityGateway($connection);
        Session::use($recordingGateway);
        try {
            LifecycleRecord::transaction(function () use ($path, $connection, $recordingGateway): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 4, 'name' => 'preserve-session']);
                $replacement = self::gateway($path);
                try {
                    Session::use($replacement);
                    self::fail('隐式换绑不得代替调用方回滚未结束事务');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('before rebinding', $e->getMessage());
                }
                self::assertSame(0, $connection->rollbacks);
                self::assertSame($recordingGateway, Session::get()->gateway);
                throw new \DomainException('business rollback');
            });
            self::fail('业务异常应在确认回滚后透传');
        } catch (\DomainException $e) {
            self::assertSame('business rollback', $e->getMessage());
        }
        self::assertSame(1, $connection->rollbacks);
        self::assertSame([], self::databaseRows($path));
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testScopeRejectsReplacingActiveTransactionAndPreservesSession(): void
    {
        Session::use($this->rootGateway);
        try {
            LifecycleRecord::transaction(function (): void {
                $scope = Session::scope(self::gateway(':memory:'));
                self::fail('作用域创建不得静默回滚旧 Session 的事务');
            });
            self::fail('换绑拒绝应在事务内保留业务异常语义');
        } catch (\LogicException $e) {
            self::assertStringContainsString('before rebinding', $e->getMessage());
        }
        self::assertSame($this->rootGateway, Session::get()->gateway);
    }

    public function testSameGatewayUseAndScopeCannotDiscardActiveTransaction(): void
    {
        Session::use($this->rootGateway);
        foreach (['use', 'scope'] as $operation) {
            try {
                LifecycleRecord::transaction(function () use ($operation): void {
                    if ($operation === 'use') {
                        Session::use($this->rootGateway);
                    } else {
                        Session::scope($this->rootGateway, static fn (): null => null);
                    }
                });
                self::fail('同 Gateway 的作用域或重新绑定也不能回滚当前事务');
            } catch (\LogicException $e) {
                self::assertStringContainsString('before rebinding', $e->getMessage());
            }
            self::assertSame($this->rootGateway, Session::get()->gateway);
        }
    }

    public function testUseRejectsReplacingActiveTransactionEvenWhenRollbackWouldFail(): void
    {
        $path = self::databasePath();
        $gateway = self::gateway($path);
        $connection = new RecordingLifecycleConnection($gateway->connection());
        $recordingGateway = new EntityGateway($connection);
        Session::use($recordingGateway);
        try {
            LifecycleRecord::transaction(function () use ($path, $connection, $recordingGateway): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 5, 'name' => 'no-early-rollback']);
                $connection->failRollback = true;
                try {
                    Session::use(self::gateway($path));
                    self::fail('换绑不得吞掉回滚失败或伪装业务异常');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('before rebinding', $e->getMessage());
                    self::assertNotInstanceOf(TransactionException::class, $e);
                }
                self::assertSame(0, $connection->rollbacks);
                self::assertSame($recordingGateway, Session::get()->gateway);
                throw new \DomainException('business rollback');
            });
            self::fail('外层回滚失败必须报告事务结果未知');
        } catch (TransactionException $e) {
            self::assertSame('rollback', $e->operation);
            self::assertSame('business rollback', $e->businessException?->getMessage());
            self::assertSame('rollback failure', $e->getPrevious()?->getMessage());
        }
        self::assertSame(1, $connection->rollbacks);
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testDifferentFibersCommitIndependentConnectionsWhileSuspended(): void
    {
        $makeFiber = static function (array &$states): \Fiber {
            return new \Fiber(static function () use (&$states): void {
                $connection = new IndependentLifecycleConnection();
                Session::use(new EntityGateway($connection));
                $connection->onSuspend = static function (): void {
                    \Fiber::suspend();
                };
                LifecycleRecord::transaction(static function (): void {});
                $states[] = $connection->operations;
            });
        };
        $states = [];
        $first = $makeFiber($states);
        $second = $makeFiber($states);
        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertTrue($first->isTerminated());
        self::assertTrue($second->isTerminated());
        self::assertSame([
            ['begin', 'commit'],
            ['begin', 'commit'],
        ], $states);
    }

    public function testDistinctPdoConnectionsCommitInterleavedFiberTransactions(): void
    {
        $path = self::databasePath();
        $first = new \Fiber(static function () use ($path): void {
            Session::use(self::gateway($path));
            LifecycleRecord::transaction(static function (): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 10, 'name' => 'fiber-10']);
                \Fiber::suspend();
            });
        });
        $first->start();
        self::assertFalse($first->isTerminated());
        self::assertSame([], self::databaseRows($path));
        $first->resume();

        $second = new \Fiber(static function () use ($path): void {
            Session::use(self::gateway($path));
            LifecycleRecord::transaction(static function (): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 11, 'name' => 'fiber-11']);
                \Fiber::suspend();
            });
        });
        $second->start();
        self::assertFalse($second->isTerminated());
        self::assertSame([['id' => 10, 'name' => 'fiber-10']], self::databaseRows($path));
        $second->resume();

        self::assertTrue($first->isTerminated());
        self::assertTrue($second->isTerminated());
        self::assertSame([
            ['id' => 10, 'name' => 'fiber-10'],
            ['id' => 11, 'name' => 'fiber-11'],
        ], self::databaseRows($path));
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function testDestroyedFiberDoesNotReuseTransactionContext(): void
    {
        $gateway = $this->rootGateway;
        $rootContext = Session::context();
        // 每个 Fiber 在一个短生命周期局部闭包中创建并彻底释放，避免测试闭包捕获 $this 改变分配顺序。
        $allocate = static function () use ($gateway): array {
            $fiber = new \Fiber(static function () use ($gateway): array {
                Session::use($gateway);
                $context = Context::fiber(\Fiber::getCurrent());
                $id = spl_object_id(\Fiber::getCurrent());
                LifecycleRecord::transaction(static fn (): null => null);
                Session::discard();

                return [$id, $context];
            });
            $fiber->start();
            $result = $fiber->getReturn();
            unset($fiber);
            gc_collect_cycles();

            return $result;
        };
        $numericIds = [];
        $contexts = [];
        for ($attempt = 0; $attempt < 128; $attempt++) {
            [$id, $context] = $allocate();
            self::assertFalse(in_array($context, $contexts, true), 'Context 必须按 Fiber 对象身份隔离');
            if (isset($numericIds[$id])) {
                self::assertNotSame($numericIds[$id], $context, '数字 ID 复用不得复用旧事务 Context');
                self::assertSame($rootContext, Session::context(), '已完成 Fiber 不得改变根执行上下文');

                return;
            }
            $numericIds[$id] = $context;
            $contexts[] = $context;
        }
        self::fail('128 次独立分配/释放后仍未覆盖 Fiber 数字 ID 复用，请检查测试前提');
    }

    public function testNamedFiberScopeDoesNotRestoreRootSession(): void
    {
        $root = Session::use($this->rootGateway, 'readonly');
        $gateway = self::gateway(':memory:');
        $fiber = new \Fiber(static function () use ($gateway): void {
            Session::scope($gateway, static function () use ($gateway): void {
                self::assertSame($gateway, Session::get('readonly')->gateway);
            }, 'readonly');
            Session::get('readonly');
        });
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Session::use(..., 'readonly') registration exists in the current Fiber/coroutine");
        try {
            $fiber->start();
        } finally {
            self::assertSame($root, Session::get('readonly'));
        }
    }

    public function testActiveFiberCannotShareGatewayWithoutTransaction(): void
    {
        $first = new \Fiber(function (): void {
            Session::use($this->rootGateway);
            \Fiber::suspend();
            Session::discard();
        });
        $first->start();
        $second = new \Fiber(function (): void {
            try {
                Session::use($this->rootGateway);
                self::fail('非事务读写也不能在两个活跃 Fiber 间共享同一网关');
            } catch (\LogicException $e) {
                self::assertStringContainsString('another active Fiber/coroutine', $e->getMessage());
            }
        });
        $second->start();
        self::assertTrue($second->isTerminated());
        $first->resume();
        self::assertTrue($first->isTerminated());

        Session::use($this->rootGateway);
        self::assertSame($this->rootGateway, Session::get()->gateway);
    }

    public function testNamedSessionsShareFiberWithoutReplacingEachOther(): void
    {
        $readonly = self::gateway(':memory:');
        Session::use($this->rootGateway);
        Session::use($readonly, 'readonly');
        self::assertSame($this->rootGateway, Session::get()->gateway);
        self::assertSame($readonly, Session::get('readonly')->gateway);

        $scoped = self::gateway(':memory:');
        Session::scope($scoped, static function () use ($scoped, $readonly): void {
            self::assertSame($scoped, Session::get()->gateway);
            self::assertSame($readonly, Session::get('readonly')->gateway);
        });
        self::assertSame($this->rootGateway, Session::get()->gateway);
        self::assertSame($readonly, Session::get('readonly')->gateway);

        Session::discard('default');
        self::assertSame($readonly, Session::get('readonly')->gateway);
        $this->expectException(\LogicException::class);
        Session::get();
    }

    public function testDiscardingOneNamedSessionCannotConfirmAnotherRollback(): void
    {
        Session::use($this->rootGateway);
        $readonly = self::gateway(':memory:');
        Session::use($readonly, 'readonly');
        try {
            $this->rootGateway->transaction(static function (): void {
                Session::discard('readonly');
                throw new \DomainException('only default transaction rolls back');
            });
            self::fail('default 事务异常必须透传');
        } catch (\DomainException $e) {
            self::assertSame('only default transaction rolls back', $e->getMessage());
        }
        self::assertSame($this->rootGateway, Session::get()->gateway);
        $this->expectException(\LogicException::class);
        Session::get('readonly');
    }

    public function testDiscardAllNamesRollsBackEachActiveTransaction(): void
    {
        // 两个 SQLite 连接不必同时持写事务；验证独立物理回滚链即可。
        $first = self::gateway(':memory:');
        $second = self::gateway(':memory:');
        $firstConnection = new RecordingLifecycleConnection($first->connection());
        $secondConnection = new RecordingLifecycleConnection($second->connection());
        $firstGateway = new EntityGateway($firstConnection);
        $secondGateway = new EntityGateway($secondConnection);
        Session::use($firstGateway);
        Session::use($secondGateway, 'readonly');
        try {
            $firstGateway->transaction(static function () use ($secondGateway): void {
                LifecycleRecord::executeRaw('INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)', ['id' => 101, 'name' => 'first']);
                $secondGateway->transaction(static function (): void {
                    Session::get('readonly')->gateway->executeRaw(
                        'INSERT INTO lifecycle_record (id, name) VALUES (:id, :name)',
                        ['id' => 102, 'name' => 'readonly'],
                    );
                    Session::discard();
                });
            });
            self::fail('所有命名会话回滚后不得提交');
        } catch (\LogicException $e) {
            self::assertStringContainsString('the work was rolled back', $e->getMessage());
        }
        self::assertSame(1, $firstConnection->rollbacks);
        self::assertSame(1, $secondConnection->rollbacks);
        self::assertSame([], $firstConnection->queryAll('SELECT id FROM lifecycle_record', []));
        self::assertSame([], $secondConnection->queryAll('SELECT id FROM lifecycle_record', []));
    }

    public function testReplacingOneNameReleasesItsPreviousGateway(): void
    {
        Session::use($this->rootGateway);
        $replacement = self::gateway(':memory:');
        Session::use($replacement);
        $fiber = new \Fiber(function (): void {
            Session::use($this->rootGateway);
            Session::discard();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        self::assertSame($replacement, Session::get()->gateway);
    }

    public function testNamedScopeKeepsSameGatewayHeldUnderAnotherName(): void
    {
        Session::use($this->rootGateway);
        Session::use($this->rootGateway, 'readonly');
        Session::scope(self::gateway(':memory:'), static function (): void {
            Session::discard('readonly');
        });
        self::assertSame($this->rootGateway, Session::get()->gateway);
        $probe = new \Fiber(function (): void {
            try {
                Session::use($this->rootGateway);
                self::fail('删除另一个命名会话后，恢复的外层会话仍须保持独占');
            } catch (\LogicException $e) {
                self::assertStringContainsString('another active Fiber/coroutine', $e->getMessage());
            }
        });
        $probe->start();
        self::assertTrue($probe->isTerminated());
        Session::discard();
        $fiber = new \Fiber(function (): void {
            Session::use($this->rootGateway);
            Session::discard();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
    }

    public function testDiscardAllNamesOnlyAffectsCurrentFiber(): void
    {
        $readonly = self::gateway(':memory:');
        $fiber = new \Fiber(static function () use ($readonly): void {
            Session::use(self::gateway(':memory:'));
            Session::use($readonly, 'readonly');
            self::assertNotNull(Session::get()->gateway);
            self::assertSame($readonly, Session::get('readonly')->gateway);
            Session::discard();
            self::assertNull(self::lookupSessionsForTest());
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        Session::use($this->rootGateway);
        Session::use($readonly, 'readonly');
        self::assertSame($readonly, Session::get('readonly')->gateway);
    }

    private static function lookupSessionsForTest(): ?array
    {
        $sessions = new \ReflectionProperty(Session::class, 'contextSessions');
        $map = $sessions->getValue();

        return $map[Session::context()] ?? null;
    }

    private static function gateway(string $path): EntityGateway
    {
        static $initialized = [];
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode=DELETE');
            $pdo->exec('PRAGMA busy_timeout=2000');
        }
        if ($path === ':memory:' || !isset($initialized[$path])) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS lifecycle_record (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
            $initialized[$path] = true;
        }

        return new EntityGateway(new PdoConnection($pdo));
    }

    private static function databasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pixiaoka-session-');
        self::assertIsString($path);

        return $path;
    }

    /** @return list<array{id: int, name: string}> */
    private static function databaseRows(string $path): array
    {
        $pdo = new PDO('sqlite:' . $path);

        return $pdo->query('SELECT id, name FROM lifecycle_record ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class LifecycleRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public string $name;

    public static function table(): string
    {
        return 'lifecycle_record';
    }
}

final class RecordingLifecycleConnection implements Connection
{
    public int $rollbacks = 0;

    public bool $failRollback = false;

    public function __construct(private readonly Connection $inner)
    {
    }

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
        $this->rollbacks++;
        if ($this->failRollback) {
            throw new \RuntimeException('rollback failure');
        }

        return $this->inner->rollback();
    }
}

final class IndependentLifecycleConnection implements Connection
{
    /** @var list<string> */
    public array $operations = [];
    public ?\Closure $onSuspend = null;

    public function execute(string $sql, array $params): int { return 0; }
    public function queryOne(string $sql, array $params): ?array { return null; }
    public function queryAll(string $sql, array $params): array { return []; }
    public function lastInsertId(): string { return ''; }
    public function quoteIdentifier(string $identifier): string { return '"' . $identifier . '"'; }
    public function supportsReturning(): bool { return true; }
    public function beginTransaction(): bool
    {
        $this->operations[] = 'begin';
        ($this->onSuspend)();

        return true;
    }
    public function commit(): bool
    {
        $this->operations[] = 'commit';

        return true;
    }
    public function rollback(): bool
    {
        $this->operations[] = 'rollback';

        return true;
    }
}
