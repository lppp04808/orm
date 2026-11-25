<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\OptimisticLockException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

require_once __DIR__ . '/SoftDeleteTest.php';

class SoftDeleteVersionRecord extends Entity
{
    public static string $table = 'soft_version_records';

    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    #[Column]
    #[Version]
    public int $revision;

    public static function table(): string
    {
        return self::$table;
    }
}

final class SoftDeleteVersionSafetyTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;
    private ?string $sharedPath = null;
    private ?PDO $otherPdo = null;
    private ?EntityGateway $otherGateway = null;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE soft_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE soft_version_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, deleted_at TEXT NULL, revision INTEGER NOT NULL)');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
        $this->otherGateway = null;
        $this->otherPdo = null;
        SoftDeleteVersionRecord::$table = 'soft_version_records';
        unset($this->gateway, $this->pdo);
        if ($this->sharedPath !== null) {
            @unlink($this->sharedPath);
            $this->sharedPath = null;
        }
    }

    public static function unscopedWrites(): array
    {
        return [
            'default delete' => [static fn () => SoftDeleteEntity::query()->delete()],
            'with trashed delete' => [static fn () => SoftDeleteEntity::query()->withTrashed()->delete()],
            'only trashed delete' => [static fn () => SoftDeleteEntity::query()->onlyTrashed()->delete()],
            'default force delete' => [static fn () => SoftDeleteEntity::query()->forceDelete()],
            'with trashed force delete' => [static fn () => SoftDeleteEntity::query()->withTrashed()->forceDelete()],
            'only trashed force delete' => [static fn () => SoftDeleteEntity::query()->onlyTrashed()->forceDelete()],
            'default restore' => [static fn () => SoftDeleteEntity::query()->restore()],
            'only trashed restore' => [static fn () => SoftDeleteEntity::query()->onlyTrashed()->restore()],
            'empty OR delete' => [static fn () => SoftDeleteEntity::query()->orGroup(static function (): void {})->delete()],
            'nested empty OR force delete' => [static fn () => SoftDeleteEntity::query()->withTrashed()->orGroup(
                static fn ($group) => $group->orGroup(static function (): void {}),
            )->forceDelete()],
            'empty OR restore' => [static fn () => SoftDeleteEntity::query()->orGroup(static function (): void {})->restore()],
        ];
    }

    #[DataProvider('unscopedWrites')]
    public function testConditionalDeleteRestoreAndForceDeleteRequireExplicitWhere(callable $write): void
    {
        $active = $this->row('active');
        $deleted = $this->row('deleted');
        $deleted->delete();

        try {
            $write();
            self::fail('隐式软删除范围或空 OR 分组不得替代显式 WHERE');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('WHERE', $e->getMessage());
        }
        self::assertSame([['active', null], ['deleted', $deleted->deleted_at->format('Y-m-d H:i:s')]], $this->databaseRows());
        self::assertNull($active->deleted_at);
        self::assertNotNull($deleted->deleted_at);
    }

    public function testEmptyInIsAnExplicitConditionAndDoesNotDeleteAnything(): void
    {
        $this->row('active');

        self::assertSame(0, SoftDeleteEntity::query()->whereIn('id', [])->delete());
        self::assertSame(0, SoftDeleteEntity::query()->whereIn('id', [])->forceDelete());
        self::assertSame(0, SoftDeleteEntity::query()->whereIn('id', [])->restore());
        self::assertSame(0, SoftDeleteEntity::query()->orGroup(static fn ($group) => $group->whereIn('id', []))->delete());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM soft_rows')->fetchColumn());
    }

    public function testEmptyNotInIsAnExplicitCondition(): void
    {
        $this->row('active');

        self::assertSame(1, SoftDeleteEntity::query()->whereNotIn('id', [])->delete());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM soft_rows WHERE deleted_at IS NULL')->fetchColumn());
    }

    public function testExplicitOrGroupScopesSoftDelete(): void
    {
        $active = $this->row('active');
        $other = $this->row('other');

        self::assertSame(1, SoftDeleteEntity::query()->orGroup(
            static fn ($group) => $group->where('id', $active->id),
        )->delete());
        self::assertNotNull($active->deleted_at);
        self::assertNull($other->deleted_at);
    }

    public function testSoftDeletedEntitySaveCannotUpdateItsRow(): void
    {
        $row = $this->row('deleted');
        $row->delete();
        $before = $this->databaseRows();
        $original = $row->takeOriginal();

        $row->name = 'must not update deleted row';
        try {
            $row->save();
            self::fail('软删除实体的普通保存不得更新数据库');
        } catch (\Pixiaoka\Orm\Exception\IdentityException $e) {
            self::assertStringContainsString('non-deleted', $e->getMessage());
        }
        self::assertSame($original, $row->takeOriginal());
        self::assertSame('must not update deleted row', $row->name);
        self::assertSame($before, $this->databaseRows());
    }

    public function testSaveCannotUpdateSoftDeletedRowWithoutVersion(): void
    {
        $other = $this->connectOtherGateway();
        try {
            Session::use($this->gateway);
            $record = $this->row('local');
            Session::use($other);
            $remote = $other->find(SoftDeleteEntity::class, ['id' => $record->id]);
            self::assertNotNull($remote);
            Session::use($this->gateway);
            $record = $this->gateway->find(SoftDeleteEntity::class, ['id' => $record->id]);
            self::assertNotNull($record);
            Session::use($other);
            $remote->delete();

            Session::use($this->gateway);
            $record->name = 'must not update deleted row';
            try {
                $record->save();
                self::fail('普通保存必须 AND deleted_at IS NULL，即使数据库版本未变化');
            } catch (\Pixiaoka\Orm\Exception\IdentityException $e) {
                self::assertStringContainsString('non-deleted', $e->getMessage());
            }
            self::assertFalse($record->isNew());
            self::assertSame('local', $record->takeOriginal()['name']);
            $row = $this->pdo->query('SELECT name, deleted_at FROM soft_rows WHERE id = ' . $record->id)->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertSame('local', $row['name']);
            self::assertNotNull($row['deleted_at']);
        } finally {
            Session::discard();
        }
    }

    public function testVersionConditionalWritesAreRejectedBeforeAnyWrite(): void
    {
        $record = $this->versionRow('active');
        $record->delete();
        $before = $this->versionDatabaseRow($record->id);

        $writes = [
            static fn () => SoftDeleteVersionRecord::query()->where('id', $record->id)->update(['name' => 'bulk']),
            static fn () => SoftDeleteVersionRecord::query()->where('id', $record->id)->delete(),
            static fn () => SoftDeleteVersionRecord::query()->where('id', $record->id)->forceDelete(),
            static fn () => SoftDeleteVersionRecord::query()->where('id', $record->id)->restore(),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                self::fail('带 Version 的条件写必须保持拒绝');
            } catch (\LogicException $e) {
                self::assertStringContainsString('cannot preserve per-entity version semantics', $e->getMessage());
            }
        }
        self::assertSame($before, $this->versionDatabaseRow($record->id));
        self::assertSame(2, $record->revision);
    }

    public function testSoftDeleteChecksAndIncrementsVersion(): void
    {
        $record = $this->versionRow('active');

        $record->delete();

        self::assertSame(2, $record->revision);
        self::assertSame(2, $record->takeOriginal()['revision']);
        self::assertInstanceOf(DateTimeImmutable::class, $record->deleted_at);
        self::assertSame(2, $this->versionDatabaseRow($record->id)['revision']);
        self::assertNotNull($this->versionDatabaseRow($record->id)['deleted_at']);
    }

    public function testRestoreChecksAndIncrementsVersion(): void
    {
        $record = $this->versionRow('deleted');
        $record->delete();

        $record->restore();

        self::assertNull($record->deleted_at);
        self::assertSame(3, $record->revision);
        self::assertSame(3, $record->takeOriginal()['revision']);
        self::assertSame(3, $this->versionDatabaseRow($record->id)['revision']);
        self::assertNull($this->versionDatabaseRow($record->id)['deleted_at']);
    }

    public function testForceDeleteAffectsNoRowOnStaleVersionAndKeepsEntityState(): void
    {
        $record = $this->versionRow('active');
        $before = $record->takeOriginal();
        $this->pdo->exec('UPDATE soft_version_records SET revision = revision + 1 WHERE id = ' . $record->id);

        try {
            $record->forceDelete();
            self::fail('陈旧版本物理删除不得成功');
        } catch (OptimisticLockException $e) {
            self::assertSame('DELETE', $e->operation);
        }
        self::assertFalse($record->isNew());
        self::assertSame(1, $record->revision);
        self::assertSame($before, $record->takeOriginal());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM soft_version_records')->fetchColumn());
    }

    public function testSaveCannotUpdateRowSoftDeletedByAnotherConnection(): void
    {
        $other = $this->connectOtherGateway();
        try {
            Session::use($this->gateway);
            $record = $this->versionRow('local');
            Session::use($other);
            $remote = $other->find(SoftDeleteVersionRecord::class, ['id' => $record->id]);
            self::assertNotNull($remote);
            Session::use($this->gateway);
            $record = $this->gateway->find(SoftDeleteVersionRecord::class, ['id' => $record->id]);
            self::assertNotNull($record);
            Session::use($other);
            $remote = $other->find(SoftDeleteVersionRecord::class, ['id' => $record->id]);
            self::assertNotNull($remote);
            $remote->delete();

            Session::use($this->gateway);
            $record->name = 'must not resurrect deleted row';
            try {
                $record->save();
                self::fail('普通保存不得更新已软删除行');
            } catch (OptimisticLockException $e) {
                self::assertSame('UPDATE', $e->operation);
            }
            self::assertFalse($record->isNew());
            self::assertSame(1, $record->revision);
            self::assertNull($record->deleted_at);
            self::assertSame('local', $record->takeOriginal()['name']);
            $database = $this->versionDatabaseRow($record->id);
            self::assertSame('local', $database['name']);
            self::assertSame(2, $database['revision']);
            self::assertNotNull($database['deleted_at']);
        } finally {
            Session::discard();
        }
    }

    public function testStaleSoftDeleteAndRestoreKeepMemoryAndDatabaseUnchanged(): void
    {
        $other = $this->connectOtherGateway();
        try {
            Session::use($this->gateway);
            $record = $this->versionRow('original');
            Session::use($other);
            $remote = $other->find(SoftDeleteVersionRecord::class, ['id' => $record->id]);
            self::assertNotNull($remote);
            Session::use($this->gateway);
            $record = $this->gateway->find(SoftDeleteVersionRecord::class, ['id' => $record->id]);
            self::assertNotNull($record);
            Session::use($other);
            $remote->name = 'winner';
            $remote->save();

            Session::use($this->gateway);
            $original = $record->takeOriginal();
            try {
                $record->delete();
                self::fail('陈旧版本软删除不得成功');
            } catch (OptimisticLockException $e) {
                self::assertSame('DELETE', $e->operation);
            }
            self::assertSame(1, $record->revision);
            self::assertNull($record->deleted_at);
            self::assertSame($original, $record->takeOriginal());
            self::assertFalse($record->isNew());
            $database = $this->versionDatabaseRow($record->id);
            self::assertSame('winner', $database['name']);
            self::assertSame(2, $database['revision']);
            self::assertNull($database['deleted_at']);

            $record->refresh();
            $record->delete();
            $deletedAt = $record->deleted_at;
            $original = $record->takeOriginal();
            $this->advanceVersionFromOtherConnection($record->id, 3);
            $database = $this->versionDatabaseRow($record->id);
            self::assertSame(4, $database['revision']);

            Session::use($this->gateway);
            try {
                $record->restore();
                self::fail('陈旧版本恢复不得成功');
            } catch (OptimisticLockException $e) {
                self::assertSame('RESTORE', $e->operation);
            }
            self::assertSame(3, $record->revision);
            self::assertSame($deletedAt, $record->deleted_at);
            self::assertSame($original, $record->takeOriginal());
            self::assertFalse($record->isNew());
            $database = $this->versionDatabaseRow($record->id);
            self::assertSame(4, $database['revision']);
            self::assertNotNull($database['deleted_at']);
        } finally {
            Session::discard();
        }
    }

    public function testRollbackRestoresSoftDeleteRestoreVersionsAndSnapshots(): void
    {
        $record = $this->versionRow('rollback');
        $other = $this->versionRow('other');
        $recordOriginal = $record->takeOriginal();

        try {
            SoftDeleteVersionRecord::transaction(static function () use ($record): void {
                $record->delete();
                self::assertSame(2, $record->revision);
                throw new \RuntimeException('rollback soft delete');
            });
            self::fail('事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback soft delete', $e->getMessage());
        }
        self::assertNull($record->deleted_at);
        self::assertSame(1, $record->revision);
        self::assertSame($recordOriginal, $record->takeOriginal());
        self::assertSame($record, SoftDeleteVersionRecord::find(['id' => $record->id]));
        self::assertSame(1, $this->versionDatabaseRow($record->id)['revision']);
        self::assertNull($this->versionDatabaseRow($record->id)['deleted_at']);

        $record->delete();
        $deletedOriginal = $record->takeOriginal();
        try {
            SoftDeleteVersionRecord::transaction(static function () use ($record): void {
                $record->restore();
                self::assertSame(3, $record->revision);
                throw new \RuntimeException('rollback restore');
            });
            self::fail('事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback restore', $e->getMessage());
        }
        self::assertInstanceOf(DateTimeImmutable::class, $record->deleted_at);
        self::assertSame(2, $record->revision);
        self::assertSame($deletedOriginal, $record->takeOriginal());
        self::assertSame(2, $this->versionDatabaseRow($record->id)['revision']);
        self::assertNotNull($this->versionDatabaseRow($record->id)['deleted_at']);
        $cache = new \ReflectionProperty(EntityGateway::class, 'cache');
        $cached = $cache->getValue($this->gateway)->get(SoftDeleteVersionRecord::class, ['id' => $record->id]);
        self::assertSame($record, $cached, '回滚后的 canonical 缓存对象');
        self::assertNull(SoftDeleteVersionRecord::find(['id' => $record->id]));
        $visible = SoftDeleteVersionRecord::query()->withTrashed()->where('id', $record->id)->first();
        self::assertNotNull($visible);
        self::assertSame(2, $visible->revision);
        self::assertNotNull($visible->deleted_at);
        self::assertSame(1, $other->revision);
    }

    private function row(string $name): SoftDeleteEntity
    {
        $row = new SoftDeleteEntity();
        $row->name = $name;
        $row->save();

        return $row;
    }

    private function versionRow(string $name): SoftDeleteVersionRecord
    {
        $row = new SoftDeleteVersionRecord();
        $row->name = $name;
        $row->save();

        return $row;
    }

    private function connectOtherGateway(): EntityGateway
    {
        $this->sharedPath = tempnam(sys_get_temp_dir(), 'pixiaoka-soft-version-shared-');
        self::assertIsString($this->sharedPath);
        $this->pdo = new PDO('sqlite:' . $this->sharedPath);
        $this->otherPdo = new PDO('sqlite:' . $this->sharedPath);
        $this->otherPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($this->pdo, 'sqlite', SoftDeleteVersionRecord::table());
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        $this->otherGateway = new EntityGateway(new PdoConnection($this->otherPdo));
        Session::use($this->gateway);

        return $this->otherGateway;
    }

    /** @return array<string, array{string}> */
    public static function liveDrivers(): array
    {
        return ['MySQL' => ['mysql'], 'PostgreSQL' => ['pgsql']];
    }

    #[DataProvider('liveDrivers')]
    public function testRealDatabaseTwoConnectionSafetyAndRollback(string $driver): void
    {
        $config = $this->liveConnectionConfig($driver);
        if ($config === null) {
            self::markTestSkipped('未配置 ORM_TEST_' . strtoupper($driver) . '_DSN 或 PIXIAOKA_ORM_LIVE_' . strtoupper($driver) . '_DSN');
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('当前 PHP 未加载 pdo_' . $driver);
        }
        [$dsn, $user, $password] = $config;
        $table = 'soft_version_live_' . bin2hex(random_bytes(6));
        SoftDeleteVersionRecord::$table = $table;
        $this->pdo = $this->connectLive($dsn, $user, $password, $driver);
        $this->otherPdo = $this->connectLive($dsn, $user, $password, $driver);
        $this->createSchema($this->pdo, $driver, $table);
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        $this->otherGateway = new EntityGateway(new PdoConnection($this->otherPdo));
        try {
            $this->assertTwoConnectionSafetyAndRollback($this->otherGateway);
        } finally {
            Session::discard();
            $quoted = $this->gateway->quoteIdentifier($table);
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
            SoftDeleteVersionRecord::$table = 'soft_version_records';
        }
    }

    private function assertTwoConnectionSafetyAndRollback(EntityGateway $other): void
    {
        Session::use($this->gateway);
        $record = $this->versionRow('local');
        $id = $record->id;
        Session::use($other);
        $remote = (new \Pixiaoka\Orm\Query\Builder(SoftDeleteVersionRecord::class, $other))
            ->withTrashed()->where('id', $id)->first();
        self::assertNotNull($remote);
        $remote->delete();

        Session::use($this->gateway);
        $record->name = 'must not update deleted row';
        $original = $record->takeOriginal();
        try {
            $record->save();
            self::fail('远端软删除后，普通保存必须失败');
        } catch (OptimisticLockException $e) {
            self::assertSame('UPDATE', $e->operation);
        }
        $this->assertVersionState($record, 1, false, $original);
        $this->assertVersionDatabaseRow($id, 'local', 2, false);

        $record->refresh();
        $record->restore();
        $record->delete();
        $original = $record->takeOriginal();
        $deletedAt = $record->deleted_at;
        $this->advanceVersionFromOtherConnection($id, $record->revision);
        Session::use($this->gateway);
        try {
            $record->restore();
            self::fail('远端更新后，陈旧恢复必须失败');
        } catch (OptimisticLockException $e) {
            self::assertSame('RESTORE', $e->operation);
        }
        $this->assertVersionState($record, 4, true, $original);
        self::assertSame($deletedAt, $record->deleted_at);
        $this->assertVersionDatabaseRow($id, 'local', 5, false);

        $record->refresh();
        $record->restore();
        $original = $record->takeOriginal();
        Session::use($other);
        $remote->refresh();
        $remote->name = 'delete winner';
        $remote->save();
        Session::use($this->gateway);
        try {
            $record->delete();
            self::fail('远端更新后，陈旧软删除必须失败');
        } catch (OptimisticLockException $e) {
            self::assertSame('DELETE', $e->operation);
        }
        $this->assertVersionState($record, 6, false, $original);
        $this->assertVersionDatabaseRow($id, 'delete winner', 7, true);

        $record->refresh();
        $original = $record->takeOriginal();
        try {
            SoftDeleteVersionRecord::transaction(static function () use ($record): void {
                $record->delete();
                self::assertSame(8, $record->revision);
                throw new \RuntimeException('rollback live soft delete');
            });
            self::fail('软删除事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback live soft delete', $e->getMessage());
        }
        $this->assertVersionState($record, 7, false, $original);
        self::assertSame($record, SoftDeleteVersionRecord::find(['id' => $record->id]));
        $this->assertVersionDatabaseRow($id, 'delete winner', 7, true);

        $record->delete();
        $original = $record->takeOriginal();
        try {
            SoftDeleteVersionRecord::transaction(static function () use ($record): void {
                $record->restore();
                self::assertSame(9, $record->revision);
                throw new \RuntimeException('rollback live restore');
            });
            self::fail('恢复事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback live restore', $e->getMessage());
        }
        $this->assertVersionState($record, 8, true, $original);
        self::assertNull(SoftDeleteVersionRecord::find(['id' => $record->id]));
        $visible = SoftDeleteVersionRecord::query()->withTrashed()->where('id', $record->id)->first();
        self::assertNotNull($visible);
        self::assertSame(8, $visible->revision);
        self::assertNotNull($visible->deleted_at);
        $this->assertVersionDatabaseRow($id, 'delete winner', 8, false);
    }

    /** @param array<string, mixed> $original */
    private function assertVersionState(SoftDeleteVersionRecord $record, int $revision, bool $deleted, array $original): void
    {
        self::assertFalse($record->isNew());
        self::assertSame($revision, $record->revision);
        self::assertSame($deleted, $record->deleted_at !== null);
        self::assertSame($original, $record->takeOriginal());
    }

    private function assertVersionDatabaseRow(int $id, string $name, int $revision, bool $active): void
    {
        $database = $this->versionDatabaseRow($id);
        self::assertSame($name, $database['name']);
        self::assertSame($revision, $database['revision']);
        self::assertSame($active, $database['deleted_at'] === null);
    }

    private function advanceVersionFromOtherConnection(int $id, int $expectedVersion): void
    {
        self::assertNotNull($this->otherPdo);
        $quote = $this->otherPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        $statement = $this->otherPdo->prepare(
            'UPDATE ' . $quote . SoftDeleteVersionRecord::table() . $quote
            . ' SET revision = revision + 1 WHERE id = ? AND revision = ? AND deleted_at IS NOT NULL',
        );
        $statement->execute([$id, $expectedVersion]);
        self::assertSame(1, $statement->rowCount(), '远端连接必须制造已软删除行的版本冲突');
    }

    /** @return array{string, ?string, ?string}|null */
    private function liveConnectionConfig(string $driver): ?array
    {
        $prefixes = ['ORM_TEST_' . strtoupper($driver), 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver)];
        foreach ($prefixes as $prefix) {
            $dsn = getenv($prefix . '_DSN');
            if (!is_string($dsn) || $dsn === '') {
                continue;
            }
            $user = getenv($prefix . '_USER');
            $password = getenv($prefix . '_PASSWORD');

            return [$dsn, $user === false ? null : $user, $password === false ? null : $password];
        }

        return null;
    }

    private function connectLive(string $dsn, ?string $user, ?string $password, string $driver): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::assertSame($driver, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        return $pdo;
    }

    private function createSchema(PDO $pdo, string $driver, string $table): void
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $q = static fn (string $identifier): string => $quote . $identifier . $quote;
        $primary = match ($driver) {
            'mysql' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'pgsql' => 'BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $time = $driver === 'sqlite' ? 'TEXT' : ($driver === 'mysql' ? 'DATETIME' : 'TIMESTAMP');
        $pdo->exec(sprintf(
            'CREATE TABLE %s (%s %s, %s VARCHAR(255) NOT NULL, %s %s NULL, %s INTEGER NOT NULL)',
            $q($table), $q('id'), $primary, $q('name'), $q('deleted_at'), $time, $q('revision'),
        ));
        if ($driver === 'sqlite') {
            $pdo->exec(sprintf(
                'CREATE TABLE %s (%s %s, %s VARCHAR(255) NOT NULL, %s %s NULL)',
                $q('soft_rows'), $q('id'), $primary, $q('name'), $q('deleted_at'), $time,
            ));
        }
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function databaseRows(): array
    {
        return $this->pdo->query('SELECT name, deleted_at FROM soft_rows ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string, mixed> */
    private function versionDatabaseRow(int $id): array
    {
        $table = SoftDeleteVersionRecord::table();
        $quote = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        $statement = $this->pdo->prepare(
            'SELECT name, deleted_at, revision FROM ' . $quote . $table . $quote . ' WHERE id = ?',
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['revision'] = (int) $row['revision'];

        return $row;
    }
}
