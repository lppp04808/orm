<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use Pixiaoka\Orm\Cast\BcDecimal;
use DateTimeZone;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Session;
use Pixiaoka\Orm\Metadata\MetadataReader;
use PDO;
use PHPUnit\Framework\TestCase;

enum SampleStatus: int
{
    case Off = 0;
    case On = 1;
}

final class SurrogateItem extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public bool $active;

    #[Column]
    public array $tags;

    #[Column]
    public BcDecimal $amount;

    #[Column]
    public SampleStatus $status;

    #[Column]
    #[Temporal]
    public DateTimeImmutable $happened_at;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'surrogate_item';
    }
}

final class Asset extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public int $user_id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public string $key;

    #[Column]
    public int $val;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'asset';
    }
}

final class BoundaryKey extends Entity
{
    #[Column, PrimaryKey(order: 1)]
    public string $left;

    #[Column, PrimaryKey(order: 2)]
    public string $right;

    #[Column]
    public string $label;

    public static function table(): string { return 'boundary_key'; }
}

final class NonAlphabeticalCompositeKey extends Entity
{
    #[Column, PrimaryKey(order: 1)]
    public int $tenant_id;

    #[Column, PrimaryKey(order: 2)]
    public int $app_id;

    #[Column]
    public string $label;

    public static function table(): string { return 'non_alphabetical_composite_key'; }
}

final class Flag extends Entity
{
    #[Column]
    #[PrimaryKey]
    public bool $on;

    #[Column]
    public string $label;

    #[Column]
    public int $created_at;

    public static function table(): string
    {
        return 'flag';
    }
}

final class MixedKey extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true, order: 1)]
    public int $id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public int $tenant_id;

    public static function table(): string
    {
        return 'mixed_key';
    }
}

final class HookedAsset extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public int $user_id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public string $key;

    #[Column]
    public int $val;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public int $hookLog = 0;

    public function beforeInsert(): void { $this->hookLog = 1; }
    public function afterInsert(): void { $this->hookLog = 2; }
    public function beforeUpdate(): void { $this->hookLog = 3; }

    public static function table(): string
    {
        return 'hooked_asset';
    }
}

final class OrmKernelTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE surrogate_item (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                active INTEGER NOT NULL,
                tags TEXT NOT NULL,
                amount TEXT NOT NULL,
                status INTEGER NOT NULL,
                happened_at TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE asset (
                user_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                val INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, key)
            )'
        );
        $this->pdo->exec('CREATE TABLE flag ("on" INTEGER NOT NULL PRIMARY KEY, label TEXT NOT NULL, created_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE boundary_key (left TEXT NOT NULL, right TEXT NOT NULL, label TEXT NOT NULL, PRIMARY KEY (left, right))');
        $this->pdo->exec('CREATE TABLE non_alphabetical_composite_key (
            tenant_id INTEGER NOT NULL, app_id INTEGER NOT NULL, label TEXT NOT NULL,
            PRIMARY KEY (tenant_id, app_id))');
        $this->pdo->exec('CREATE TABLE hooked_asset (
            user_id INTEGER NOT NULL, key TEXT NOT NULL, val INTEGER NOT NULL,
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, key))');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    // —— 基础 CRUD ——

    public function testCacheIdentityPreservesCompositeKeyBoundariesAndTypes(): void
    {
        BoundaryKey::insertAll([
            ['left' => "a\0b", 'right' => 'c', 'label' => 'first'],
            ['left' => 'a', 'right' => "b\0c", 'label' => 'second'],
        ]);
        $first = BoundaryKey::find(['left' => "a\0b", 'right' => 'c']);
        $second = BoundaryKey::find(['left' => 'a', 'right' => "b\0c"]);
        self::assertSame('first', $first?->label);
        self::assertSame('second', $second?->label);
        self::assertNotSame($first, $second);

        Flag::insertAll([['on' => true, 'label' => 'enabled']]);
        $cached = Flag::find(['on' => true]);
        $sameBoolean = Flag::find(['on' => 1]);
        self::assertSame($cached, $sameBoolean, '同一布尔主键规范化后必须复用缓存对象');
        $flagPdo = new PDO('sqlite::memory:');
        $flagPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $flagPdo->exec('CREATE TABLE flag ("on" INTEGER NOT NULL PRIMARY KEY, label TEXT NOT NULL, created_at INTEGER NOT NULL)');
        $flagPdo->exec("INSERT INTO flag (\"on\", label, created_at) VALUES (1, 'other', 0)");
        Session::use(new EntityGateway(new PdoConnection($flagPdo)));
        try {
            $cached->refresh();
            self::fail('不同 Gateway 不得共享实体缓存');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('enabled', $cached?->label);
    }

    public function testSurrogateInsertFillsGeneratedKeyAndCastsTypes(): void
    {
        $item = new SurrogateItem();
        $item->name = 'alpha';
        $item->active = false;
        $item->tags = ['a', 'b'];
        $item->amount = new BcDecimal('1.50');
        $item->status = SampleStatus::Off;
        $item->happened_at = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));

        $this->gateway->insert($item);

        self::assertSame(1, $item->id);
        $found = $this->gateway->find(SurrogateItem::class, ['id' => '1']);
        self::assertInstanceOf(SurrogateItem::class, $found);
        self::assertFalse($found->active);
        self::assertSame(['a', 'b'], $found->tags);
        self::assertSame('1.50', (string) $found->amount);
        self::assertSame(SampleStatus::Off, $found->status);
        self::assertSame('2026-01-02 03:04:05', $found->happened_at->format('Y-m-d H:i:s'));
    }

    public function testCallerCannotSupplyGeneratedKey(): void
    {
        $item = $this->item();
        $item->id = 9;

        $this->expectException(IdentityException::class);
        $this->gateway->insert($item);
    }

    public function testCompositeNaturalKeyKeepsZeroAndEmptyString(): void
    {
        $asset = new Asset();
        $asset->user_id = 0;
        $asset->key = '';
        $asset->val = 3;

        $this->gateway->insert($asset);
        $found = $this->gateway->find(Asset::class, ['user_id' => 0, 'key' => '']);

        self::assertInstanceOf(Asset::class, $found);
        self::assertSame(0, $found->user_id);
        self::assertSame('', $found->key);
        self::assertSame(3, $found->val);
    }

    public function testFindRejectsPartialCompositeKeyWithoutQuery(): void
    {
        $asset = new Asset();
        $asset->user_id = 1;
        $asset->key = 'vip';
        $asset->val = 1;
        $this->gateway->insert($asset);

        try {
            $this->gateway->find(Asset::class, ['user_id' => 1]);
            self::fail('缺列应该拒绝');
        } catch (IdentityException) {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM asset')->fetchColumn();
            self::assertSame(1, $count);
        }
    }

    public function testUpdateAndDeleteRequireFullIdentity(): void
    {
        $asset = new Asset();
        $asset->user_id = 7;
        $asset->key = 'vip';
        $asset->val = 1;
        $this->gateway->insert($asset);

        $asset->val = 9;
        $this->gateway->update($asset);
        $found = $this->gateway->find(Asset::class, ['user_id' => 7, 'key' => 'vip']);
        self::assertSame(9, $found->val);

        $partial = new Asset();
        $partial->user_id = 7;
        $partial->val = 0;
        try {
            $this->gateway->update($partial);
            self::fail('缺主键列不应该更新');
        } catch (IdentityException) {
        }

        $still = $this->gateway->find(Asset::class, ['user_id' => 7, 'key' => 'vip']);
        self::assertSame(9, $still->val);

        $this->gateway->delete($asset);
        self::assertNull($this->gateway->find(Asset::class, ['user_id' => 7, 'key' => 'vip']));
    }

    public function testFalseIsALegalKey(): void
    {
        $flag = new Flag();
        $flag->on = false;
        $flag->label = 'no';

        $this->gateway->insert($flag);

        $found = $this->gateway->find(Flag::class, ['on' => 0]);
        self::assertInstanceOf(Flag::class, $found);
        self::assertFalse($found->on);
    }

    public function testInvalidNumberDoesNotBecomeZero(): void
    {
        $this->pdo->exec("INSERT INTO surrogate_item (name, active, tags, amount, status, happened_at, created_at) VALUES ('x', 1, '[]', 'nope', 1, '2026-01-02 00:00:00', 0)");

        $this->expectException(CastException::class);
        $this->gateway->find(SurrogateItem::class, ['id' => 1]);
    }

    public function testBoolStringZeroIsFalseNotTrue(): void
    {
        $this->pdo->exec("INSERT INTO surrogate_item (name, active, tags, amount, status, happened_at, created_at) VALUES ('x', 0, '[]', '1', 0, '2026-01-02 00:00:00', 0)");
        $found = $this->gateway->find(SurrogateItem::class, ['id' => 1]);

        self::assertFalse($found->active);
    }

    // —— Active Record ——

    public function testSaveInsertsNewRowThenUpdatesFoundRow(): void
    {
        $asset = new Asset();
        self::assertTrue($asset->isNew());
        $asset->user_id = 4;
        $asset->key = 'vip';
        $asset->val = 1;
        $asset->save();
        self::assertFalse($asset->isNew());

        $found = Asset::find(['user_id' => 4, 'key' => 'vip']);
        self::assertInstanceOf(Asset::class, $found);
        self::assertFalse($found->isNew());
        $found->val = 8;
        $found->save();

        $again = Asset::find(['user_id' => 4, 'key' => 'vip']);
        self::assertSame(8, $again->val);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM asset')->fetchColumn());
    }

    public function testInsertRejectsPersistedRowAndDeleteRejectsNewRow(): void
    {
        $asset = new Asset();
        $asset->user_id = 4;
        $asset->key = 'a';
        $asset->val = 1;
        $asset->insert();

        $this->expectException(IdentityException::class);
        $asset->insert();
    }

    public function testSaveOfSurrogateFillsKey(): void
    {
        $item = $this->item();
        $item->save();

        self::assertFalse($item->isNew());
        self::assertSame(1, $item->id);
        $item->name = 'beta';
        $item->save();

        $found = SurrogateItem::find(['id' => 1]);
        self::assertSame('beta', $found->name);
        $found->delete();
        self::assertNull(SurrogateItem::find(['id' => 1]));
    }

    public function testRefillFailureLeavesRowNew(): void
    {
        Session::use(new EntityGateway(new PdoConnection($this->pdo, static fn (): string => '')));
        $item = $this->item();

        try {
            $item->save();
            self::fail('回填失败必须算失败');
        } catch (IdentityException $e) {
            self::assertTrue($e->rowMayExist);
            self::assertTrue($item->isNew());
        }
    }

    // —— 查询 ——

    public function testQueryWhereAndOrderBy(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i * 10;
            $a->save();
        }

        $all = Asset::query()->where('key', 'vip')->orderBy('user_id', 'DESC')->get();
        self::assertCount(3, $all);
        self::assertSame(3, $all[0]->user_id);
    }

    public function testQueryLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        $page = Asset::query()->where('key', 'vip')->orderBy('user_id')->limit(2)->offset(1)->get();
        self::assertCount(2, $page);
        self::assertSame(2, $page[0]->user_id);
        self::assertSame(3, $page[1]->user_id);
    }

    public function testQueryWhereIn(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        $res = Asset::query()->whereIn('user_id', [1, 3])->orderBy('user_id')->get();
        self::assertCount(2, $res);
        self::assertSame(1, $res[0]->user_id);
        self::assertSame(3, $res[1]->user_id);
    }

    public function testQueryCount(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        self::assertSame(3, Asset::query()->where('key', 'vip')->count());
    }

    // —— 批量 ——

    public function testBatchInsert(): void
    {
        Asset::insertAll([
            ['user_id' => 1, 'key' => 'a', 'val' => 1],
            ['user_id' => 2, 'key' => 'a', 'val' => 2],
            ['user_id' => 3, 'key' => 'a', 'val' => 3],
        ]);

        $all = Asset::query()->where('key', 'a')->orderBy('user_id')->get();
        self::assertCount(3, $all);
        self::assertSame(1, $all[0]->val);
        self::assertSame(3, $all[2]->val);
    }

    // —— 时间戳 ——

    public function testTimestampsAreAutoFilled(): void
    {
        $before = time();

        $asset = new Asset();
        $asset->user_id = 5;
        $asset->key = 'vip';
        $asset->val = 1;
        $asset->save();

        self::assertGreaterThanOrEqual($before, $asset->created_at);
        self::assertSame(0, $asset->updated_at);

        $asset->val = 2;
        $asset->save();
        self::assertGreaterThanOrEqual($before, $asset->updated_at);
    }

    // —— 事务 ——

    public function testTransactionCommits(): void
    {
        Asset::transaction(function (): void {
            $a = new Asset();
            $a->user_id = 10;
            $a->key = 'a';
            $a->val = 1;
            $a->save();
        });

        self::assertNotNull(Asset::find(['user_id' => 10, 'key' => 'a']));
    }

    public function testTransactionRollsBack(): void
    {
        try {
            Asset::transaction(function (): void {
                $a = new Asset();
                $a->user_id = 11;
                $a->key = 'a';
                $a->val = 1;
                $a->save();
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull(Asset::find(['user_id' => 11, 'key' => 'a']));
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM asset')->fetchColumn();
        self::assertSame(0, $count);
    }

    // —— 删除后可重新插入 ——

    public function testDeleteThenSaveReinserts(): void
    {
        $asset = new Asset();
        $asset->user_id = 9;
        $asset->key = 'vip';
        $asset->val = 5;
        $asset->save();
        self::assertFalse($asset->isNew());

        $asset->delete();
        self::assertTrue($asset->isNew());
        self::assertNull(Asset::find(['user_id' => 9, 'key' => 'vip']));

        $asset->save();
        self::assertFalse($asset->isNew());
        $found = Asset::find(['user_id' => 9, 'key' => 'vip']);
        self::assertInstanceOf(Asset::class, $found);
    }

    public function testDeleteAndReinsertInsideTransactionRestoresIdentityBaseline(): void
    {
        $asset = new Asset();
        $asset->user_id = 9;
        $asset->key = 'vip';
        $asset->val = 5;
        $asset->save();
        $baseline = $asset->takeOriginal();
        try {
            Asset::transaction(function () use ($asset): void {
                $asset->delete();
                $asset->save();
                self::assertSame($asset, Asset::find(['user_id' => 9, 'key' => 'vip']));
                throw new \RuntimeException('rollback reinsert');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback reinsert', $e->getMessage());
        }
        self::assertFalse($asset->isNew());
        self::assertSame($baseline, $asset->takeOriginal());
        self::assertSame($asset, Asset::find(['user_id' => 9, 'key' => 'vip']));
        self::assertSame(5, Asset::find(['user_id' => 9, 'key' => 'vip'])?->val);
    }

    public function testNonAlphabeticalCompositeKeyRollbackRestoresDeleteReinsertDelete(): void
    {
        $entity = $this->compositeKeyEntity(7, 3, 'committed');
        $entity->save();
        $baseline = $entity->takeOriginal();
        try {
            NonAlphabeticalCompositeKey::transaction(function () use ($entity): void {
                $entity->delete();
                $entity->label = 'replacement';
                $entity->save();
                self::assertFalse($entity->isNew());
                self::assertSame($entity, $this->findCompositeKey(7, 3));
                $entity->delete();
                self::assertTrue($entity->isNew());
                self::assertNull($this->findCompositeKey(7, 3));
                throw new \RuntimeException('rollback non-alphabetical reinsert');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback non-alphabetical reinsert', $e->getMessage());
        }

        self::assertFalse($entity->isNew());
        self::assertSame('committed', $entity->label);
        self::assertSame($baseline, $entity->takeOriginal());
        self::assertSame($entity, $this->findCompositeKey(7, 3));
        self::assertSame([['committed']], $this->pdo->query(
            'SELECT label FROM non_alphabetical_composite_key WHERE tenant_id = 7 AND app_id = 3',
        )->fetchAll(PDO::FETCH_NUM));
    }

    public function testNonAlphabeticalCompositeKeyRollbackRestoresDistinctReplacementAfterSecondDelete(): void
    {
        $entity = $this->compositeKeyEntity(9, 1, 'committed');
        $entity->save();
        $baseline = $entity->takeOriginal();
        $replacement = null;
        $duplicate = null;
        try {
            NonAlphabeticalCompositeKey::transaction(function () use ($entity, &$replacement, &$duplicate): void {
                $entity->delete();
                $replacement = $this->compositeKeyEntity(9, 1, 'replacement');
                $replacement->save();
                $this->gateway->clearCache(NonAlphabeticalCompositeKey::class);
                $duplicate = $this->findCompositeKey(9, 1);
                self::assertNotNull($duplicate);
                self::assertNotSame($replacement, $duplicate);
                self::assertSame('replacement', $duplicate->label);
                $replacement->label = 'changed';
                $replacement->save();
                $replacement->delete();
                throw new \RuntimeException('rollback distinct non-alphabetical replacement');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback distinct non-alphabetical replacement', $e->getMessage());
        }

        self::assertNotNull($replacement);
        self::assertNotNull($duplicate);
        foreach ([$entity, $replacement, $duplicate] as $restored) {
            self::assertFalse($restored->isNew());
            self::assertSame('committed', $restored->label);
            self::assertSame($baseline, $restored->takeOriginal());
        }
        self::assertSame($entity, $this->findCompositeKey(9, 1));
        self::assertSame([['committed']], $this->pdo->query(
            'SELECT label FROM non_alphabetical_composite_key WHERE tenant_id = 9 AND app_id = 1',
        )->fetchAll(PDO::FETCH_NUM));
    }

    public function testNonAlphabeticalCompositeKeyRollbackRestoresInsertThenDelete(): void
    {
        $entity = $this->compositeKeyEntity(8, 2, 'temporary');
        $queried = null;
        try {
            NonAlphabeticalCompositeKey::transaction(function () use ($entity, &$queried): void {
                $entity->save();
                self::assertFalse($entity->isNew());
                self::assertSame($entity, $this->findCompositeKey(8, 2));
                $this->gateway->clearCache(NonAlphabeticalCompositeKey::class);
                $queried = $this->findCompositeKey(8, 2);
                self::assertNotNull($queried);
                self::assertNotSame($entity, $queried);
                $entity->delete();
                self::assertTrue($entity->isNew());
                self::assertNull($this->findCompositeKey(8, 2));
                $transactions = new \ReflectionProperty(EntityGateway::class, 'transactions');
                $manager = $transactions->getValue($this->gateway);
                $history = new \ReflectionProperty($manager, 'txnNewIdentities');
                $identityHistory = $history->getValue($manager);
                self::assertSame([], $identityHistory[Session::context()]);
                throw new \RuntimeException('rollback non-alphabetical insert');
            });
            self::fail('必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback non-alphabetical insert', $e->getMessage());
        }

        self::assertTrue($entity->isNew());
        self::assertSame('temporary', $entity->label);
        self::assertSame(8, $entity->tenant_id);
        self::assertSame(2, $entity->app_id);
        self::assertSame([], $entity->takeOriginal());
        self::assertNotNull($queried);
        self::assertTrue($queried->isNew());
        self::assertSame('temporary', $queried->label);
        self::assertSame([], $queried->takeOriginal());
        self::assertNull($this->findCompositeKey(8, 2));
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM non_alphabetical_composite_key WHERE tenant_id = 8 AND app_id = 2',
        )->fetchColumn());
    }

    // —— 幂等更新 ——

    public function testIdempotentUpdateDoesNotThrow(): void
    {
        $asset = new Asset();
        $asset->user_id = 8;
        $asset->key = 'vip';
        $asset->val = 7;
        $asset->save();

        $found = Asset::find(['user_id' => 8, 'key' => 'vip']);
        $found->val = 7;
        $found->save();
        $again = Asset::find(['user_id' => 8, 'key' => 'vip']);
        self::assertSame(7, $again->val);
    }

    // —— toArray / refresh ——

    public function testToArrayAndRefresh(): void
    {
        $asset = new Asset();
        $asset->user_id = 6;
        $asset->key = 'vip';
        $asset->val = 9;
        $asset->save();

        $arr = $asset->toArray();
        self::assertSame(6, $arr['user_id']);
        self::assertSame('vip', $arr['key']);
        self::assertSame(9, $arr['val']);

        $this->pdo->exec("UPDATE asset SET val = 44 WHERE user_id = 6 AND key = 'vip'");
        $asset->refresh();
        self::assertSame(44, $asset->val);
    }

    // —— 元数据拒绝 ——

    public function testGeneratedAndNaturalKeysCannotMix(): void
    {
        $this->expectException(MetadataException::class);
        (new MetadataReader())->read(MixedKey::class);
    }

    // —— 回填失败 ——

    public function testRefillFailureIsAFailure(): void
    {
        $gateway = new EntityGateway(new PdoConnection($this->pdo, static fn (): string => ''));

        $item = $this->item();
        try {
            $gateway->insert($item);
            self::fail('回填失败必须算失败');
        } catch (IdentityException $e) {
            self::assertTrue($e->rowMayExist);
            self::assertFalse((new \ReflectionProperty($item, 'id'))->isInitialized($item));
        }
    }

    // —— OR 条件 ——

    public function testOrGroup(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i * 10;
            $a->save();
        }

        $res = Asset::query()
            ->where('key', 'vip')
            ->orGroup(fn (Builder $q) => $q->where('user_id', 1)->where('user_id', 3))
            ->orderBy('user_id')
            ->get();

        self::assertCount(2, $res);
        self::assertSame(1, $res[0]->user_id);
        self::assertSame(3, $res[1]->user_id);
    }

    // —— 事件钩子 ——

    public function testHooksFireOnInsertAndUpdate(): void
    {
        $h = new HookedAsset();
        $h->user_id = 1;
        $h->key = 'hook';
        $h->val = 1;
        $h->save();
        self::assertSame(2, $h->hookLog);

        $found = HookedAsset::find(['user_id' => 1, 'key' => 'hook']);
        $found->val = 9;
        $found->save();
        self::assertSame(3, $found->hookLog);
    }

    // —— 脏跟踪：全量写不覆盖没变的字段 ----

    public function testUpdateDoesNotReWriteRaw(): void
    {
        $pdo = $this->pdo;
        $a = new Asset();
        $a->user_id = 50;
        $a->key = 'vip';
        $a->val = 1;
        $a->save();

        $found = Asset::find(['user_id' => 50, 'key' => 'vip']);
        $found->val = 8;
        $found->save();

        $raw = $pdo->query("SELECT created_at FROM asset WHERE user_id = 50 AND key = 'vip'")->fetchColumn();
        self::assertSame($a->created_at, (int) $raw);
        self::assertGreaterThan(0, $found->updated_at);
    }

    // —— 缓存 ——

    public function testCacheReturnsSameInstanceForSameIdentity(): void
    {
        $a = new Asset();
        $a->user_id = 60;
        $a->key = 'vip';
        $a->val = 1;
        $a->save();

        $one = Asset::find(['user_id' => 60, 'key' => 'vip']);
        $two = Asset::find(['user_id' => 60, 'key' => 'vip']);
        self::assertSame($one, $two);

        $one->val = 9;
        self::assertSame(9, $two->val);
    }

    // —— 批量插入 Entity 对象 ——

    public function testBatchInsertFromEntities(): void
    {
        $a1 = new Asset();
        $a1->user_id = 70;
        $a1->key = 'a';
        $a1->val = 1;

        $a2 = new Asset();
        $a2->user_id = 71;
        $a2->key = 'a';
        $a2->val = 2;

        Asset::insertAll([$a1, $a2]);

        $all = Asset::query()->where('key', 'a')->orderBy('user_id')->get();
        self::assertCount(2, $all);
        self::assertFalse($a1->isNew());
        self::assertFalse($a2->isNew());
    }

    private function compositeKeyEntity(int $tenantId, int $appId, string $label): NonAlphabeticalCompositeKey
    {
        $entity = new NonAlphabeticalCompositeKey();
        $entity->tenant_id = $tenantId;
        $entity->app_id = $appId;
        $entity->label = $label;

        return $entity;
    }

    private function findCompositeKey(int $tenantId, int $appId): ?NonAlphabeticalCompositeKey
    {
        return NonAlphabeticalCompositeKey::find(['tenant_id' => $tenantId, 'app_id' => $appId]);
    }

    private function item(): SurrogateItem
    {
        $item = new SurrogateItem();
        $item->name = 'alpha';
        $item->active = true;
        $item->tags = [];
        $item->amount = new BcDecimal('1');
        $item->status = SampleStatus::On;
        $item->happened_at = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));

        return $item;
    }

    // —— P0-1：whereOp 非法运算符拒绝 ——

    public function testWhereOpRejectsInvalidOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Asset::query()->whereOp('val', 'DROP TABLE', 1);
    }

    public function testWhereOpRejectsNonArrayBetween(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Asset::query()->whereOp('val', 'BETWEEN', 5);
    }

    public function testWhereOpRejectsBetweenWithWrongArity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Asset::query()->whereOp('val', 'BETWEEN', [1, 2, 3]);
    }

    // —— P0-2：count 不受 LIMIT / OFFSET 影响 ——

    public function testCountIgnoresLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        self::assertSame(5, Asset::query()->where('key', 'vip')->limit(2)->offset(1)->count());
        $sql = Asset::query()->where('key', 'vip')->limit(2)->offset(1)->countSql()['sql'];
        self::assertStringNotContainsStringIgnoringCase('LIMIT', $sql);
        self::assertStringNotContainsStringIgnoringCase('OFFSET', $sql);
        self::assertStringNotContainsStringIgnoringCase('ORDER BY', $sql);
    }

    // —— P2-3：whereNotNull / whereNull / BETWEEN ——

    public function testWhereNotNullAndWhereNull(): void
    {
        $a = new Asset();
        $a->user_id = 1;
        $a->key = 'vip';
        $a->val = 1;
        $a->save();
        $b = new Asset();
        $b->user_id = 2;
        $b->key = 'vip';
        $b->val = 2;
        $b->save();

        $sql = Asset::query()->whereNotNull('user_id')->build()['sql'];
        self::assertStringContainsStringIgnoringCase('"user_id" IS NOT NULL', $sql);

        $null = Asset::query()->whereNull('user_id')->build();
        self::assertStringContainsStringIgnoringCase('IS NULL', $null['sql']);

        $both = Asset::query()->whereNotNull('user_id')->get();
        self::assertCount(2, $both);
        // 全部行 user_id 非空时 whereNull 命中 0 行
        self::assertSame([], Asset::query()->whereNull('user_id')->get());
    }

    public function testWhereOpBetween(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        $res = Asset::query()->where('key', 'vip')->whereOp('user_id', 'BETWEEN', [2, 4])->orderBy('user_id')->get();
        self::assertCount(3, $res);
        self::assertSame(2, $res[0]->user_id);
        self::assertSame(4, $res[2]->user_id);

        $sql = Asset::query()->whereOp('user_id', 'BETWEEN', [2, 4])->build()['sql'];
        self::assertStringContainsStringIgnoringCase('"user_id" BETWEEN :q0 AND :q1', $sql);
    }

    // —— P2-2：orGroup 嵌套 ——

    public function testOrGroupNestedDoesNotLoseConditions(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $a = new Asset();
            $a->user_id = $i;
            $a->key = 'vip';
            $a->val = $i;
            $a->save();
        }

        // (user_id = 1) OR ((user_id = 2) OR (user_id = 4)) —— 子组条件不再丢失
        $res = Asset::query()
            ->orGroup(fn (Builder $q) => $q->where('user_id', 1)
                ->orGroup(fn (Builder $s) => $s->where('user_id', 2)->where('user_id', 4)))
            ->orderBy('user_id')
            ->get();

        self::assertSame([1, 2, 4], array_map(static fn (Asset $a): int => $a->user_id, $res));
    }

    // —— P2-1：first() 带 LIMIT 1 ——

    public function testFirstEmitsLimitOne(): void
    {
        $a = new Asset();
        $a->user_id = 1;
        $a->key = 'vip';
        $a->val = 1;
        $a->save();

        // SQL 层断言：first() 生成的查询带 LIMIT 1
        $recorder = new RecordingConnection(new PdoConnection($this->pdo));
        $gateway = new EntityGateway($recorder);
        Session::use($gateway);
        try {
            $first = Asset::query()->where('key', 'vip')->first();
            self::assertInstanceOf(Asset::class, $first);
            self::assertStringContainsStringIgnoringCase('LIMIT 1', $recorder->lastSql);
        } finally {
            Session::use($this->gateway);
        }

        // first() 不改变原 Builder 的状态（clone 不污染自身）
        $builder = Asset::query()->where('key', 'vip');
        self::assertCount(1, $builder->get());
        self::assertSame(1, Asset::query()->where('key', 'vip')->count());
    }

    // —— P2-4：RowCache LRU 去重 ——

    public function testRowCacheSetDuplicateKeyKeepsLruOrder(): void
    {
        $a = new Asset();
        $a->user_id = 1;
        $a->key = 'vip';
        $a->val = 1;
        $a->save();

        // 同一身份重复 set 不重复计 key；缓存命中正常
        $one = Asset::find(['user_id' => 1, 'key' => 'vip']);
        $two = Asset::find(['user_id' => 1, 'key' => 'vip']);
        self::assertSame($one, $two);
    }

    // —— P2-5：quoteIdentifier 支持 schema ——

    public function testQuoteIdentifierSupportsSchema(): void
    {
        $conn = new PdoConnection($this->pdo);
        self::assertSame('"public"."asset"', $conn->quoteIdentifier('public.asset'));
        self::assertSame('"asset"', $conn->quoteIdentifier('asset'));

        $this->expectException(\InvalidArgumentException::class);
        $conn->quoteIdentifier('public.as"set');
    }

    // —— P1-2：批量插入时间戳只在未提供时填充 ——

    public function testBatchInsertRespectsProvidedTimestamps(): void
    {
        $custom = 1000000;
        Asset::insertAll([
            ['user_id' => 1, 'key' => 'a', 'val' => 1, 'created_at' => $custom],
            ['user_id' => 2, 'key' => 'a', 'val' => 2],
        ]);

        $one = Asset::find(['user_id' => 1, 'key' => 'a']);
        self::assertSame($custom, $one->created_at);
        $two = Asset::find(['user_id' => 2, 'key' => 'a']);
        self::assertGreaterThan(0, $two->created_at);
    }

    public function testBatchInsertSurrogateIdsRefilledPerRow(): void
    {
        $e1 = $this->item();
        $e1->name = 'one';
        $e2 = $this->item();
        $e2->name = 'two';
        $e3 = $this->item();
        $e3->name = 'three';

        SurrogateItem::insertAll([$e1, $e2, $e3]);

        // P0-3：sqlite 支持 RETURNING，每行 ID 独立回填，不再串号
        self::assertNotSame($e1->id, $e2->id);
        self::assertNotSame($e2->id, $e3->id);
        self::assertSame([1, 2, 3], [$e1->id, $e2->id, $e3->id]);
        self::assertFalse($e1->isNew());
        self::assertFalse($e2->isNew());
        self::assertFalse($e3->isNew());
    }

    public function testBatchInsertArrayRowsWithGeneratedColumnRefillsNothing(): void
    {
        // 数组行没有实体可回填，只验证写入成功且行数正确
        SurrogateItem::insertAll([
            ['name' => 'x', 'active' => 1, 'tags' => [], 'amount' => '1', 'status' => 1, 'happened_at' => '2026-01-02 03:04:05'],
            ['name' => 'y', 'active' => 1, 'tags' => [], 'amount' => '2', 'status' => 1, 'happened_at' => '2026-01-02 03:04:05'],
        ]);

        self::assertSame(2, SurrogateItem::query()->count());
    }
}

/**
 * 记录最后一条 SQL 的 Connection 装饰器，测试断言生成的 SQL 用。
 */
final class RecordingConnection implements \Pixiaoka\Orm\Gateway\Connection
{
    public string $lastSql = '';

    public function __construct(
        private readonly \Pixiaoka\Orm\Gateway\Connection $inner,
    ) {
    }

    public function execute(string $sql, array $params): int
    {
        $this->lastSql = $sql;

        return $this->inner->execute($sql, $params);
    }

    public function queryOne(string $sql, array $params): ?array
    {
        $this->lastSql = $sql;

        return $this->inner->queryOne($sql, $params);
    }

    public function queryAll(string $sql, array $params): array
    {
        $this->lastSql = $sql;

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
        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        return $this->inner->rollback();
    }
}