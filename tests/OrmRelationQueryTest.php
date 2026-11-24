<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Session;

final class RelQueryCustomer extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return 'relq_customer'; }

    #[HasManyAttribute(target: RelQueryOrder::class, foreignKey: ['ownerRef'])]
    public function orders(): HasMany { return $this->makeRelation(); }
}

final class RelQueryOrder extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column('customer_id')]
    public ?int $ownerRef = null;
    #[Column]
    public string $status;
    #[Column]
    public int $amount;

    public static function table(): string { return 'relq_order'; }

    #[BelongsToAttribute(target: RelQueryCustomer::class, localKey: ['ownerRef'])]
    public function owner(): BelongsTo { return $this->makeRelation(); }
}

final class RelQueryNode extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    #[Column]
    public ?int $parent_id = null;
    #[Column]
    public string $name;

    public static function table(): string { return 'relq_node'; }

    #[HasManyAttribute(target: self::class, foreignKey: ['parent_id'])]
    public function children(): HasMany { return $this->makeRelation(); }

    #[BelongsToAttribute(target: self::class, localKey: ['parent_id'])]
    public function parent(): BelongsTo { return $this->makeRelation(); }
}

final class RelQueryTenant extends Entity
{
    #[Column('tenant_id'), PrimaryKey(order: 1)]
    public int $tenantKey;
    #[Column('id'), PrimaryKey(order: 2)]
    public int $recordKey;
    #[Column]
    public string $name;

    public static function table(): string { return 'relq_tenant'; }

    #[HasManyAttribute(
        target: RelQueryItem::class,
        localKey: ['tenantKey', 'recordKey'],
        foreignKey: ['ownerTenant', 'ownerId'],
    )]
    public function items(): HasMany { return $this->makeRelation(); }
}

final class RelQueryItem extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    #[Column('owner_tenant')]
    public int $ownerTenant;
    #[Column('owner_id')]
    public int $ownerId;
    #[Column]
    public string $label;

    public static function table(): string { return 'relq_item'; }

    #[BelongsToAttribute(
        target: RelQueryTenant::class,
        localKey: ['ownerTenant', 'ownerId'],
        foreignKey: ['tenantKey', 'recordKey'],
    )]
    public function owner(): BelongsTo { return $this->makeRelation(); }
}

final class RelQueryReservedParent extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return '_orm_relation_1'; }

    #[HasManyAttribute(target: RelQueryOrder::class, localKey: ['id'], foreignKey: ['ownerRef'])]
    public function orders(): HasMany { return $this->makeRelation(); }
}

final class OrmRelationQueryTest extends TestCase
{
    private PDO $pdo;
    private int $selects = 0;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE relq_customer (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE relq_order (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NULL, status TEXT NOT NULL, amount INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE relq_node (id INTEGER PRIMARY KEY, parent_id INTEGER NULL, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE relq_tenant (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, name TEXT NOT NULL, PRIMARY KEY (tenant_id, id))');
        $this->pdo->exec('CREATE TABLE relq_item (id INTEGER PRIMARY KEY, owner_tenant INTEGER NOT NULL, owner_id INTEGER NOT NULL, label TEXT NOT NULL)');
        $gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($gateway);
        $gateway->subscribe(function (Event $event): void {
            if ($event instanceof SqlEvent && $event->operation === 'SELECT') {
                $this->selects++;
            }
        });
        RelQueryCustomer::insertAll([['name' => 'alice'], ['name' => 'bob'], ['name' => 'nobody']]);
        RelQueryOrder::insertAll([
            ['ownerRef' => 1, 'status' => 'paid', 'amount' => 30],
            ['ownerRef' => 1, 'status' => 'new', 'amount' => 10],
            ['ownerRef' => 1, 'status' => 'paid', 'amount' => 20],
            ['ownerRef' => 2, 'status' => 'new', 'amount' => 40],
            ['ownerRef' => null, 'status' => 'orphan', 'amount' => 50],
            ['ownerRef' => 99, 'status' => 'orphan', 'amount' => 60],
        ]);
        RelQueryNode::insertAll([
            ['id' => 1, 'parent_id' => null, 'name' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'child'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'other'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'leaf'],
            ['id' => 5, 'parent_id' => null, 'name' => 'empty'],
        ]);
        RelQueryTenant::insertAll([
            ['tenantKey' => 1, 'recordKey' => 1, 'name' => 'A'],
            ['tenantKey' => 1, 'recordKey' => 2, 'name' => 'B'],
            ['tenantKey' => 2, 'recordKey' => 1, 'name' => 'C'],
        ]);
        RelQueryItem::insertAll([
            ['id' => 1, 'ownerTenant' => 1, 'ownerId' => 1, 'label' => 'a1'],
            ['id' => 2, 'ownerTenant' => 1, 'ownerId' => 1, 'label' => 'a2'],
            ['id' => 3, 'ownerTenant' => 2, 'ownerId' => 1, 'label' => 'c1'],
            ['id' => 4, 'ownerTenant' => 9, 'ownerId' => 1, 'label' => 'orphan'],
        ]);
        $this->selects = 0;
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testHasDoesntHaveAndRelationUseWholeConstrainedSet(): void
    {
        self::assertSame(['alice'], $this->customerNames(
            RelQueryCustomer::query()->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid')),
        ));
        self::assertSame(['nobody'], $this->customerNames(RelQueryCustomer::query()->whereDoesntHave('orders')));
        self::assertSame(['bob', 'nobody'], $this->customerNames(
            RelQueryCustomer::query()->whereDoesntHave('orders', fn (Builder $q) => $q->where('status', 'paid')),
        ));
        self::assertSame(['alice', 'bob'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'status', 'new'),
        ));
        self::assertSame(['alice', 'bob'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'amount', '>=', '20'),
        ));
        self::assertSame(['alice', 'bob'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'status', 'IN', ['new', 'paid']),
        ));
        self::assertSame(['alice'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'amount', 'BETWEEN', [20, 30]),
        ));
        self::assertSame(['alice'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'amount', '=', 20),
        ));
        self::assertSame(['alice', 'bob'], $this->customerNames(
            RelQueryCustomer::query()->whereRelation('orders', 'status', '!=' , 'orphan'),
        ));
        self::assertSame([1, 2, 3, 4], array_column(
            RelQueryOrder::query()->whereRelation('owner', 'name', '!=', null)->orderBy('id')->get(),
            'id',
        ));
        self::assertSame(0, RelQueryOrder::query()->whereRelation('owner', 'name', null)->count());
        $operatorLookingValues = RelQueryCustomer::query()
            ->whereRelation('orders', 'status', '=')
            ->build();
        self::assertSame(['q0' => '='], $operatorLookingValues['params']);
        self::assertStringContainsString('"status" = :q0', $operatorLookingValues['sql']);
        self::assertSame([1, 2, 3, 4], array_column(RelQueryOrder::query()->whereHas('owner')->orderBy('id')->get(), 'id'));
        self::assertSame([5, 6], array_column(RelQueryOrder::query()->whereDoesntHave('owner')->orderBy('id')->get(), 'id'));
        self::assertSame(2, RelQueryCustomer::query()->whereHas('orders')->count());
        self::assertSame(2, RelQueryCustomer::query()->whereDoesntHave('orders', fn (Builder $q) => $q->where('status', 'paid'))->count());
    }

    public function testStableAliasesPropertyMappingAndParameters(): void
    {
        $query = RelQueryCustomer::query()
            ->where('name', 'alice')
            ->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid'))
            ->orGroup(fn (Builder $q) => $q->whereHas('orders', fn (Builder $r) => $r->where('amount', 20)));
        $built = $query->build();
        self::assertSame(['q0' => 'alice', 'q1' => 'paid', 'q2' => 20], $built['params']);
        self::assertStringContainsString('FROM "relq_order" AS "_orm_relation_1"', $built['sql']);
        self::assertStringContainsString('"_orm_relation_1"."status" = :q1', $built['sql']);
        self::assertStringContainsString('"_orm_relation_1"."customer_id" = "relq_customer"."id"', $built['sql']);
        self::assertStringContainsString('"_orm_relation_2"."amount" = :q2', $built['sql']);
        self::assertSame($built, $query->build());
        self::assertSame(['alice'], $this->customerNames($query));
    }

    public function testNestedPathsConstrainLeafAndCountMatchedChildren(): void
    {
        $query = RelQueryCustomer::query()->whereHas(
            'orders.owner',
            fn (Builder $q) => $q->where('name', 'alice'),
        );
        $sql = $query->build()['sql'];
        self::assertStringContainsString('FROM "relq_customer" AS "_orm_relation_2"', $sql);
        self::assertStringContainsString('"_orm_relation_2"."id" = "_orm_relation_1"."customer_id"', $sql);
        self::assertSame(['alice'], $this->customerNames($query));
        $rows = RelQueryCustomer::query()->withCount('orders.owner')->orderBy('id')->rows();
        self::assertSame([3, 1, 0], array_map(intval(...), array_column($rows, 'orders_owner_count')));
    }

    public function testCompositeKeysAndReverseRelationDoNotCrossTenants(): void
    {
        self::assertSame(['A', 'C'], array_column(
            RelQueryTenant::query()->whereHas('items')->orderBy('tenant_id')->orderBy('id')->get(),
            'name',
        ));
        self::assertSame(['B'], array_column(RelQueryTenant::query()->whereDoesntHave('items')->get(), 'name'));
        $rows = RelQueryTenant::query()->withCount('items')->orderBy('tenant_id')->orderBy('id')->rows();
        self::assertSame([2, 0, 1], array_map(intval(...), array_column($rows, 'items_count')));
        self::assertSame([1, 2, 3], array_column(RelQueryItem::query()->whereHas('owner')->orderBy('id')->get(), 'id'));
        $sql = RelQueryTenant::query()->whereRelation('items', 'label', 'a1')->build()['sql'];
        self::assertStringContainsString('"_orm_relation_1"."owner_tenant" = "relq_tenant"."tenant_id"', $sql);
        self::assertStringContainsString('"_orm_relation_1"."owner_id" = "relq_tenant"."id"', $sql);
    }

    public function testSelfRelationAndOrGroups(): void
    {
        self::assertSame(['root', 'child'], array_column(
            RelQueryNode::query()->whereHas('children')->orderBy('id')->get(),
            'name',
        ));
        self::assertSame(['root'], array_column(
            RelQueryNode::query()->whereHas('children', fn (Builder $q) => $q->where('name', 'child'))->get(),
            'name',
        ));
        self::assertSame([2, 3, 4], array_column(RelQueryNode::query()->whereHas('parent')->orderBy('id')->get(), 'id'));
        $aliased = RelQueryNode::query()->alias('node')->whereHas('children', fn (Builder $q) => $q->where('name', 'child'))->build();
        self::assertStringContainsString('"node"."id"', $aliased['sql']);
        self::assertSame(['root'], array_column(RelQueryNode::query()->alias('node')
            ->whereHas('children', fn (Builder $q) => $q->where('name', 'child'))->rows(), 'name'));
        $rows = RelQueryNode::query()->withCount('children')->orderBy('id')->rows();
        self::assertSame([2, 1, 0, 0, 0], array_map(intval(...), array_column($rows, 'children_count')));
        self::assertSame(['alice', 'nobody'], $this->customerNames(
            RelQueryCustomer::query()->orGroup(
                fn (Builder $q) => $q->where('name', 'nobody')->whereHas('orders', fn (Builder $r) => $r->where('status', 'paid')),
            ),
        ));
    }

    public function testCountsUseRawRowsAndCanBeFiltered(): void
    {
        $query = RelQueryCustomer::query()
            ->select('name')
            ->withCount('orders')
            ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
            ->orderBy('id');
        self::assertSame([
            ['name' => 'alice', 'orders_count' => 3, 'paid_orders' => 2],
            ['name' => 'bob', 'orders_count' => 1, 'paid_orders' => 0],
            ['name' => 'nobody', 'orders_count' => 0, 'paid_orders' => 0],
        ], $query->rows());
        self::assertSame(3, RelQueryCustomer::query()->withCount('orders')->value('orders_count'));
        $row = RelQueryCustomer::query()->where('name', 'bob')->withCount('orders')->row();
        self::assertSame('bob', $row['name']);
        self::assertSame(1, $row['orders_count']);
        self::assertSame(0, RelQueryCustomer::query()->orderBy('id')->withCount('orders')->limit(1)->offset(2)->value('orders_count'));
        self::assertSame($query->build(), $query->build());
        self::assertSame(['alice', 'bob'], $this->customerNames(
            RelQueryCustomer::query()->whereHas('orders', fn (Builder $q) => $q->whereIn('status', ['paid', 'new'])),
        ));
        self::assertSame(2, RelQueryCustomer::query()
            ->orGroup(fn (Builder $q) => $q->where('name', 'alice')->where('name', 'bob'))
            ->count());
        $ordinaryOr = RelQueryCustomer::query()
            ->orGroup(fn (Builder $q) => $q->where('name', 'alice')->where('name', 'bob'))
            ->withCount('orders')
            ->orderBy('id')
            ->rows();
        self::assertSame([3, 1], array_map(intval(...), array_column($ordinaryOr, 'orders_count')));
        $reverseOrdinaryOr = RelQueryCustomer::query()
            ->withCount('orders')
            ->orGroup(fn (Builder $q) => $q->where('name', 'alice')->where('name', 'bob'))
            ->orderBy('id')
            ->rows();
        self::assertSame([3, 1], array_map(intval(...), array_column($reverseOrdinaryOr, 'orders_count')));
        self::assertSame([], array_map(intval(...), array_column(
            RelQueryCustomer::query()->withCount('orders')
                ->orGroup(fn (Builder $q) => $q->where('name', 'alice')->where('name', 'bob'))
                ->orGroup(fn (Builder $q) => $q->where('name', 'nobody')->where('name', 'missing'))
                ->orderBy('id')->rows(),
            'orders_count',
        )));
        self::assertSame([
            ['id' => 1, 'orders_count' => 3, 'orders_owner_count' => 3, 'paid_orders' => 2],
            ['id' => 2, 'orders_count' => 1, 'orders_owner_count' => 1, 'paid_orders' => 0],
            ['id' => 3, 'orders_count' => 0, 'orders_owner_count' => 0, 'paid_orders' => 0],
        ], RelQueryCustomer::query()
            ->withCount(['orders', 'orders.owner'])
            ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
            ->select('id')->orderBy('id')->rows());
    }

    public function testRelationFiltersCanUpdateDeleteAndUseFirstSafely(): void
    {
        self::assertSame('bob', RelQueryCustomer::query()->whereHas('orders')->orderBy('id', 'DESC')->first()->name);
        self::assertSame('alice', RelQueryCustomer::query()->whereHas('orders')->orderBy('id')->first()->name);
        self::assertSame(1, RelQueryCustomer::query()->whereRelation('orders', 'status', 'paid')->update(['name' => 'paid-owner']));
        self::assertSame('paid-owner', $this->pdo->query('SELECT name FROM relq_customer WHERE id = 1')->fetchColumn());
        self::assertSame('bob', $this->pdo->query('SELECT name FROM relq_customer WHERE id = 2')->fetchColumn());
        self::assertSame(1, RelQueryCustomer::query()->whereDoesntHave('orders')->delete());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM relq_customer')->fetchColumn());
    }

    public function testCountsLeaveCanonicalEntitiesAndRetryDoesNotMutateConstraints(): void
    {
        $customer = RelQueryCustomer::find(['id' => 1]);
        $original = $customer->takeOriginal();
        $customer->name = 'local-dirty';
        $rows = RelQueryCustomer::query()->withCount('orders')->rows();
        self::assertSame(3, $rows[0]['orders_count']);
        self::assertSame($customer, RelQueryCustomer::find(['id' => 1]));
        self::assertSame('local-dirty', $customer->name);
        self::assertSame($original, $customer->takeOriginal());
        self::assertFalse($customer->relationLoaded('orders'));
        self::assertSame(2, $this->selects);

        $query = RelQueryCustomer::query()->whereHas('orders', static function (Builder $q): void {
            $q->where('status', 'paid');
            throw new \DomainException('constraint failed');
        });
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $query->build();
                self::fail('约束异常必须原样传播');
            } catch (\DomainException $e) {
                self::assertSame('constraint failed', $e->getMessage());
            }
        }
        $stable = RelQueryCustomer::query()->whereHas(
            'orders',
            static fn (Builder $q): Builder => $q->where('status', 'paid'),
        );
        self::assertSame($stable->build(), $stable->build());
        self::assertSame(2, $this->selects);
    }

    public function testReservedAliasesAndConstraintExceptionsCannotHideInvalidPlans(): void
    {
        foreach (['whereHas', 'withCount'] as $method) {
            foreach ([1, 2, 3] as $depth) {
                $path = $depth === 1 ? 'orders' : 'orders.owner';
                try {
                    RelQueryCustomer::query()
                        ->alias('_orm_relation_' . $depth)
                        ->{$method}($path)
                        ->rows();
                    self::fail("顶层或祖先表别名不得冒充任意深度的内部关联别名: $method depth $depth");
                } catch (RelationException $e) {
                    self::assertStringContainsString('Internal relation alias conflict', $e->getMessage());
                }
            }
        }
        $this->pdo->exec('CREATE TABLE "_orm_relation_1" (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        RelQueryReservedParent::insertAll([['id' => 1, 'name' => 'reserved']]);
        try {
            RelQueryReservedParent::query()->whereHas('orders')->get();
            self::fail('真实表名不得冒充内部关联别名');
        } catch (RelationException $e) {
            self::assertStringContainsString('Internal relation alias conflict', $e->getMessage());
        }
        $this->pdo->exec('DROP TABLE "_orm_relation_1"');
        self::assertSame(0, $this->selects);

        // 嵌套父 Builder 的内部别名不能作为外部限定名重复登记，否则正常自关联会自碰撞。
        self::assertSame(['root'], array_column(
            RelQueryNode::query()->whereHas('children.parent', fn (Builder $q) => $q->where('id', 1))->get(),
            'name',
        ));
        self::assertSame(['child'], array_column(
            RelQueryNode::query()->whereHas('children.parent.children', fn (Builder $q) => $q->where('id', 4))->get(),
            'name',
        ));
        self::assertSame(['root', 'child'], array_column(
            RelQueryNode::query()->alias('node')->whereHas('children.parent')->orderBy('id')->rows(),
            'name',
        ));
        $nestedCount = RelQueryNode::query()->withCount('children.parent');
        self::assertSame($nestedCount->build(), $nestedCount->build());
        self::assertSame([2, 1, 0, 0, 0], array_map(
            intval(...),
            array_column($nestedCount->orderBy('id')->rows(), 'children_parent_count'),
        ));
        $constraintCalls = 0;
        $stable = RelQueryNode::query()->whereHas('children.parent', function (Builder $q) use (&$constraintCalls): void {
            $constraintCalls++;
            $q->where('id', 1);
        });
        self::assertSame($stable->build(), $stable->build());
        self::assertSame(2, $constraintCalls);
        self::assertSame(4, $this->selects);
        $this->selects = 0;

        $operations = [
            fn (Builder $q) => $q->whereIn('id', [])->where('id', RelQueryOrder::query()->select('id')),
            fn (Builder $q) => $q->whereIn('id', [])->whereOp('id', 'IN', RelQueryOrder::query()->select('id')),
            fn (Builder $q) => $q->whereIn('id', [])->whereOp('id', '=', RelQueryOrder::query()->select('id')),
            fn (Builder $q) => $q->whereIn('id', [])->orGroup(function (Builder $nested): void {
                $nested->withCount('owner');
                throw new \DomainException('after invalid count');
            }),
            fn (Builder $q) => $q->whereIn('id', [])->orGroup(function (Builder $nested): void {
                $nested->whereIn('id', RelQueryOrder::query()->select('id'));
                throw new \DomainException('after invalid subquery');
            }),
            fn (Builder $q) => $q->orGroup(function (Builder $nested): void {
                $nested->join('relq_customer', 'c', fn ($on) => $on->on('c.id', '=', 'ownerRef'));
                throw new \DomainException('after invalid join');
            }),
            fn (Builder $q) => $q->whereHas('owner', fn (Builder $nested) => $nested->select('id')),
        ];
        foreach ($operations as $operation) {
            try {
                RelQueryCustomer::query()->whereHas('orders', $operation)->build();
                self::fail('恒假条件或异常不得掩盖非法关系约束');
            } catch (RelationException) {
            }
        }
        self::assertSame(0, $this->selects);
    }

    public function testEagerLoadingRemainsSeparateFromRelationFiltering(): void
    {
        $customers = RelQueryCustomer::query()
            ->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid'))
            ->with('orders')
            ->get();
        self::assertCount(1, $customers);
        self::assertCount(3, $customers[0]->loadedRelation('orders'));
        $row = RelQueryCustomer::query()->alias('c')->withCount('orders')->row();
        self::assertSame('alice', $row['name']);
        self::assertSame(3, $row['orders_count']);
    }

    public function testConstraintCannotExecuteReadsWritesOrArbitrarySubqueries(): void
    {
        $operations = [
            fn (Builder $q) => $q->get(),
            fn (Builder $q) => $q->rows(),
            fn (Builder $q) => $q->build(),
            fn (Builder $q) => $q->update(['status' => 'bad']),
            fn (Builder $q) => $q->where('id', 1)->delete(),
            fn (Builder $q) => $q->whereRaw('status = :status', ['status' => 'paid']),
            fn (Builder $q) => $q->where('relq_order.status', 'paid'),
            fn (Builder $q) => $q->whereExists(RelQueryOrder::query()->select('id')),
            fn (Builder $q) => $q->orderByRaw('1'),
            fn (Builder $q) => $q->withCount('owner'),
            fn (Builder $q) => $q->orGroup(fn (Builder $nested) => $nested->update(['status' => 'bad'])),
            fn (Builder $q) => $q->orGroup(fn (Builder $nested) => $nested->where('id', 1)->delete()),
            fn (Builder $q) => $q->orGroup(fn (Builder $nested) => $nested->get()),
            fn (Builder $q) => $q->orGroup(fn (Builder $nested) => $nested->build()),
        ];
        foreach ($operations as $operation) {
            try {
                RelQueryCustomer::query()->whereHas('orders', $operation)->get();
                self::fail('非法关系约束必须拒绝');
            } catch (RelationException) {
            }
        }
        self::assertSame(0, $this->selects);
        self::assertSame(0, $this->pdo->query("SELECT COUNT(*) FROM relq_order WHERE status = 'bad'")->fetchColumn());
    }

    public function testCountOutputCannotHydrateWriteAggregateOrCollide(): void
    {
        $query = RelQueryCustomer::query()->withCount('orders');
        foreach (['get', 'first', 'count'] as $method) {
            try {
                $query->{$method}();
                self::fail($method . ' 不得消费关联计数投影');
            } catch (\InvalidArgumentException|RelationException) {
            }
        }
        foreach (['update', 'delete'] as $method) {
            try {
                RelQueryCustomer::query()->where('id', 1)->withCount('orders')->{$method}(...($method === 'update' ? [['name' => 'bad']] : []));
                self::fail($method . ' 不得消费关联计数投影');
            } catch (\InvalidArgumentException) {
            }
        }
        foreach ([
            '重复默认计数别名' => fn () => RelQueryCustomer::query()->withCount('orders')->withCount('orders')->build(),
            fn () => RelQueryCustomer::query()->withCount('orders', null, 'name')->build(),
            fn () => RelQueryCustomer::query()->select('id')->withCount('orders', null, 'id')->build(),
            fn () => RelQueryCustomer::query()->selectRaw('1')->withCount('orders')->build(),
            fn () => RelQueryCustomer::query()->withCount('orders')->whereHas('orders'),
            fn () => RelQueryCustomer::query()->orGroup(fn (Builder $q) => $q->whereHas('orders'))->withCount('orders'),
            fn () => RelQueryCustomer::query()->withCount('orders')->orGroup(fn (Builder $q) => $q->whereHas('orders')),
            fn () => RelQueryCustomer::query()->withCount('orders')->orGroup(fn (Builder $q) => $q->withCount('owner')),
            fn () => RelQueryCustomer::query()->orGroup(fn (Builder $q) => $q->withCount('orders')),
            fn () => RelQueryCustomer::query()->withCount('')->rows(),
        ] as $case => $invalid) {
            try {
                $invalid();
                self::fail('非法关联计数组合必须拒绝: ' . $case);
            } catch (\InvalidArgumentException|RelationException) {
            }
        }
        self::assertSame(0, $this->selects);
        self::assertSame('alice', $this->pdo->query('SELECT name FROM relq_customer WHERE id = 1')->fetchColumn());
    }

    public function testComplexParentsAndInvalidConstraintShapesAreRejectedBeforeSql(): void
    {
        $parents = [
            fn () => RelQueryCustomer::query()->whereHas('orders')->join('relq_order', 'o', fn ($on) => $on->on('c.id', '=', 'o.customer_id')),
            fn () => RelQueryCustomer::query()->whereHas('orders')->groupBy('id'),
            fn () => RelQueryCustomer::query()->whereHas('orders')->distinct(),
            fn () => RelQueryCustomer::query()->whereHas('orders')->union(RelQueryCustomer::query()),
            fn () => RelQueryCustomer::query()->whereHas('orders')->from(RelQueryCustomer::query()->select('id'), 'c'),
        ];
        foreach ($parents as $parent) {
            try {
                $parent()->build();
                self::fail('复杂父查询应在本轮边界处明确拒绝');
            } catch (RelationException) {
            }
        }
        $shapes = [
            fn (Builder $q) => $q->select('id'),
            fn (Builder $q) => $q->limit(1),
            fn (Builder $q) => $q->alias('o'),
            fn (Builder $q) => $q->join('relq_customer', 'c', fn ($on) => $on->on('c.id', '=', 'customer_id')),
            fn (Builder $q) => $q->forUpdate(),
            fn (Builder $q) => $q,
        ];
        $shapes[array_key_last($shapes)] = fn (Builder $q) => RelQueryCustomer::query();
        foreach ($shapes as $shape) {
            try {
                RelQueryCustomer::query()->whereHas('orders', $shape)->build();
                self::fail('非法关系约束形状必须拒绝');
            } catch (RelationException) {
            }
        }
        self::assertSame(0, $this->selects);
    }

    /** @return list<string> */
    private function customerNames(Builder $query): array
    {
        return array_column($query->orderBy('id')->get(), 'name');
    }
}
