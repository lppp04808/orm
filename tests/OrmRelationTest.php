<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\HasOne as HasOneAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Relation\HasOne;
use Pixiaoka\Orm\Session;

final class RelationCustomer extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return 'relation_customer'; }

    #[HasOneAttribute(target: RelationProfile::class, foreignKey: ['customer_id'])]
    public function profile(): HasOne { return $this->makeRelation(); }

    #[HasManyAttribute(target: RelationOrder::class, foreignKey: ['customer_id'])]
    public function orders(): HasMany { return $this->makeRelation(); }
}

final class RelationProfile extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public int $customer_id;
    #[Column]
    public string $bio;

    public static function table(): string { return 'relation_profile'; }

    #[BelongsToAttribute(target: RelationCustomer::class, localKey: ['customer_id'])]
    public function customer(): BelongsTo { return $this->makeRelation(); }
}

final class RelationOrder extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public ?int $customer_id = null;
    #[Column]
    public string $status;
    #[Column]
    public int $amount;

    public static function table(): string { return 'relation_order'; }

    #[BelongsToAttribute(target: RelationCustomer::class, localKey: ['customer_id'])]
    public function customer(): BelongsTo { return $this->makeRelation(); }
}

final class RelationOrderKeyCustomer extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    public static function table(): string { return 'relation_customer'; }

    #[HasManyAttribute(target: RelationOrder::class, foreignKey: ['id'])]
    public function orders(): HasMany { return $this->makeRelation(); }
}

final class RelationOrderKeyOrder extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    public static function table(): string { return 'relation_customer'; }

    #[BelongsToAttribute(target: RelationOrderKeyCustomer::class)]
    public function customer(): BelongsTo { return $this->makeRelation(); }
}

final class RelationTenant extends Entity
{
    #[Column, PrimaryKey(order: 1)]
    public int $tenant_id;
    #[Column, PrimaryKey(order: 2)]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return 'relation_tenant'; }

    #[HasManyAttribute(target: RelationEntry::class, localKey: ['tenant_id', 'id'], foreignKey: ['owner_tenant', 'owner_id'])]
    public function entries(): HasMany { return $this->makeRelation(); }
}

final class RelationEntry extends Entity
{
    #[Column, PrimaryKey(order: 1)]
    public int $tenant_id;
    #[Column, PrimaryKey(order: 2)]
    public int $id;
    #[Column]
    public int $owner_tenant;
    #[Column]
    public int $owner_id;
    #[Column]
    public string $label;

    public static function table(): string { return 'relation_entry'; }

    #[BelongsToAttribute(target: RelationTenant::class, localKey: ['owner_tenant', 'owner_id'], foreignKey: ['tenant_id', 'id'])]
    public function owner(): BelongsTo { return $this->makeRelation(); }
}

final class InvalidRelation extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    public static function table(): string { return 'invalid_relation'; }

    #[HasManyAttribute(target: RelationOrder::class, localKey: ['id'], foreignKey: ['status'])]
    public function wrongType(): HasMany { return $this->makeRelation(); }
}

final class RelationValueObjectParent extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return 'relation_value_parent'; }

    #[HasManyAttribute(target: RelationValueObjectChild::class, localKey: ['id'], foreignKey: ['parent_id'])]
    public function children(): HasMany { return $this->makeRelation(); }
}

final class RelationValueObjectChild extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public int $parent_id;
    #[Column]
    public DateTimeImmutable $happened_at;
    #[Column]
    public BcDecimal $amount;

    public static function table(): string { return 'relation_value_child'; }
}

final class OrmRelationTest extends TestCase
{
    private EntityGateway $gateway;
    private PDO $pdo;
    private int $selects = 0;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE relation_customer (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE relation_profile (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, bio TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE relation_order (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NULL, status TEXT NOT NULL, amount INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE relation_tenant (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, name TEXT NOT NULL, PRIMARY KEY (tenant_id, id))');
        $this->pdo->exec('CREATE TABLE relation_entry (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, owner_tenant INTEGER NOT NULL, owner_id INTEGER NOT NULL, label TEXT NOT NULL, PRIMARY KEY (tenant_id, id))');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
        $this->selects = 0;
        $this->gateway->subscribe(function (Event $event): void {
            if ($event instanceof SqlEvent && $event->operation === 'SELECT') {
                $this->selects++;
            }
        });
        RelationCustomer::insertAll([['name' => 'alice'], ['name' => 'bob'], ['name' => 'nobody']]);
        RelationProfile::insertAll([
            ['customer_id' => 1, 'bio' => 'first'], ['customer_id' => 1, 'bio' => 'second'],
            ['customer_id' => 99, 'bio' => 'orphan'],
        ]);
        RelationOrder::insertAll([
            ['customer_id' => 1, 'status' => 'paid', 'amount' => 30],
            ['customer_id' => 1, 'status' => 'new', 'amount' => 10],
            ['customer_id' => 1, 'status' => 'paid', 'amount' => 20],
            ['customer_id' => 2, 'status' => 'new', 'amount' => 40],
            ['customer_id' => null, 'status' => 'orphan', 'amount' => 50],
        ]);
        RelationTenant::insertAll([
            ['tenant_id' => 1, 'id' => 7, 'name' => 'A'],
            ['tenant_id' => 2, 'id' => 7, 'name' => 'B'],
            ['tenant_id' => 2, 'id' => 8, 'name' => 'C'],
        ]);
        RelationEntry::insertAll([
            ['tenant_id' => 1, 'id' => 1, 'owner_tenant' => 1, 'owner_id' => 7, 'label' => 'a1'],
            ['tenant_id' => 1, 'id' => 2, 'owner_tenant' => 2, 'owner_id' => 7, 'label' => 'b1'],
            ['tenant_id' => 1, 'id' => 3, 'owner_tenant' => 1, 'owner_id' => 7, 'label' => 'a2'],
            ['tenant_id' => 1, 'id' => 4, 'owner_tenant' => 99, 'owner_id' => 7, 'label' => 'orphan'],
        ]);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testExplicitHasManyAndConstraints(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        $orders = $customer->orders()->query(
            RelationOrder::query()->where('status', 'paid')->orderBy('amount', 'DESC'),
        )->get();
        self::assertSame([30, 20], array_column($orders, 'amount'));
        self::assertSame(30, $customer->orders()->query(
            RelationOrder::query()->orderBy('amount', 'DESC'),
        )->first()->amount);
        self::assertFalse($customer->relationLoaded('orders'));
    }

    public function testHasOneAndBelongsTo(): void
    {
        $profile = RelationCustomer::find(['id' => 1])->profile()->query(
            RelationProfile::query()->orderBy('id'),
        )->first();
        self::assertSame('first', $profile->bio);
        self::assertSame('alice', $profile->customer()->get()->name);
        $orphan = RelationOrder::query()->where('customer_id', 99);
        self::assertCount(0, $orphan->get());
        $missing = new RelationOrder();
        $missing->customer_id = 999;
        self::assertNull($missing->customer()->get());
    }

    public function testEagerLoadingUsesIndependentQueriesAndCachesNullAndEmpty(): void
    {
        $customers = RelationCustomer::query()->orderBy('id')->with('profile', 'orders')->get();
        self::assertSame(3, $this->selects, '主表和每层每个关系各一条 SELECT');
        self::assertSame('first', $customers[0]->loadedRelation('profile')->bio);
        self::assertCount(3, $customers[0]->loadedRelation('orders'));
        self::assertNull($customers[1]->loadedRelation('profile'));
        self::assertTrue($customers[1]->relationLoaded('profile'));
        self::assertSame([], $customers[2]->loadedRelation('orders'));
        self::assertSame($customers[0]->id, $customers[0]->loadedRelation('orders')[0]->customer()->get()->id);
    }

    public function testNestedAndSiblingConstraintsMerge(): void
    {
        $customers = RelationCustomer::query()->with([
            'orders' => fn (Builder $q) => $q->where('status', 'paid'),
            'orders.customer' => fn (Builder $q) => $q->where('name', 'alice'),
        ])->orderBy('id')->get();
        self::assertCount(2, $customers[0]->loadedRelation('orders'));
        self::assertSame([], $customers[1]->loadedRelation('orders'));
        self::assertSame($customers[0], $customers[0]->loadedRelation('orders')[0]->loadedRelation('customer'));
        self::assertSame(3, $this->selects);
    }

    public function testCompositeKeysDoNotCrossTenants(): void
    {
        $tenants = RelationTenant::query()->with('entries.owner')->orderBy('tenant_id')->orderBy('id')->get();
        self::assertSame(['a1', 'a2'], array_column($tenants[0]->loadedRelation('entries'), 'label'));
        self::assertSame(['b1'], array_column($tenants[1]->loadedRelation('entries'), 'label'));
        self::assertSame([], $tenants[2]->loadedRelation('entries'));
        self::assertSame('B', $tenants[1]->loadedRelation('entries')[0]->loadedRelation('owner')->name);
        self::assertSame(3, $this->selects);
    }

    public function testEmptyAndFreshLoad(): void
    {
        self::assertSame([], RelationCustomer::query()->where('id', 999)->with('orders')->get());
        $this->selects = 0;
        self::assertSame([], RelationCustomer::query()->where('id', 999)->with('orders')->get());
        self::assertSame(1, $this->selects, '空主结果不得执行预加载');
        $customer = RelationCustomer::find(['id' => 1]);
        self::assertCount(3, $customer->load('orders')->loadedRelation('orders'));
        self::assertCount(3, $customer->load(['orders' => fn (Builder $q) => $q])->loadedRelation('orders'));
    }

    public function testRejectsAmbiguousOrInvalidRelationLoading(): void
    {
        $this->expectException(RelationException::class);
        RelationCustomer::query()->with(['orders' => fn (Builder $q) => $q->limit(1)])->get();
    }

    public function testRejectsGlobalEagerPaginationBeforeSql(): void
    {
        $this->expectException(RelationException::class);
        RelationCustomer::query()->with(['orders' => fn (Builder $q) => $q->offset(1)])->get();
    }

    public function testRejectsUnsupportedTerminal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RelationCustomer::query()->with('orders')->rows();
    }

    public function testRejectsBadMetadata(): void
    {
        $this->expectException(MetadataException::class);
        $this->gateway->relation(InvalidRelation::class, 'wrongType');
    }

    public function testNullableKeysDoNotExecuteRelationSql(): void
    {
        $order = RelationOrder::query()->whereNull('customer_id')->first();
        self::assertNotNull($order);
        $this->selects = 0;
        self::assertNull($order->customer()->get());
        self::assertNull($order->customer()->first());
        self::assertSame($order, $order->load('customer'));
        self::assertTrue($order->relationLoaded('customer'));
        self::assertNull($order->loadedRelation('customer'));
        self::assertSame(0, $this->selects);

        self::assertNull(RelationOrder::query()->with('customer')->whereNull('customer_id')->first()->loadedRelation('customer'));
        self::assertSame(1, $this->selects);
    }

    public function testUninitializedKeyAndCrossGatewayBuilderAreRejected(): void
    {
        $order = new RelationOrder();
        unset($order->customer_id);
        try {
            $order->customer()->get();
            self::fail('未初始化关联键不得查询');
        } catch (RelationException) {
        }
        $other = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        $this->expectException(\InvalidArgumentException::class);
        RelationCustomer::find(['id' => 1])->orders()->query(new Builder(RelationOrder::class, $other));
    }

    public function testRepeatedTargetsUseOneIdentityAndTargetFieldsFollowDatabase(): void
    {
        $cached = RelationCustomer::find(['id' => 1]);
        $this->pdo->exec("UPDATE relation_customer SET name = 'database-name' WHERE id = 1");
        $this->pdo->exec("UPDATE relation_order SET customer_id = 1 WHERE customer_id IS NULL");
        $beforeSelects = $this->selects;
        $orders = RelationOrder::query()->with('customer')->get();
        self::assertSame($beforeSelects + 2, $this->selects);
        $target = null;
        foreach ($orders as $order) {
            if ($order->customer_id === null) {
                self::assertNull($order->loadedRelation('customer'));
                continue;
            }
            if ($order->customer_id === 1) {
                $target ??= $order->loadedRelation('customer');
                self::assertSame($target, $order->loadedRelation('customer'));
                self::assertSame('database-name', $target->name);
            } else {
                self::assertSame('bob', $order->loadedRelation('customer')->name);
            }
            self::assertSame('database-name', $target->name);
        }
        self::assertSame($cached, $target);
        self::assertSame($cached, RelationCustomer::find(['id' => 1]));
        self::assertSame('database-name', $cached->name);
    }

    public function testEagerRefreshDoesNotTreatEquivalentValueObjectsAsLocalChanges(): void
    {
        $this->pdo->exec('CREATE TABLE relation_value_parent (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE relation_value_child (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, happened_at TEXT NOT NULL, amount TEXT NOT NULL)');
        $parent = new RelationValueObjectParent();
        $parent->name = 'parent';
        $parent->save();
        $child = new RelationValueObjectChild();
        $child->parent_id = $parent->id;
        $child->happened_at = new DateTimeImmutable('2026-08-03 10:20:30', new \DateTimeZone('UTC'));
        $child->amount = new BcDecimal('123.45');
        $child->save();

        $cached = RelationValueObjectChild::find(['id' => $child->id]);
        self::assertNotNull($cached);
        $cached->happened_at = new DateTimeImmutable('2026-08-03 18:20:30.987654', new \DateTimeZone('+08:00'));
        $cached->amount = new BcDecimal('123.45');
        $this->pdo->exec("UPDATE relation_value_child SET happened_at = '2026-08-03 10:20:31', amount = '456.78' WHERE id = {$child->id}");

        $loaded = RelationValueObjectParent::query()->with('children')->where('id', $parent->id)->first();
        self::assertNotNull($loaded);
        $refreshed = $loaded->loadedRelation('children')[0];
        self::assertSame($cached, $refreshed);
        self::assertSame('2026-08-03 10:20:31', $refreshed->happened_at->format('Y-m-d H:i:s'));
        self::assertSame('456.78', (string) $refreshed->amount);
        self::assertSame('2026-08-03 10:20:31', $refreshed->takeOriginal()['happened_at']->format('Y-m-d H:i:s'));
        self::assertSame('456.78', (string) $refreshed->takeOriginal()['amount']);
    }

    public function testEntityCannotReadOrWriteAcrossGateways(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        $other = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($other);
        foreach (['load', 'orders', 'save', 'refresh', 'delete'] as $method) {
            try {
                match ($method) {
                    'load' => $customer->load('orders'),
                    'orders' => $customer->orders(),
                    default => $customer->{$method}(),
                };
                self::fail($method . ' 不得跨 Gateway 使用实体');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('EntityGateway', $e->getMessage());
            }
        }
        self::assertSame('alice', $customer->name);
        self::assertSame(['id' => 1, 'name' => 'alice'], $customer->toArray());
        self::assertFalse($customer->relationLoaded('orders'));

        Session::discard();
        $other = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        $other->connection()->execute('CREATE TABLE relation_customer (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)', []);
        try {
            $other->insert($customer);
            self::fail('删除后的实体也不能跨 Gateway 重插');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('alice', $customer->toArray()['name']);
    }

    public function testDestroyedGatewayCannotBeReboundImplicitly(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        $gateway->connection()->execute(
            'CREATE TABLE relation_customer (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
            [],
        );
        $gateway->connection()->execute(
            'CREATE TABLE relation_profile (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, bio TEXT NOT NULL)',
            [],
        );
        Session::use($gateway);
        RelationCustomer::insertAll([['name' => 'ephemeral']]);
        RelationProfile::insertAll([['customer_id' => 1, 'bio' => 'retained']]);
        $customer = RelationCustomer::find(['id' => 1])->load('profile');
        $reference = \WeakReference::create($gateway);
        $gateway->clearCache(RelationCustomer::class);
        Session::discard();
        unset($gateway, $pdo);
        gc_collect_cycles();
        self::assertNull($reference->get(), '测试前提：原 Gateway 必须已经销毁');
        self::assertSame('retained', $customer->loadedRelation('profile')?->bio);

        Session::use(new EntityGateway(new PdoConnection(new PDO('sqlite::memory:'))));
        foreach (['load', 'orders', 'save', 'refresh', 'delete', 'toArray'] as $method) {
            try {
                match ($method) {
                    'load' => $customer->load('orders'),
                    'orders' => $customer->orders(),
                    default => $customer->{$method}(),
                };
                self::fail($method . ' 不得把过期实体隐式绑定到新 Gateway');
            } catch (\LogicException $e) {
                self::assertStringContainsString('原 Gateway 已销毁', $e->getMessage());
            }
        }
        self::assertSame('ephemeral', $customer->name);
        self::assertTrue($customer->relationLoaded('profile'));
        self::assertFalse($customer->relationLoaded('orders'));
    }

    public function testSameGatewayCanBeReusedAfterDiscardWithoutKeepingItAlive(): void
    {
        $customer = RelationCustomer::find(['id' => 1])->load('profile');
        Session::discard();
        Session::use($this->gateway);
        self::assertSame('alice', $customer->refresh()->name);
        $customer->name = 'reused';
        $customer->save();
        self::assertSame('reused', $customer->name);

        Session::discard();
        unset($customer);
        $gateway = new EntityGateway(new PdoConnection($this->pdo));
        $entity = $gateway->find(RelationCustomer::class, ['id' => 1]);
        $reference = \WeakReference::create($gateway);
        unset($gateway);
        gc_collect_cycles();
        self::assertNull($reference->get(), '实体不得通过弱归属延长 Gateway/连接生命周期');
        self::assertSame('reused', $entity?->name);
    }

    public function testEmptyGatewayCacheDoesNotKeepEntityGraphAlive(): void
    {
        $entity = RelationCustomer::query()->where('id', 999)->first();
        $reference = \WeakReference::create($this->gateway);
        Session::discard();
        unset($entity, $this->gateway);
        gc_collect_cycles();
        self::assertNull($reference->get(), '无缓存实体时 Session 释放后不得保留 Gateway');
    }

    public function testBatchInsertRejectsForeignEntityBeforeAnySql(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        $customer->delete();
        $other = new EntityGateway(new PdoConnection(new PDO('sqlite::memory:')));
        $other->connection()->execute('CREATE TABLE relation_customer (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)', []);
        Session::use($other);
        $fresh = new RelationCustomer();
        $fresh->name = 'fresh';
        try {
            $other->batchInsert(RelationCustomer::class, [$fresh, $customer]);
            self::fail('批量插入必须在 SQL 前拒绝其他 Gateway 的实体');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, $other->connection()->queryOne('SELECT COUNT(*) AS aggregate FROM relation_customer', [])['aggregate']);
        self::assertTrue($fresh->isNew());
    }

    public function testBatchInsertRejectsInvalidEntitiesBeforeHooksOrSql(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        $fresh = new RelationCustomer();
        $fresh->name = 'fresh';
        try {
            $this->gateway->batchInsert(RelationCustomer::class, [$fresh, $customer]);
            self::fail('已持久化实体不得再次批量插入');
        } catch (\Pixiaoka\Orm\Exception\IdentityException) {
        }
        try {
            $this->gateway->batchInsert(RelationCustomer::class, [$fresh, new RelationOrder()]);
            self::fail('其他模型的实体不得混入批量插入');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(RelationCustomer::class, $e->getMessage());
        }
        self::assertTrue($fresh->isNew());
        self::assertSame('fresh', $fresh->name);
        self::assertSame(3, RelationCustomer::query()->count());
    }

    public function testDeletedCachedEntityDoesNotLeaveStaleCacheIdentity(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        $customer->delete();
        $cache = (new \ReflectionProperty(\Pixiaoka\Orm\Gateway\EntityGateway::class, 'cache'))->getValue($this->gateway);
        $snapshot = $cache->transactionSnapshot();
        self::assertSame([], $snapshot['items']);
        self::assertSame([], $snapshot['identities']);
        unset($customer->id);
        $customer->save();
        self::assertSame(4, $customer->id);
        self::assertSame($customer, RelationCustomer::find(['id' => 4]));
    }

    public function testRefreshDeleteAndSaveDoNotSerializeRelations(): void
    {
        $customer = RelationCustomer::query()->with('orders')->where('id', 1)->first();
        self::assertSame(['id' => 1, 'name' => 'alice'], $customer->toArray());
        self::assertSame('alice', $customer->takeOriginal()['name']);
        $customer->name = 'alice-updated';
        $customer->save();
        self::assertCount(3, $customer->loadedRelation('orders'));
        $customer->refresh();
        self::assertFalse($customer->relationLoaded('orders'));
        try {
            $customer->loadedRelation('orders');
            self::fail('refresh 必须清空关系');
        } catch (\LogicException) {
        }
        $customer->load('orders')->delete();
        self::assertFalse($customer->relationLoaded('orders'));
    }

    public function testTransactionRollbackRestoresPreviousRelationState(): void
    {
        $customer = RelationCustomer::query()->with('orders')->where('id', 1)->first();
        $before = $customer->loadedRelation('orders');
        try {
            RelationCustomer::transaction(function () use ($customer): void {
                $customer->load(['orders' => fn (Builder $q) => $q->where('status', 'paid')]);
                self::assertCount(2, $customer->loadedRelation('orders'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame($before, $customer->loadedRelation('orders'));
        self::assertCount(3, $customer->loadedRelation('orders'));
    }

    public function testFailedNestedAndSiblingLoadsDoNotInstallPartialStateAndRetry(): void
    {
        $customer = RelationCustomer::query()->with('profile')->where('id', 1)->first();
        $profile = $customer->loadedRelation('profile');
        foreach ([
            ['orders.missing'],
            [['orders', 'missing']],
            ['orders.customer' => fn (Builder $q) => $q->limit(1)],
        ] as $relations) {
            try {
                $customer->load($relations);
                self::fail('失败关系加载必须抛异常');
            } catch (MetadataException|RelationException) {
            }
            self::assertFalse($customer->relationLoaded('orders'));
            self::assertSame($profile, $customer->loadedRelation('profile'));
        }
        $customer->load(['orders.customer', 'profile']);
        self::assertCount(3, $customer->loadedRelation('orders'));
        self::assertSame($profile, $customer->loadedRelation('orders')[0]->loadedRelation('customer')->load('profile')->loadedRelation('profile'));
        self::assertSame($profile, $customer->loadedRelation('profile'));
    }

    public function testCompositeKeysAreChunkedWithoutParameterLimitOrCrossJoin(): void
    {
        $tenants = [];
        $entries = [];
        for ($i = 20; $i <= 1020; $i++) {
            $tenants[] = ['tenant_id' => 3, 'id' => $i, 'name' => 'bulk-' . $i];
            $entries[] = [
                'tenant_id' => 2,
                'id' => $i,
                'owner_tenant' => 3,
                'owner_id' => $i,
                'label' => 'bulk-entry-' . $i,
            ];
        }
        RelationTenant::insertAll($tenants);
        RelationEntry::insertAll($entries);
        $this->selects = 0;
        $entries = RelationEntry::query()->with('owner')
            ->where('tenant_id', 2)
            ->whereOp('id', '>=', 20)
            ->orderBy('id')
            ->get();
        self::assertSame(4, $this->selects, '1001 个不同复合键产生 500 + 500 + 1 三批关系查询');
        self::assertCount(1001, $entries);
        self::assertSame('bulk-20', $entries[0]->loadedRelation('owner')?->name);
        self::assertSame('bulk-1020', $entries[1000]->loadedRelation('owner')?->name);
        foreach ($entries as $index => $entry) {
            self::assertSame(3, $entry->loadedRelation('owner')?->tenant_id);
            self::assertSame(20 + $index, $entry->loadedRelation('owner')?->id);
        }
    }

    public function testInvalidRelationConfigurationsAndTerminalsAreRejectedBeforeWrites(): void
    {
        foreach ([
            [
                ['orders' => fn (Builder $q) => $q],
                ['orders' => fn (Builder $q) => $q],
            ],
            ['orders' => fn (Builder $q) => $q->select('id')],
            ['orders' => fn (Builder $q) => $q->with('customer')],
        ] as $relations) {
            try {
                RelationCustomer::query()->with($relations)->get();
                self::fail('非法预加载配置必须拒绝');
            } catch (RelationException) {
            }
        }
        foreach (['rows', 'row', 'value', 'count'] as $method) {
            try {
                RelationCustomer::query()->with('orders')->{$method}();
                self::fail($method . ' 不得忽略 with');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(4, RelationOrder::query()->whereNotNull('customer_id')->count());
        try {
            RelationCustomer::query()->with('orders')->where('id', 1)->update(['name' => 'bad']);
            self::fail('UPDATE 不得忽略 with');
        } catch (\InvalidArgumentException) {
        }
        try {
            RelationCustomer::query()->with('orders')->where('id', 1)->delete();
            self::fail('DELETE 不得忽略 with');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('alice', RelationCustomer::query()->where('id', 1)->value('name'));
    }

    public function testFailedGraphRestoresMergedTargetFieldsRelationsAndCache(): void
    {
        $cached = RelationCustomer::find(['id' => 1])->load('profile');
        $profile = $cached->loadedRelation('profile');
        $this->pdo->exec("UPDATE relation_customer SET name = 'database-name' WHERE id = 1");
        $this->pdo->exec("UPDATE relation_order SET customer_id = 1 WHERE customer_id IS NULL");
        $beforeSelects = $this->selects;
        $orders = RelationOrder::query()->get();
        self::assertSame($beforeSelects + 1, $this->selects);
        self::assertNotSame($cached, $orders[0], '普通查询继续返回新副本');
        try {
            $orders[0]->load(['customer', 'missing']);
            self::fail('兄弟关系失败必须抛异常');
        } catch (MetadataException) {
        }
        self::assertSame('alice', $cached->name);
        self::assertSame($profile, $cached->loadedRelation('profile'));
        self::assertFalse($orders[0]->relationLoaded('customer'));
        self::assertSame($cached, RelationCustomer::find(['id' => 1]));
        self::assertSame('alice', RelationCustomer::find(['id' => 1])->name);

        $orders[0]->load('customer');
        self::assertSame($cached, $orders[0]->loadedRelation('customer'));
        self::assertSame('database-name', $cached->name);
        self::assertSame($profile, $cached->loadedRelation('profile'));
    }

    public function testRelationRefreshPreservesDirtyFieldsAndFlushesEvictedCopies(): void
    {
        $cached = RelationCustomer::find(['id' => 1]);
        $cached->name = 'local-draft';
        $this->pdo->exec("UPDATE relation_customer SET name = 'alice' WHERE id = 1");
        $order = RelationOrder::query()->with('customer')->where('id', 1)->first();
        self::assertSame($cached, $order->loadedRelation('customer'));
        self::assertSame('local-draft', $cached->name);
        self::assertSame('alice', $cached->takeOriginal()['name']);

        RelationCustomer::flushCache();
        $evicted = RelationOrder::query()->with('customer')->where('id', 1)->first();
        self::assertNotSame($cached, $evicted->loadedRelation('customer'));
        self::assertSame('local-draft', $cached->name, 'flushCache 不得修改仍被调用方持有的旧对象');
        self::assertSame('alice', $evicted->loadedRelation('customer')->name);
    }

    public function testConstraintCannotReenterLoaderAndFailureCanRetry(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        try {
            $customer->load(['orders' => function (Builder $q) use ($customer): Builder {
                $customer->load('profile');

                return $q;
            }]);
            self::fail('约束闭包不得启动另一棵预加载图');
        } catch (RelationException) {
        }
        self::assertFalse($customer->relationLoaded('orders'));
        self::assertFalse($customer->relationLoaded('profile'));
        $customer->load('orders');
        self::assertCount(3, $customer->loadedRelation('orders'));
    }

    public function testRollbackRestoresCanonicalTargetAndRelationsLoadedInTransaction(): void
    {
        $cached = RelationCustomer::find(['id' => 1])->load('profile');
        $profile = $cached->loadedRelation('profile');
        $this->pdo->exec("UPDATE relation_customer SET name = 'database-name' WHERE id = 1");
        try {
            RelationCustomer::transaction(static function () use ($cached): void {
                $order = RelationOrder::query()->with('customer')->where('id', 1)->first();
                self::assertSame($cached, $order->loadedRelation('customer'));
                self::assertSame('database-name', $cached->name);
                throw new \RuntimeException('rollback relation graph');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback relation graph', $e->getMessage());
        }
        self::assertSame('alice', $cached->name);
        self::assertSame($profile, $cached->loadedRelation('profile'));
        self::assertSame($cached, RelationCustomer::find(['id' => 1]));
        self::assertSame('alice', RelationCustomer::find(['id' => 1])->name);
    }

    public function testRejectsUnknownRelationWithoutSql(): void
    {
        $this->expectException(MetadataException::class);
        RelationCustomer::query()->with('missing')->get();
    }

    public function testPrimaryKeyRelationOrderIsNotDuplicated(): void
    {
        $sql = [];
        $unsubscribe = $this->gateway->subscribe(static function (Event $event) use (&$sql): void {
            if ($event instanceof SqlEvent) {
                $sql[] = $event->sql;
            }
        });
        try {
            $customer = RelationCustomer::find(['id' => 1]);
            $source = RelationOrderKeyCustomer::find(['id' => 1]);
            self::assertNotNull($source);
            $source->orders()->get();
            $source->orders()->query(
                RelationOrder::query()->orderBy('amount', 'DESC')->orderBy('id', 'DESC'),
            )->get();
            $explicit = $sql;
            $sql = [];
            $loaded = RelationOrderKeyCustomer::query()->with('orders')->where('id', 1)->first();
            self::assertSame([1], array_column($loaded?->loadedRelation('orders'), 'id'));
            $eager = $sql;
            $sql = [];
            $loaded = RelationOrderKeyCustomer::query()->with([
                'orders' => static fn (Builder $query): Builder => $query->orderBy('amount', 'DESC'),
            ])->where('id', 1)->first();
            self::assertSame([1], array_column($loaded?->loadedRelation('orders'), 'id'));
            $constrained = $sql;
            $sql = [];

            $order = new RelationOrderKeyOrder();
            $order->id = 1;
            self::assertSame($customer?->id, $order->customer()->get()?->id);
            $orderSql = $sql;
            $sql = [];
            self::assertSame($customer?->id, $order->customer()->query(
                RelationOrderKeyCustomer::query()->orderBy('id', 'DESC'),
            )->get()?->id);
            $descending = $sql;
        } finally {
            $unsubscribe();
        }

        self::assertCount(4, $explicit);
        self::assertStringEndsWith(' ORDER BY "id" ASC', $explicit[2]);
        self::assertSame(1, substr_count($explicit[2], 'ORDER BY'));
        self::assertStringEndsWith(' ORDER BY "amount" DESC, "id" DESC', $explicit[3]);
        self::assertSame(1, substr_count($explicit[3], 'ORDER BY'));
        self::assertCount(2, $eager);
        self::assertStringEndsWith(' ORDER BY "id" ASC', $eager[1]);
        self::assertSame(1, substr_count($eager[1], 'ORDER BY'));
        self::assertCount(2, $constrained);
        self::assertStringEndsWith(' ORDER BY "amount" DESC, "id" ASC', $constrained[1]);
        self::assertSame(1, substr_count($constrained[1], 'ORDER BY'));
        self::assertCount(1, $orderSql);
        self::assertStringEndsWith(' ORDER BY "id" ASC', $orderSql[0]);
        self::assertSame(1, substr_count($orderSql[0], 'ORDER BY'));
        self::assertCount(1, $descending);
        self::assertStringEndsWith(' ORDER BY "id" DESC', $descending[0]);
        self::assertSame(1, substr_count($descending[0], 'ORDER BY'));
    }

    public function testSingleRelationsAndEagerLoadingUseStablePrimaryKeyTieBreakers(): void
    {
        $customer = RelationCustomer::find(['id' => 1]);
        foreach (['OFF', 'ON'] as $reverse) {
            $this->pdo->exec('PRAGMA reverse_unordered_selects = ' . $reverse);
            self::assertSame(1, $customer->profile()->get()?->id);
            self::assertSame(1, $customer->profile()->first()?->id);
            self::assertSame([1, 2, 3], array_column($customer->orders()->get(), 'id'));

            $loaded = RelationCustomer::query()->with('profile', 'orders')->where('id', 1)->first();
            self::assertSame(1, $loaded?->loadedRelation('profile')?->id);
            self::assertSame([1, 2, 3], array_column($loaded?->loadedRelation('orders'), 'id'));
        }
        $this->pdo->exec('PRAGMA reverse_unordered_selects = OFF');
        self::assertSame(2, $customer->profile()->query(
            RelationProfile::query()->orderBy('bio', 'DESC'),
        )->get()?->id);
        self::assertSame([1, 3], array_column($customer->orders()->query(
            RelationOrder::query()->where('status', 'paid')->orderBy('amount', 'DESC'),
        )->get(), 'id'));
    }

    public function testCanonicalRefreshInvalidatesOnlyRelationsWithChangedPersistentKeys(): void
    {
        $order = RelationOrder::query()->where('id', 1)->first()->load('customer');
        $alice = $order->loadedRelation('customer');
        $profile = $alice->load('profile')->loadedRelation('profile');
        $order->status = 'local-draft';
        $this->pdo->exec('UPDATE relation_order SET customer_id = 2 WHERE id = 1');

        $bob = RelationCustomer::query()->with('orders')->where('id', 2)->first();
        self::assertSame($order, $bob?->loadedRelation('orders')[0]);
        self::assertSame(2, $order->customer_id);
        self::assertSame('local-draft', $order->status, '刷新不得覆盖未保存字段');
        self::assertFalse($order->relationLoaded('customer'));
        self::assertTrue($alice->relationLoaded('profile'));
        self::assertSame($profile, $alice->loadedRelation('profile'));

        $order->load('customer');
        self::assertSame($bob, $order->loadedRelation('customer'));
        self::assertSame(2, $order->takeOriginal()['customer_id']);
        self::assertSame('paid', $order->takeOriginal()['status']);
    }

    public function testNestedGraphUsesRefreshedKeysInsteadOfInstallingStaleChildren(): void
    {
        $order = RelationOrder::query()->where('id', 1)->first()->load('customer');
        self::assertSame(1, $order->loadedRelation('customer')?->id);
        $this->pdo->exec('UPDATE relation_order SET customer_id = 2 WHERE id = 1');

        $bob = RelationCustomer::query()->with('orders.customer')->where('id', 2)->first();
        self::assertSame($order, $bob?->loadedRelation('orders')[0]);
        self::assertSame($bob, $order->loadedRelation('customer'));
        self::assertSame(2, $order->loadedRelation('customer')?->id);
        self::assertSame(2, $order->customer_id);
    }

    public function testChangedKeysAreRestoredAfterFailedGraphAndTransactionRollback(): void
    {
        $order = RelationOrder::query()->where('id', 1)->first()->load('customer');
        $alice = $order->loadedRelation('customer');
        $this->pdo->exec('UPDATE relation_order SET customer_id = 2 WHERE id = 1');

        try {
            $order->load(['customer', 'missing']);
            self::fail('失败的兄弟关系必须抛异常');
        } catch (MetadataException) {
        }
        self::assertSame(1, $order->customer_id);
        self::assertSame($alice, $order->loadedRelation('customer'));
        self::assertSame($order, RelationOrder::find(['id' => 1]));

        try {
            RelationOrder::transaction(static function () use ($order): void {
                $bob = RelationCustomer::query()->with('orders.customer')->where('id', 2)->first();
                self::assertSame($bob, $order->loadedRelation('customer'));
                self::assertSame(2, $order->customer_id);
                throw new \RuntimeException('rollback changed relation key');
            });
            self::fail('事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback changed relation key', $e->getMessage());
        }
        self::assertSame(1, $order->customer_id);
        self::assertSame($alice, $order->loadedRelation('customer'));
        self::assertSame($order, RelationOrder::find(['id' => 1]));
    }
}
