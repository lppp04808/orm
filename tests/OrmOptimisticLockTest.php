<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Exception\OptimisticLockException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Session;

final class OrmOptimisticLockTest extends TestCase
{
    private PDO $pdo;

    private EntityGateway $gateway;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE version_record (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE stable_version_record (id INTEGER PRIMARY KEY, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testInsertDefaultExplicitBatchAndHookVersion(): void
    {
        $record = new VersionRecord();
        $record->body = 'initial';
        $record->save();
        self::assertSame(1, $record->revision);
        self::assertSame(1, $record->beforeInsertVersion);

        $zero = new VersionRecord();
        $zero->body = 'zero';
        $zero->revision = 0;
        $zero->save();
        self::assertSame(0, $zero->revision);

        $batch = new VersionRecord();
        $batch->body = 'batch';
        VersionRecord::insertAll([$batch, ['body' => 'array'], ['body' => 'explicit', 'revision' => 4]]);
        self::assertSame(1, $batch->revision);
        self::assertSame(1, $batch->beforeInsertVersion);
        $batch->body = 'saved again';
        $batch->save();
        self::assertSame(2, $batch->revision);
        self::assertSame([[1], [0], [2], [1], [4]], $this->pdo->query('SELECT revision FROM version_record ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public function testUpdateAndDeleteUseOriginalVersionAndKeepStateOnConflict(): void
    {
        $record = new VersionRecord();
        $record->body = 'initial';
        $record->save();
        $before = $record->takeOriginal();
        $this->pdo->exec('UPDATE version_record SET body = "other", revision = revision + 1');

        $record->body = 'local';
        try {
            $record->save();
            self::fail('陈旧版本不能保存');
        } catch (OptimisticLockException $e) {
            self::assertSame('UPDATE', $e->operation);
            self::assertSame(1, $e->expectedVersion);
            self::assertSame(VersionRecord::class, $e->model);
        }
        self::assertSame(1, $record->revision);
        self::assertSame($before, $record->takeOriginal());
        self::assertSame(0, $record->afterUpdates);
        self::assertSame($record, VersionRecord::find(['id' => $record->id]));

        try {
            $record->delete();
            self::fail('陈旧版本不能删除');
        } catch (OptimisticLockException $e) {
            self::assertSame('DELETE', $e->operation);
        }
        self::assertFalse($record->isNew());
        $record->refresh();
        self::assertSame(2, $record->revision);
        $record->delete();
        self::assertTrue($record->isNew());
    }

    public function testAtomicSqlAndSuccessfulUpdate(): void
    {
        $connection = new VersionRecordingConnection(new PdoConnection($this->pdo));
        Session::use(new EntityGateway($connection));
        $record = new VersionRecord();
        $record->body = 'before';
        $record->save();
        $record->body = 'after';
        $record->save();
        self::assertSame(2, $record->revision);
        self::assertSame(2, (int) $this->pdo->query('SELECT revision FROM version_record')->fetchColumn());
        $sql = end($connection->log);
        self::assertSame('UPDATE "version_record" SET "body" = :set_body, "revision" = "revision" + 1 WHERE "id" = :pk_id AND "revision" = :orm_version', $sql['sql']);
        self::assertSame(['set_body' => 'after', 'pk_id' => $record->id, 'orm_version' => 1], $sql['params']);
        self::assertSame(1, $record->afterUpdates);
    }

    public function testVersionTamperingNoOpAndUnsafeBulkWritesAreRejected(): void
    {
        $record = new VersionRecord();
        $record->body = 'initial';
        $record->save();
        $record->revision = 99;
        try {
            $record->save();
            self::fail('不能手改版本');
        } catch (IdentityException $e) {
            self::assertStringContainsString('不能直接修改', $e->getMessage());
        }
        $record->refresh();
        $record->save();
        self::assertSame(1, $record->revision);
        foreach ([
            static fn (): int => VersionRecord::query()->where('id', $record->id)->update(['body' => 'bulk']),
            static fn (): int => VersionRecord::query()->where('id', $record->id)->delete(),
            static fn (): int => VersionRecord::upsert(['id' => $record->id, 'body' => 'upsert']),
        ] as $operation) {
            try {
                $operation();
                self::fail('批量写不能绕过乐观锁');
            } catch (\LogicException $e) {
                self::assertStringContainsString('无法保留逐实体版本语义', $e->getMessage());
            }
        }
    }

    public function testRollbackRestoresEveryFieldVersionSnapshotAndCache(): void
    {
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $id = $record->id;
        $fresh = new VersionRecord();
        $fresh->body = 'new row';
        try {
            VersionRecord::transaction(function () use ($record, $fresh): void {
                VersionRecord::transaction(function () use ($record, $fresh): void {
                    $record->body = 'inner';
                    $record->save();
                    $record->body = 'outer';
                    $record->save();
                    $fresh->save();
                });
                $record->delete();
                self::assertSame(3, $record->revision);
                throw new \RuntimeException('rollback');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame('committed', $record->body);
        self::assertSame(1, $record->revision);
        self::assertFalse($record->isNew());
        self::assertSame(1, $record->takeOriginal()['revision']);
        self::assertSame($record, VersionRecord::find(['id' => $id]));
        self::assertTrue($fresh->isNew());
        self::assertFalse($fresh->propSet('id'));
        self::assertFalse($fresh->propSet('revision'));
        self::assertSame('new row', $fresh->body);
        $record->body = 'retry';
        $record->save();
        self::assertSame(2, $record->revision);
        $fresh->save();
        self::assertSame(1, $fresh->revision);
    }

    public function testRollbackRestoresEntitiesFirstLoadedInsideTransaction(): void
    {
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $id = $record->id;
        $this->gateway->clearCache(VersionRecord::class);
        unset($record);
        $loaded = null;
        try {
            VersionRecord::transaction(function () use ($id, &$loaded): void {
                $loaded = VersionRecord::find(['id' => $id]);
                self::assertNotNull($loaded);
                $loaded->body = 'transaction';
                $loaded->save();
                self::assertSame(2, $loaded->revision);
                throw new \RuntimeException('rollback loaded entity');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback loaded entity', $e->getMessage());
        }
        self::assertNotNull($loaded);
        self::assertSame('committed', $loaded->body);
        self::assertSame(1, $loaded->revision);
        self::assertSame(1, $loaded->takeOriginal()['revision']);
        $fresh = VersionRecord::find(['id' => $id]);
        self::assertNotNull($fresh);
        self::assertSame('committed', $fresh->body);
        self::assertSame(1, $fresh->revision);
        $loaded->body = 'retry';
        $loaded->save();
        self::assertSame(2, $loaded->revision);
    }

    public function testRollbackRestoresAllInstancesOfTheSameIdentity(): void
    {
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $id = $record->id;
        $other = null;
        try {
            VersionRecord::transaction(function () use ($record, $id, &$other): void {
                $record->body = 'uncommitted';
                $record->save();
                $other = VersionRecord::query()->where('id', $id)->first();
                self::assertNotNull($other);
                self::assertNotSame($record, $other);
                self::assertSame(2, $other->revision);
                throw new \RuntimeException('rollback all instances');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback all instances', $e->getMessage());
        }
        self::assertNotNull($other);
        foreach ([$record, $other] as $entity) {
            self::assertSame('committed', $entity->body);
            self::assertSame(1, $entity->revision);
            self::assertSame('committed', $entity->takeOriginal()['body']);
            self::assertSame(1, $entity->takeOriginal()['revision']);
        }
        self::assertSame($record, VersionRecord::find(['id' => $id]));
        self::assertSame([['committed', 1]], $this->pdo->query('SELECT body, revision FROM version_record')->fetchAll(PDO::FETCH_NUM));
    }

    public function testRollbackRestoresNewEntityAfterItIsQueriedAgain(): void
    {
        $record = new VersionRecord();
        $record->body = 'new in transaction';
        $other = null;
        try {
            VersionRecord::transaction(function () use ($record, &$other): void {
                $record->save();
                $other = VersionRecord::query()->where('id', $record->id)->first();
                self::assertNotNull($other);
                self::assertNotSame($record, $other);
                throw new \RuntimeException('rollback inserted row');
            });
        } catch (\RuntimeException) {
        }
        self::assertTrue($record->isNew());
        self::assertFalse($record->propSet('id'));
        self::assertFalse($record->propSet('revision'));
        self::assertSame([], $record->takeOriginal());
        self::assertNotNull($other);
        self::assertTrue($other->isNew());
        self::assertSame(1, $other->id);
        self::assertSame('new in transaction', $other->body);
        self::assertSame(1, $other->revision);
        self::assertSame([], $other->takeOriginal());
        $other->body = 'must not update';
        try {
            $other->save();
            self::fail('回滚后的生成主键不得被重新插入');
        } catch (IdentityException $e) {
            self::assertStringContainsString('生成列由数据库填写', $e->getMessage());
        }
        self::assertSame([], $other->takeOriginal());
        self::assertSame(1, $other->revision);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM version_record')->fetchColumn());
        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('还没入库');
        $other->delete();
        self::assertNull(VersionRecord::query()->where('body', 'new in transaction')->first());
        $record->save();
        self::assertSame(1, $record->revision);
    }

    public function testRollbackRestoresExistingRowAfterDeleteReinsertAndRepeatedQueries(): void
    {
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $id = $record->id;
        $replacement = null;
        $other = null;
        $reused = false;
        try {
            VersionRecord::transaction(function () use ($record, &$replacement, &$other, &$reused): void {
                $record->delete();
                $replacement = new VersionRecord();
                $replacement->body = 'replacement';
                $replacement->save();
                $reused = $replacement->id === $record->id;
                $other = VersionRecord::query()->where('id', $replacement->id)->first();
                self::assertNotNull($other);
                self::assertNotSame($replacement, $other);
                self::assertSame('replacement', $other->body);
                $replacement->body = 'replacement changed';
                $replacement->save();
                $again = VersionRecord::query()->where('id', $replacement->id)->first();
                self::assertNotNull($again);
                self::assertSame('replacement changed', $again->body);
                throw new \RuntimeException('rollback reinserted existing row');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback reinserted existing row', $e->getMessage());
        }
        self::assertNotNull($replacement);
        self::assertNotNull($other);
        self::assertFalse($record->isNew());
        self::assertSame($id, $record->id);
        self::assertSame('committed', $record->body);
        self::assertSame(1, $record->revision);
        self::assertSame('committed', $record->takeOriginal()['body']);
        self::assertSame(1, $record->takeOriginal()['revision']);
        if ($reused) {
            foreach ([$replacement, $other] as $entity) {
                self::assertFalse($entity->isNew());
                self::assertSame($id, $entity->id);
                self::assertSame('committed', $entity->body);
                self::assertSame(1, $entity->revision);
                self::assertSame('committed', $entity->takeOriginal()['body']);
                self::assertSame(1, $entity->takeOriginal()['revision']);
            }
        } else {
            foreach ([$replacement, $other] as $entity) {
                self::assertTrue($entity->isNew());
                self::assertSame([], $entity->takeOriginal());
            }
            self::assertFalse($replacement->propSet('id'));
            self::assertFalse($replacement->propSet('revision'));
            self::assertSame(2, $other->id);
            self::assertSame(1, $other->revision);
        }
        self::assertSame($record, VersionRecord::find(['id' => $id]));
        self::assertSame([['committed', 1]], $this->pdo->query('SELECT body, revision FROM version_record')->fetchAll(PDO::FETCH_NUM));
        $record->body = 'retry after rollback';
        $record->save();
        self::assertSame(2, $record->revision);
    }

    public function testRollbackRestoresStableGeneratedIdentityAfterRepeatedDeleteAndReinsert(): void
    {
        VersionRecord::$table = 'stable_version_record';
        $record = new VersionRecord();
        $record->body = 'committed';
        $record->save();
        $id = $record->id;
        $replacement = null;
        $other = null;
        try {
            VersionRecord::transaction(function () use ($record, &$replacement, &$other): void {
                $record->delete();
                $replacement = new VersionRecord();
                $replacement->body = 'replacement';
                $replacement->save();
                self::assertSame($record->id, $replacement->id);
                $other = VersionRecord::query()->where('id', $replacement->id)->first();
                self::assertNotNull($other);
                $replacement->body = 'changed';
                $replacement->save();
                $replacement->delete();
                $second = new VersionRecord();
                $second->body = 'second replacement';
                $second->save();
                self::assertSame($record->id, $second->id);
                $last = VersionRecord::query()->where('id', $second->id)->first();
                self::assertNotNull($last);
                throw new \RuntimeException('rollback stable identity');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback stable identity', $e->getMessage());
        } finally {
            VersionRecord::$table = 'version_record';
        }
        self::assertNotNull($replacement);
        self::assertNotNull($other);
        foreach ([$record, $replacement, $other] as $entity) {
            self::assertFalse($entity->isNew());
            self::assertSame($id, $entity->id);
            self::assertSame('committed', $entity->body);
            self::assertSame(1, $entity->revision);
            self::assertSame('committed', $entity->takeOriginal()['body']);
            self::assertSame(1, $entity->takeOriginal()['revision']);
        }
        self::assertSame($record, VersionRecord::find(['id' => $id]));
        self::assertSame([['committed', 1]], $this->pdo->query('SELECT body, revision FROM stable_version_record')->fetchAll(PDO::FETCH_NUM));
    }

    public function testTwoIndependentConnectionsConflict(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pixiaoka-version-');
        self::assertIsString($path);
        try {
            $first = new PDO('sqlite:' . $path);
            $first->exec('CREATE TABLE version_record (id INTEGER PRIMARY KEY, body TEXT NOT NULL, revision INTEGER NOT NULL)');
            $second = new PDO('sqlite:' . $path);
            $leftGateway = new EntityGateway(new PdoConnection($first));
            $rightGateway = new EntityGateway(new PdoConnection($second));
            Session::use($leftGateway);
            $left = new VersionRecord();
            $left->body = 'left';
            $left->save();
            Session::use($rightGateway);
            $right = VersionRecord::find(['id' => $left->id]);
            self::assertNotNull($right);
            Session::use($leftGateway);
            $left->body = 'winner';
            $left->save();
            Session::use($rightGateway);
            $right->body = 'loser';
            $this->expectException(OptimisticLockException::class);
            $right->save();
        } finally {
            Session::discard();
            unset($first, $second, $leftGateway, $rightGateway);
            @unlink($path);
        }
    }

    public function testRollbackPreservesLruOrderAfterRemovingTemporaryRows(): void
    {
        $gateway = (new \ReflectionClass(EntityGateway::class))->newInstanceWithoutConstructor();
        $connection = new \ReflectionProperty(EntityGateway::class, 'connection');
        $connection->setValue($gateway, new PdoConnection($this->pdo));
        $cache = new RowCache(2);
        $cacheProperty = new \ReflectionProperty(EntityGateway::class, 'cache');
        $cacheProperty->setValue($gateway, $cache);
        foreach (['inTransactionByContext', 'txnStatesByContext', 'txnIdentityStates', 'txnNewIdentities', 'txnCacheSnapshots', 'txnStartedAt'] as $property) {
            $map = new \ReflectionProperty(EntityGateway::class, $property);
            $map->setValue($gateway, new \WeakMap());
        }
        $events = new \ReflectionProperty(EntityGateway::class, 'events');
        $events->setValue($gateway, new \Pixiaoka\Orm\Observability\EventDispatcher());
        $clock = new \ReflectionProperty(EntityGateway::class, 'clock');
        $clock->setValue($gateway, new \Pixiaoka\Orm\Observability\SystemClock());
        $metadata = new \ReflectionProperty(EntityGateway::class, 'metadata');
        $metadata->setValue($gateway, new MetadataReader());
        $caster = new \ReflectionProperty(EntityGateway::class, 'caster');
        $caster->setValue($gateway, new \Pixiaoka\Orm\Cast\Caster());
        Session::use($gateway);
        $first = new VersionRecord();
        $first->body = 'first';
        $first->save();
        $second = new VersionRecord();
        $second->body = 'second';
        $second->save();
        self::assertSame($first, VersionRecord::find(['id' => $first->id]));
        self::assertSame($second, VersionRecord::find(['id' => $second->id]));
        try {
            VersionRecord::transaction(function (): void {
                $temporary = new VersionRecord();
                $temporary->body = 'temporary';
                $temporary->save();
                throw new \RuntimeException('rollback cache');
            });
        } catch (\RuntimeException) {
        }
        $third = new VersionRecord();
        $third->body = 'third';
        $third->save();
        $cacheRows = (new \ReflectionProperty(RowCache::class, 'items'))->getValue($cache);
        self::assertSame([$second, $third], array_values($cacheRows));
    }

    /** @return array<string, array{class-string, string}> */
    public static function invalidModels(): array
    {
        return [
            'missing column' => [VersionWithoutColumn::class, '必须同时声明'],
            'duplicate' => [DuplicateVersionRecord::class, '只能声明一个'],
            'primary' => [PrimaryVersionRecord::class, '不能兼任主键'],
            'string' => [StringVersionRecord::class, '非空 int'],
            'nullable' => [NullableVersionRecord::class, '非空 int'],
            'timestamp' => [TimeVersionRecord::class, '不能兼任自动时间'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidModels')]
    public function testInvalidMetadata(string $class, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);
        (new MetadataReader())->read($class);
    }

    public function testNegativeInitialVersionRejected(): void
    {
        $record = new VersionRecord();
        $record->body = 'invalid';
        $record->revision = -1;
        $this->expectException(IdentityException::class);
        $record->save();
    }
}

class VersionRecord extends Entity
{
    public static string $table = 'version_record';
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $body;
    #[Column(name: 'revision')]
    #[Version]
    public int $revision;
    public int $beforeInsertVersion = -1;
    public int $afterUpdates = 0;
    public function beforeInsert(): void { $this->beforeInsertVersion = $this->revision; }
    public function afterUpdate(): void { $this->afterUpdates++; }
    public static function table(): string { return self::$table; }
}

class InvalidVersionBase extends VersionRecord
{
}

final class VersionRecordingConnection implements Connection
{
    public array $log = [];
    public function __construct(private readonly Connection $inner) {}
    public function execute(string $sql, array $params): int { $this->log[] = ['sql' => $sql, 'params' => $params]; return $this->inner->execute($sql, $params); }
    public function queryOne(string $sql, array $params): ?array { return $this->inner->queryOne($sql, $params); }
    public function queryAll(string $sql, array $params): array { return $this->inner->queryAll($sql, $params); }
    public function lastInsertId(): string { return $this->inner->lastInsertId(); }
    public function quoteIdentifier(string $identifier): string { return $this->inner->quoteIdentifier($identifier); }
    public function supportsReturning(): bool { return $this->inner->supportsReturning(); }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollback(): bool { return $this->inner->rollback(); }
}

final class VersionWithoutColumn extends InvalidVersionBase { #[Version] public int $other = 1; }
final class DuplicateVersionRecord extends InvalidVersionBase { #[Column] #[Version] public int $other = 1; }
final class PrimaryVersionRecord extends Entity { #[Column] #[PrimaryKey] #[Version] public int $id = 1; #[Column] public string $body = ''; public static function table(): string { return 'invalid'; } }
final class StringVersionRecord extends InvalidVersionBase { #[Column] #[Version] public string $other = ''; }
final class NullableVersionRecord extends InvalidVersionBase { #[Column] #[Version] public ?int $other = 1; }
final class TimeVersionRecord extends InvalidVersionBase { #[Column] #[Version] public int $created_at = 1; }
