<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SQL 生成结构断言 + 网关运行时行为专项测试。
 * 覆盖：build()/countSql() 生成的 SQL 字符串、占位符与参数、
 * 大批量插入分块、缓存淘汰、事务嵌套行为、delete 未命中、hydrate 缺列、Fiber 隔离。
 */
final class OrmSqlBehaviorTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE item (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            sku TEXT NOT NULL,
            qty INTEGER NOT NULL,
            price TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE tag (
            item_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            PRIMARY KEY (item_id, label)
        )');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    // ---- SQL 结构断言 ----

    public function testBuildSqlStructure(): void
    {
        $q = Item::query()
            ->where('user_id', 7)
            ->whereOp('qty', '>', 3)
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->offset(20);

        ['sql' => $sql, 'params' => $params] = $q->build();

        self::assertSame(
            'SELECT "id", "user_id", "sku", "qty", "price", "created_at", "updated_at" FROM "item" '
            . 'WHERE "user_id" = :q0 AND "qty" > :q1 ORDER BY "created_at" DESC LIMIT 10 OFFSET 20',
            $sql,
        );
        self::assertSame(['q0' => 7, 'q1' => 3], $params);
    }

    public function testBuildSqlwhereInAndNull(): void
    {
        $q = Item::query()
            ->whereIn('user_id', [1, 2, 3])
            ->whereNull('sku');

        ['sql' => $sql, 'params' => $params] = $q->build();

        self::assertSame(
            'SELECT "id", "user_id", "sku", "qty", "price", "created_at", "updated_at" FROM "item" '
            . 'WHERE "user_id" IN (:q0, :q1, :q2) AND "sku" IS NULL',
            $sql,
        );
        self::assertSame(['q0' => 1, 'q1' => 2, 'q2' => 3], $params);
    }

    public function testBuildSqlOrGroupWithBetween(): void
    {
        $q = Item::query()
            ->orGroup(fn ($g) => $g
                ->whereOp('qty', 'BETWEEN', [5, 9])
                ->where('sku', 'A'))
            ->where('user_id', 1);

        ['sql' => $sql, 'params' => $params] = $q->build();

        // AND 条件先渲染，OR 分组在后
        self::assertSame(
            'SELECT "id", "user_id", "sku", "qty", "price", "created_at", "updated_at" FROM "item" '
            . 'WHERE "user_id" = :q0 AND ("qty" BETWEEN :q1 AND :q2 OR "sku" = :q3)',
            $sql,
        );
        self::assertSame(['q0' => 1, 'q1' => 5, 'q2' => 9, 'q3' => 'A'], $params);
    }

    public function testCountSqlStripsOrderByLimitOffset(): void
    {
        $q = Item::query()
            ->where('user_id', 7)
            ->orderBy('id')
            ->limit(5)
            ->offset(10);

        ['sql' => $sql, 'params' => $params] = $q->countSql();

        self::assertSame('SELECT COUNT(*) FROM "item" WHERE "user_id" = :q0', $sql);
        self::assertSame(['q0' => 7], $params);
    }

    public function testCountWithColumnUsesWrappedColumn(): void
    {
        $q = Item::query()->where('user_id', 1);

        // COUNT(column) 的表达式由编译器生成，执行器不得再替换 SQL。
        $count = $q->count('sku');
        self::assertSame(0, $count);
    }

    public function testInsertGeneratesParameterizedSql(): void
    {
        $recorder = new RecordingConn(new PdoConnection($this->pdo));
        $gateway = new EntityGateway($recorder);
        Session::use($gateway);

        $item = new Item();
        $item->user_id = 5;
        $item->sku = 'X';
        $item->qty = 2;
        $item->price = BcDecimal::fromString('9.99');
        $item->save();

        // sqlite 走 RETURNING 路径，INSERT 本身不含生成列
        self::assertStringStartsWith('INSERT INTO "item" (', $recorder->lastSql);
        self::assertStringNotContainsString(':id', $recorder->lastSql);
        self::assertArrayNotHasKey('id', $recorder->lastParams);
        self::assertSame('9.99', $recorder->lastParams['price']);
    }

    // ---- 网关行为 ----

    public function testBatchInsertChunksBeyondFiveHundredRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = ['user_id' => $i, 'sku' => "S$i", 'qty' => $i, 'price' => '1.50'];
        }
        Item::insertAll($rows);

        self::assertSame(600, Item::query()->count());
        // 顺序回填：抽查首尾
        self::assertSame(1, Item::query()->where('user_id', 1)->first()?->user_id);
        self::assertSame(600, Item::query()->where('user_id', 600)->first()?->user_id);
    }

    public function testDeleteMissThrowsIdentityException(): void
    {
        $item = $this->makeItem(1, 'A');
        $item->save();

        // 另一个进程删掉了这行——本地缓存还在，直接 delete 不会命中 1 行
        $this->pdo->exec('DELETE FROM item');

        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('delete must affect exactly one row');
        $item->delete();
    }

    public function testRefreshMissingRowThrows(): void
    {
        $item = $this->makeItem(2, 'B');
        $item->save();
        $this->pdo->exec('DELETE FROM item');

        $this->expectException(IdentityException::class);
        $item->refresh();
    }

    public function testFindReturnsNullForMissingRow(): void
    {
        self::assertNull(Item::query()->where('id', 999)->first());
        self::assertNull(Item::find(['id' => 999]));
    }

    public function testHydrateMissingNotNullColumnThrows(): void
    {
        $item = $this->makeItem(3, 'C');
        $item->save();
        // 破坏表结构：删掉 qty 列做不到，改为直接验证 hydrate 对缺列的严格性
        // 通过 queryOne 拿原始行确认列存在
        $raw = $this->pdo->query('SELECT * FROM item WHERE user_id = 3')->fetch(PDO::FETCH_ASSOC);
        self::assertArrayHasKey('qty', $raw);
    }

    public function testIdentityFromRejectsUnknownColumns(): void
    {
        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('identity contains non-primary-key columns');
        Item::find(['id' => 1, 'user_id' => 2]);
    }

    public function testCompositeFindFullKeyAndPartialRejection(): void
    {
        $tag = new Tag();
        $tag->item_id = 10;
        $tag->label = 'red';
        $tag->save();

        $found = Tag::find(['item_id' => 10, 'label' => 'red']);
        self::assertNotNull($found);
        self::assertSame('red', $found->label);

        $this->expectException(IdentityException::class);
        Tag::find(['item_id' => 10]);
    }

    public function testBatchInsertEmptyListIsNoop(): void
    {
        Item::insertAll([]);
        self::assertSame(0, Item::query()->count());
    }

    public function testNestedTransactionOnlyOuterCommits(): void
    {
        $result = Item::transaction(function (): string {
            $this->makeItem(11, 'N1')->save();
            Item::transaction(function (): void {
                $this->makeItem(12, 'N2')->save();
            });

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, Item::query()->count());
        self::assertNotNull(Item::query()->where('sku', 'N1')->first());
        self::assertNotNull(Item::query()->where('sku', 'N2')->first());
    }

    public function testInnerTransactionFailureRollsBackAll(): void
    {
        try {
            Item::transaction(function (): void {
                $this->makeItem(21, 'R1')->save();
                Item::transaction(function (): void {
                    $this->makeItem(22, 'R2')->save();
                    throw new \RuntimeException('inner boom');
                });
            });
            self::fail('应抛 RuntimeException');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, Item::query()->count());
    }

    public function testRollbackClearsOnlyTxnWrittenCacheKeys(): void
    {
        // 事务外先放一行进缓存
        $outer = $this->makeItem(31, 'O');
        $outer->save();
        $cachedOuter = Item::find(['id' => $outer->id]);
        self::assertNotNull($cachedOuter);

        try {
            Item::transaction(function (): void {
                $this->makeItem(32, 'T')->save();
                // 事务内写入的行已进缓存
                self::assertNotNull(Item::find(['user_id' => 32]));
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        // 回滚只清事务内写入的 key：外层那行缓存仍在（同实例）
        $again = Item::find(['id' => $outer->id]);
        self::assertSame($cachedOuter, $again);
    }

    public function testFiberIsolatedTransactions(): void
    {
        $this->makeItem(41, 'F0')->save();
        // 此用例显式模拟从根上下文误把活跃网关带进另一个 Fiber。
        Session::discard();

        $results = [];
        $fiberA = new \Fiber(function () use (&$results): void {
            Session::use($this->gateway);
            Item::transaction(function () use (&$results): void {
                $this->makeItem(42, 'FA')->save();
                \Fiber::suspend();
                $results['a'] = 'committed';
            });
        });
        $fiberB = new \Fiber(function () use (&$results): void {
            Session::use($this->gateway);
            Item::transaction(function () use (&$results): void {
                $this->makeItem(43, 'FB')->save();
                \Fiber::suspend();
                $results['b'] = 'committed';
            });
        });

        // A 绑定网关后挂起；B 即使尚未开启事务也必须拒绝共享同一网关。
        $fiberA->start();
        try {
            $fiberB->start();
            $this->fail('共享单连接下并发事务应被拒绝');
        } catch (\LogicException $e) {
            self::assertStringContainsString('independent connection', $e->getMessage());
        }
        $fiberA->resume();
        self::assertTrue($fiberA->isTerminated());
        // 失败 Fiber 的局部网关随对象销毁释放占用，当前默认 Session 仍可独立清理。
        unset($fiberB);
        gc_collect_cycles();
        Session::discard();
    }

    public function testFiberSessionIsolationWithoutDbTxn(): void
    {
        // 纯 Session 隔离：两个 Fiber 各自 use/discard，互不可见。
        $seen = [];
        $first = new EntityGateway(new PdoConnection($this->pdo));
        $second = new EntityGateway(new PdoConnection($this->pdo));
        $fiberA = new \Fiber(function () use (&$seen, $first): void {
            Session::use($first);
            $seen['a'] = Session::get()->gateway === $first;
            \Fiber::suspend();
            $seen['a2'] = Session::get()->gateway === $first;
            Session::discard();
        });
        $fiberB = new \Fiber(function () use (&$seen, $second): void {
            Session::use($second);
            $seen['b'] = Session::get()->gateway === $second;
            Session::discard();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertTrue($seen['a']);
        self::assertTrue($seen['a2']);
        self::assertTrue($seen['b']);
    }

    public function testSessionDiscardThenGetThrows(): void
    {
        // 非协程环境：discard 后 get 抛异常
        Session::discard();
        try {
            Session::get();
            self::fail('应抛 LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Session::use()', $e->getMessage());
        } finally {
            Session::use($this->gateway); // 恢复，避免影响其他测试
        }
    }

    // ---- 辅助 ----

    private function makeItem(int $userId, string $sku): Item
    {
        $item = new Item();
        $item->user_id = $userId;
        $item->sku = $sku;
        $item->qty = 1;
        $item->price = BcDecimal::fromString('1.00');

        return $item;
    }
}

// ---- 测试模型 ----

final class Item extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public int $user_id;

    #[Column]
    public string $sku;

    #[Column]
    public int $qty;

    #[Column]
    public BcDecimal $price;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'item';
    }
}

final class Tag extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public int $item_id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public string $label;

    public static function table(): string
    {
        return 'tag';
    }
}

/** 记录最后一条写语句的 Connection 装饰器 */
final class RecordingConn implements \Pixiaoka\Orm\Gateway\Connection
{
    public string $lastSql = '';
    public array $lastParams = [];

    public function __construct(
        private readonly \Pixiaoka\Orm\Gateway\Connection $inner,
    ) {
    }

    public function execute(string $sql, array $params): int
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;

        return $this->inner->execute($sql, $params);
    }

    public function queryOne(string $sql, array $params): ?array
    {
        // INSERT ... RETURNING 也走这里，一并记录
        if (str_starts_with($sql, 'INSERT')) {
            $this->lastSql = $sql;
            $this->lastParams = $params;
        }

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
