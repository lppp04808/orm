<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\DriverAwareConnection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\JoinClause;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrmComplexQueryTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE complex_order (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            sku TEXT NOT NULL,
            qty INTEGER NOT NULL,
            price TEXT NOT NULL,
            happened_at TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE complex_tag (
            order_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            PRIMARY KEY (order_id, label)
        )');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
        foreach ([
            [1, 'A', 2, '10.50', '2026-01-01 00:00:00'],
            [1, 'B', 3, '20.00', '2026-01-02 00:00:00'],
            [2, 'C', 4, '30.00', '2026-01-03 00:00:00'],
            [2, 'D', 8, '40.00', '2026-01-04 00:00:00'],
        ] as [$user, $sku, $qty, $price, $time]) {
            $order = new ComplexOrder();
            $order->user_id = $user;
            $order->sku = $sku;
            $order->qty = $qty;
            $order->price = BcDecimal::fromString($price);
            $order->happened_at = new DateTimeImmutable($time, new DateTimeZone('UTC'));
            $order->save();
        }
        ComplexTag::insertAll([
            ['order_id' => 1, 'label' => 'new'],
            ['order_id' => 1, 'label' => 'paid'],
            ['order_id' => 2, 'label' => 'paid'],
            ['order_id' => 3, 'label' => 'void'],
        ]);
    }

    public function testJoinProjectionAndOnBinding(): void
    {
        $query = ComplexOrder::query()
            ->alias('o')
            ->selectAs('o.sku', 'order_sku')
            ->selectAs('t.label', 'tag_label')
            ->leftJoin('complex_tag', 't', fn (JoinClause $join) => $join
                ->on('o.id', '=', 't.order_id')
                ->where('t.label', '=', 'paid'))
            ->where('o.user_id', 1)
            ->orderBy('o.id');

        self::assertSame([
            ['order_sku' => 'A', 'tag_label' => 'paid'],
            ['order_sku' => 'B', 'tag_label' => 'paid'],
        ], $query->rows());
        self::assertSame('paid', $query->build()['params']['q0']);
        self::assertSame(1, $query->build()['params']['q1']);
        self::assertSame($query->build(), $query->build(), '重复 build 的 SQL 和参数必须稳定');
    }

    public function testJoinDefaultProjectionQualifiesBaseColumns(): void
    {
        $sql = ComplexOrder::query()->alias('o')
            ->join('complex_tag', 't', fn (JoinClause $join) => $join->on('o.id', '=', 't.order_id'))
            ->build()['sql'];
        self::assertStringContainsString('SELECT "o"."id", "o"."user_id"', $sql);
        self::assertStringContainsString(' INNER JOIN "complex_tag" AS "t" ON "o"."id" = "t"."order_id"', $sql);
    }

    public function testGroupByHavingAndAggregates(): void
    {
        $rows = ComplexOrder::query()
            ->select('user_id')
            ->aggregate('COUNT', '*', 'orders')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->aggregate('AVG', 'qty', 'average_qty')
            ->aggregate('MIN', 'qty', 'min_qty')
            ->aggregate('MAX', 'qty', 'max_qty')
            ->groupBy('user_id')
            ->having('SUM(qty)', '>=', 5)
            ->rows();

        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['user_id']);
        self::assertSame(2, (int) $rows[1]['user_id']);
        self::assertSame(2, (int) $rows[1]['orders']);
        self::assertSame(12, (int) $rows[1]['total_qty']);
        self::assertSame(6.0, (float) $rows[1]['average_qty']);
        self::assertSame(4, (int) $rows[1]['min_qty']);
        self::assertSame(8, (int) $rows[1]['max_qty']);
    }

    public function testDistinctAndAggregateValue(): void
    {
        self::assertSame(17, (int) ComplexOrder::query()->aggregate('SUM', 'qty')->value());
        self::assertSame(2, (int) ComplexOrder::query()->aggregate('COUNT', 'user_id', distinct: true)->value());
        self::assertSame(2, ComplexOrder::query()->select('user_id')->distinct()->count());
    }

    public function testGroupedCountIgnoresTopLevelPaginationButNotBranchPagination(): void
    {
        $grouped = ComplexOrder::query()->select('user_id')->groupBy('user_id')->limit(1);
        self::assertSame(2, $grouped->count());

        $union = ComplexOrder::query()->select('user_id')->where('user_id', 1)->limit(1)
            ->unionAll(ComplexOrder::query()->select('user_id')->where('user_id', 2)->limit(1));
        self::assertSame(2, $union->count());
    }

    public function testInScalarExistsSubqueriesAndParameterOrder(): void
    {
        $paidOrders = ComplexTag::query()->select('order_id')->where('label', 'paid');
        $rows = ComplexOrder::query()->whereIn('id', $paidOrders)->orderBy('id')->rows();
        self::assertSame([1, 2], array_map(fn (array $row): int => (int) $row['id'], $rows));

        $maxQty = ComplexOrder::query()->aggregate('MAX', 'qty');
        self::assertSame('D', ComplexOrder::query()->whereOp('qty', '=', $maxQty)->row()['sku']);

        $exists = ComplexTag::query()->select('order_id')
            ->whereRaw('complex_tag.order_id = complex_order.id')
            ->where('label', 'paid');
        self::assertCount(2, ComplexOrder::query()->whereExists($exists)->rows());
        self::assertCount(2, ComplexOrder::query()->whereNotExists($exists)->rows());

        $query = ComplexOrder::query()->where('user_id', 1)
            ->whereIn('id', ComplexTag::query()->select('order_id')->where('label', 'paid'))
            ->whereRaw('qty > :minimum AND sku != :literal', ['minimum' => 2, 'literal' => 'Z'])
            ->orderBy('id');
        self::assertSame(['q0' => 1, 'q1' => 'paid', 'q2' => 2, 'q3' => 'Z'], $query->build()['params']);
        self::assertSame(['B'], array_column($query->rows(), 'sku'));
    }

    public function testUnionAndDerivedTable(): void
    {
        $first = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)->orderBy('id')->limit(1);
        $second = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2)->orderBy('id', 'DESC')->limit(1);
        $rows = $first->unionAll($second)->orderBy('name', 'DESC')->rows();
        self::assertSame(['D', 'A'], array_column($rows, 'name'));

        $top = ComplexOrder::query()->selectAs('user_id', 'uid')->selectAs('qty', 'amount')
            ->whereOp('qty', '>=', 3);
        $rows = ComplexOrder::query()->select('uid')->from($top, 'ranked')
            ->whereOp('amount', '>', 5)->rows();
        self::assertSame([2], array_map('intval', array_column($rows, 'uid')));
    }

    public function testOffsetWithoutLimit(): void
    {
        $query = ComplexOrder::query()->select('id')->orderBy('id')->offset(2);
        self::assertStringEndsWith(' LIMIT -1 OFFSET 2', $query->build()['sql']);
        self::assertSame([3, 4], array_map('intval', array_column($query->rows(), 'id')));
    }

    public function testRawSqlFragmentsAndFullRaw(): void
    {
        $query = ComplexOrder::query()->selectRaw('sku, qty * :multiplier AS score', ['multiplier' => 2])
            ->whereRaw('qty > :minimum', ['minimum' => 2])
            ->orderByRaw('id + :position', ['position' => 0]);
        $built = $query->build();
        self::assertSame(['q0' => 2, 'q1' => 2, 'q2' => 0], $built['params']);
        self::assertStringNotContainsString(':multiplier', $built['sql']);
        self::assertSame(['B', 'C', 'D'], array_column($query->rows(), 'sku'));

        $raw = ComplexOrder::raw(
            "WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < :last) "
            . "SELECT n, ':not_a_param' AS literal /* :also_not_a_param */ FROM seq",
            ['last' => 3],
        );
        self::assertSame([1, 2, 3], array_map('intval', array_column(ComplexOrder::queryRaw($raw), 'n')));
        self::assertSame(['n' => 2, 'literal' => '文本'], ComplexOrder::queryOneRaw(
            'SELECT :value AS n, \'文本\' AS literal', ['value' => 2],
        ));
    }

    public function testRawRejectsUnboundExtraAndPositionalParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->whereRaw('qty > :qty', [])->build();
    }

    public function testRawRejectsExtraParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::queryRaw('SELECT 1', ['unused' => 1]);
    }

    public function testRawRejectsQuestionMarkPlaceholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::queryRaw('SELECT ?', [1]);
    }

    public function testTypedWhereUpdateAndUpsertValues(): void
    {
        $time = new DateTimeImmutable('2026-01-02 08:00:00', new DateTimeZone('Asia/Shanghai'));
        $built = ComplexOrder::query()->where('price', BcDecimal::fromString('20.00'))
            ->where('happened_at', $time)->build();
        self::assertSame(['q0' => '20.00', 'q1' => '2026-01-02 00:00:00'], $built['params']);
        self::assertSame('B', ComplexOrder::query()->where('price', BcDecimal::fromString('20.00'))->row()['sku']);

        self::assertSame(1, ComplexOrder::query()->where('sku', 'B')->update([
            'price' => BcDecimal::fromString('22.50'),
            'happened_at' => $time,
        ]));
        $row = ComplexOrder::query()->where('sku', 'B')->row();
        self::assertSame('22.50', $row['price']);
        self::assertSame('2026-01-02 00:00:00', $row['happened_at']);

        ComplexOrder::upsert([
            'id' => 2,
            'user_id' => 1,
            'sku' => 'B2',
            'qty' => 30,
            'price' => BcDecimal::fromString('23.50'),
            'happened_at' => $time,
        ]);
        $row = ComplexOrder::query()->where('id', 2)->row();
        self::assertSame('B2', $row['sku']);
        self::assertSame(30, (int) $row['qty']);
        self::assertSame('23.50', $row['price']);
    }

    public function testUpsertIgnoreAndNaturalCompositeKey(): void
    {
        ComplexTag::upsert(['order_id' => 1, 'label' => 'new'], []);
        self::assertSame(4, ComplexTag::query()->count());
        ComplexTag::upsert(['order_id' => 4, 'label' => 'new'], []);
        self::assertSame(5, ComplexTag::query()->count());
    }

    public function testConditionalUpdateDeleteAndCacheInvalidation(): void
    {
        $cached = ComplexOrder::find(['id' => 1]);
        self::assertSame('A', $cached->sku);
        self::assertSame(1, ComplexOrder::query()->where('id', 1)->update(['sku' => 'A2']));
        self::assertSame('A2', ComplexOrder::find(['id' => 1])->sku);
        self::assertNotSame($cached, ComplexOrder::find(['id' => 1]));

        $cached = ComplexOrder::find(['id' => 2]);
        $survivor = ComplexOrder::find(['id' => 3]);
        self::assertSame(1, ComplexOrder::query()->where('id', 2)->delete());
        self::assertNull(ComplexOrder::find(['id' => 2]));
        self::assertTrue($cached->isNew(), '实际删除的缓存实体必须失效，不能再次按已持久化对象保存');
        self::assertFalse($survivor->isNew(), '未删除的缓存实体不能被误标为未入库');
        $survivor->qty = 9;
        $survivor->save();
        self::assertSame(9, (int) ComplexOrder::query()->where('id', 3)->value('qty'));
    }

    public function testCloneIsolatesTopLevelStateAndKeepsBusinessValueIdentity(): void
    {
        $time = new DateTimeImmutable('2026-01-02 08:00:00', new DateTimeZone('Asia/Shanghai'));
        $original = ComplexOrder::query()
            ->where('happened_at', $time)
            ->where('qty', 2)
            ->orderBy('id')
            ->limit(3)
            ->offset(1);
        $baseline = $original->build();

        $clone = clone $original;
        $clone->where('sku', 'B')->orderBy('sku', 'DESC')->limit(1)->offset(0);

        self::assertNotSame($original->state, $clone->state);
        self::assertSame($time, $original->state->where[0]['value']);
        self::assertSame($time, $clone->state->where[0]['value'], '业务值对象不应被无意义克隆');
        self::assertSame($baseline, $original->build());
        self::assertSame($baseline, $original->build(), '克隆和重复编译都不能改写原查询');
        self::assertStringNotContainsString(':q2', $baseline['sql']);
        self::assertStringContainsString(':q2', $clone->build()['sql']);
        self::assertSame('B', $clone->build()['params']['q2']);
        self::assertStringEndsWith(' LIMIT 1', $clone->build()['sql']);
    }

    public function testCloneIsolatesNestedScalarInAndOrSubqueries(): void
    {
        $scalar = ComplexOrder::query()->select('id')->where('user_id', 1);
        $in = ComplexTag::query()->select('order_id')->where('label', 'paid');
        $exists = ComplexTag::query()->select('order_id')->where('label', 'new');
        $original = ComplexOrder::query()
            ->whereOp('id', '>', $scalar)
            ->whereIn('id', $in)
            ->orGroup(static function (Builder $group) use ($exists): void {
                $group->where('sku', 'A')->orGroup(
                    static fn (Builder $nested): Builder => $nested->whereExists($exists),
                );
            });
        $baseline = $original->build();

        $clone = clone $original;
        $scalarClone = $clone->state->where[0]['value'];
        $inClone = $clone->state->where[1]['value'];
        $existsClone = $clone->state->orGroups[0]['groups'][0]['conditions'][0]['value'];
        self::assertNotSame($scalar, $scalarClone);
        self::assertNotSame($in, $inClone);
        self::assertNotSame($exists, $existsClone);

        $scalarClone->where('sku', 'C');
        $inClone->where('order_id', 2);
        $existsClone->where('order_id', 3);

        self::assertSame($baseline, $original->build());
        $cloneBuild = $clone->build();
        self::assertContains('C', $cloneBuild['params']);
        self::assertContains(2, $cloneBuild['params']);
        self::assertContains(3, $cloneBuild['params']);
        self::assertNotContains('C', $original->build()['params']);
        self::assertSame($cloneBuild, $clone->build(), '克隆查询重复编译也必须稳定');
    }

    public function testCloneIsolatesUnionDerivedTableAndJoinClause(): void
    {
        $derived = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1);
        $branch = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2);
        $original = ComplexOrder::query()->select('name')->from($derived, 'ranked')->unionAll($branch);
        $baseline = $original->build();

        $clone = clone $original;
        $derivedClone = $clone->state->derivedFrom['builder'];
        $branchClone = $clone->state->unions[0]['builder'];
        self::assertNotSame($derived, $derivedClone);
        self::assertNotSame($branch, $branchClone);
        $derivedClone->where('qty', 2);
        $branchClone->where('qty', 8);

        self::assertSame($baseline, $original->build());
        $original->countSql();
        self::assertSame($baseline, $original->build(), '复杂 COUNT 只能修改自己的编译状态');
        self::assertContains(2, $clone->build()['params']);
        self::assertContains(8, $clone->build()['params']);
        self::assertNotContains(8, $original->build()['params']);

        $joined = ComplexOrder::query()->alias('o')
            ->leftJoin('complex_tag', 't', static fn (JoinClause $join): JoinClause => $join
                ->on('o.id', '=', 't.order_id'));
        $joinBaseline = $joined->build();
        $joinedClone = clone $joined;
        self::assertNotSame($joined->state->joins[0]['on'], $joinedClone->state->joins[0]['on']);
        $joinedClone->state->joins[0]['on']->where('t.label', '=', 'new');
        self::assertCount(1, $joined->state->joins[0]['on']->conditions());
        self::assertCount(2, $joinedClone->state->joins[0]['on']->conditions());
        self::assertSame($joinBaseline, $joined->build());
        self::assertSame('new', $joinedClone->build()['params']['q0']);
    }

    public function testEmptyOrGroupCannotBypassWriteGuard(): void
    {
        $empty = static function (): void {};
        foreach ([
            fn () => ComplexOrder::query()->orGroup($empty)->update(['sku' => 'unsafe']),
            fn () => ComplexOrder::query()->orGroup($empty)->delete(),
        ] as $write) {
            try {
                $write();
                self::fail('空 OR 分组不能绕过 WHERE 写保护');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('WHERE', $e->getMessage());
            }
        }
        self::assertSame(4, ComplexOrder::query()->count());
    }

    public function testEmptyInAndNotIn(): void
    {
        self::assertSame(0, ComplexOrder::query()->whereIn('id', [])->count());
        self::assertSame(4, ComplexOrder::query()->whereNotIn('id', [])->count());
    }

    public function testNullComparisons(): void
    {
        $built = ComplexOrder::query()->where('sku', null)->whereOp('qty', '<>', null)->build();
        self::assertStringContainsString('"sku" IS NULL AND "qty" IS NOT NULL', $built['sql']);
        self::assertSame([], $built['params']);
    }

    public function testWriteGuards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->update(['sku' => 'unsafe']);
    }

    public function testDeleteRequiresWhere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->delete();
    }

    public function testPrimaryKeyCannotBeMassUpdated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->where('id', 1)->update(['id' => 5]);
    }

    public function testComplexResultCannotHydratePartialEntity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rows()');
        ComplexOrder::query()->select('id')->get();
    }

    public function testSubqueryMustSelectOneColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('select()');
        ComplexOrder::query()->whereIn('id', ComplexTag::query())->build();
    }

    public function testEmptyJoinConditionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->join('complex_tag', 't', static function (): void {})->build();
    }

    public function testNestedLockInExistsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->whereExists(
            ComplexTag::query()->whereExists(ComplexTag::query()->select('order_id')->forUpdate()),
        )->build();
    }

    public function testIllegalAggregateAndHavingAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->aggregate('MEDIAN', 'qty');
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->limit(-1);
    }

    public function testLockBuildsCorrectClausePosition(): void
    {
        self::assertStringEndsWith(' LIMIT 1 FOR UPDATE', ComplexOrder::query()->forUpdate()->limit(1)->build()['sql']);
    }

    public function testLockModesAndExecution(): void
    {
        self::assertStringEndsWith(' FOR SHARE', ComplexOrder::query()->forShare()->build()['sql']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite');
        ComplexOrder::query()->forUpdate()->rows();
    }

    public function testUnionCannotContainLock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComplexOrder::query()->forUpdate()->union(ComplexOrder::query()->select('id'))->build();
    }

    public function testMysqlDialectForUpsertOffsetLockAndQuotes(): void
    {
        $connection = new FakeDialectConnection('mysql');
        $gateway = new EntityGateway($connection);
        Session::use($gateway);

        $sql = ComplexOrder::query()->select('id')->offset(3)->forUpdate()->build()['sql'];
        self::assertStringContainsString('SELECT `id` FROM `complex_order` LIMIT 18446744073709551615 OFFSET 3 FOR UPDATE', $sql);
        ComplexOrder::upsert([
            'id' => 2, 'user_id' => 1, 'sku' => 'B', 'qty' => 3,
            'price' => BcDecimal::fromString('1.00'),
            'happened_at' => new DateTimeImmutable('2026-01-02 00:00:00'),
        ], ['qty']);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE `qty` = VALUES(`qty`), `updated_at` = VALUES(`updated_at`)', $connection->lastSql);
        self::assertSame(3, $connection->lastParams['upsert_qty']);
    }

    public function testPgsqlDialectForUpsertAndLock(): void
    {
        $connection = new FakeDialectConnection('pgsql');
        $gateway = new EntityGateway($connection);
        Session::use($gateway);

        self::assertStringEndsWith(' FOR SHARE OFFSET 2', ComplexOrder::query()->select('id')->offset(2)->forShare()->build()['sql']);
        ComplexOrder::upsert([
            'id' => 2, 'user_id' => 1, 'sku' => 'B', 'qty' => 3,
            'price' => BcDecimal::fromString('1.00'),
            'happened_at' => new DateTimeImmutable('2026-01-02 00:00:00'),
        ], ['qty']);
        self::assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET "qty" = excluded."qty"', $connection->lastSql);
    }
}

final class ComplexOrder extends Entity
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
    #[Temporal]
    public DateTimeImmutable $happened_at;
    #[Column]
    public int $created_at;
    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'complex_order';
    }
}

final class ComplexTag extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public int $order_id;
    #[Column]
    #[PrimaryKey(order: 2)]
    public string $label;

    public static function table(): string
    {
        return 'complex_tag';
    }
}

/** 仅生成方言 SQL，不模拟真实 MySQL/PG 执行；实连脚本负责实际执行。 */
final class FakeDialectConnection implements Connection, DriverAwareConnection
{
    public string $lastSql = '';
    public array $lastParams = [];
    public array $queries = [];
    public array $selectResults = [];

    public function __construct(private readonly string $dialect)
    {
    }

    public function execute(string $sql, array $params): int
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;

        return 1;
    }

    public function queryOne(string $sql, array $params): ?array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->selectResults[0] ?? null;
    }

    public function queryAll(string $sql, array $params): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->selectResults;
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) !== 1) {
                throw new \InvalidArgumentException('非法标识符');
            }
        }
        $quote = $this->dialect === 'mysql' ? '`' : '"';

        return implode('.', array_map(fn (string $part): string => $quote . $part . $quote, $parts));
    }

    public function supportsReturning(): bool
    {
        return $this->dialect !== 'mysql';
    }

    public function driver(): string
    {
        return $this->dialect;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }
}
