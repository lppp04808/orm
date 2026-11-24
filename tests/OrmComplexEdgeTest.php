<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use InvalidArgumentException;
use LogicException;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Query\JoinClause;
use Pixiaoka\Orm\Session;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

// ComplexOrder / ComplexTag / FakeDialectConnection 定义在 OrmComplexQueryTest.php 底部（多类文件，自动加载不可达）
require_once __DIR__ . '/OrmComplexQueryTest.php';

/**
 * 复杂查询高价值边界用例（在 OrmComplexQueryTest 既有场景之外补充）：
 * LEFT JOIN 未命中分支、UNION 首/后续分支局部分页与顶层分页、嵌套 UNION 派生表、
 * 参数顺序、跨 gateway 拒绝、UNION 输出形状/重复别名、条件写拒绝投影/排序/分页/锁、
 * raw 重复参数/引号注释/PG ::、Expression 严格匹配、UPSERT 时间戳语义。
 */
final class OrmComplexEdgeTest extends TestCase
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
        // 与 OrmComplexQueryTest 同构的基准数据：订单 id=1..4
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
            $order->price = \Pixiaoka\Orm\Cast\BcDecimal::fromString($price);
            $order->happened_at = new DateTimeImmutable($time, new DateTimeZone('UTC'));
            $order->save();
        }
        // 注意：id=2（sku=B）没有任何标签 → 用于 LEFT JOIN 真正 unmatched 分支
        ComplexTag::insertAll([
            ['order_id' => 1, 'label' => 'new'],
            ['order_id' => 1, 'label' => 'paid'],
            ['order_id' => 3, 'label' => 'void'],
        ]);
    }

    // ==================== LEFT JOIN 真正 unmatched 分支 ====================

    public function testLeftJoinUnmatchedRowKeepsBaseRowWithNulls(): void
    {
        $rows = ComplexOrder::query()
            ->alias('o')
            ->selectAs('o.sku', 'order_sku')
            ->selectAs('t.label', 'tag_label')
            ->leftJoin('complex_tag', 't', fn (JoinClause $join) => $join->on('o.id', '=', 't.order_id'))
            ->where('o.sku', 'B') // id=2 没有任何标签
            ->rows();

        self::assertCount(1, $rows, 'LEFT JOIN 必须保留无匹配标签的基表行');
        self::assertSame('B', $rows[0]['order_sku']);
        self::assertNull($rows[0]['tag_label'], '未命中的 JOIN 列必须是 NULL，而不是整行消失');

        // 对比 INNER JOIN：同一条件下必须一行都没有
        self::assertSame(0, ComplexOrder::query()
            ->alias('o')
            ->join('complex_tag', 't', fn (JoinClause $join) => $join->on('o.id', '=', 't.order_id'))
            ->where('o.sku', 'B')
            ->count());
    }

    public function testLeftJoinOnValueBindingStillAllowsUnmatched(): void
    {
        // ON 中带值条件：id=1 只有 new/paid，ON label='void' 时 id=1 也必须保留
        $rows = ComplexOrder::query()
            ->alias('o')
            ->selectAs('o.id', 'order_id')
            ->selectAs('t.label', 'tag_label')
            ->leftJoin('complex_tag', 't', fn (JoinClause $join) => $join
                ->on('o.id', '=', 't.order_id')
                ->where('t.label', '=', 'void'))
            ->where('o.id', 1)
            ->rows();

        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['order_id']);
        self::assertNull($rows[0]['tag_label'], 'ON 值条件不满足时 LEFT JOIN 同样保留基表行');
    }

    // ==================== GROUP 两组断言（含被 HAVING 排除组的真实内容） ====================

    public function testGroupByTwoGroupsExactAggregates(): void
    {
        $rows = ComplexOrder::query()
            ->select('user_id')
            ->aggregate('COUNT', '*', 'orders')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->aggregate('MIN', 'qty', 'min_qty')
            ->aggregate('MAX', 'qty', 'max_qty')
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->rows();

        self::assertCount(2, $rows);
        // 组 1：A(qty=2) + B(qty=3)
        self::assertSame(1, (int) $rows[0]['user_id']);
        self::assertSame(2, (int) $rows[0]['orders']);
        self::assertSame(5, (int) $rows[0]['total_qty']);
        self::assertSame(2, (int) $rows[0]['min_qty']);
        self::assertSame(3, (int) $rows[0]['max_qty']);
        // 组 2：C(qty=4) + D(qty=8)
        self::assertSame(2, (int) $rows[1]['user_id']);
        self::assertSame(2, (int) $rows[1]['orders']);
        self::assertSame(12, (int) $rows[1]['total_qty']);
        self::assertSame(4, (int) $rows[1]['min_qty']);
        self::assertSame(8, (int) $rows[1]['max_qty']);

        // HAVING SUM(qty) > 5 只留组 2（组 1 恰好 5，边界被排除）
        $filtered = ComplexOrder::query()
            ->select('user_id')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->groupBy('user_id')
            ->having('SUM(qty)', '>', 5)
            ->rows();
        self::assertCount(1, $filtered);
        self::assertSame(2, (int) $filtered[0]['user_id']);
    }

    // ==================== UNION 首分支 / 后续分支局部分页 + 顶层分页 ====================

    public function testUnionFirstBranchLocalOrderAndLimitFrozen(): void
    {
        $first = ComplexOrder::query()->selectAs('sku', 'name')
            ->where('user_id', 1)->orderBy('id', 'DESC')->limit(1); // B
        $second = ComplexOrder::query()->selectAs('sku', 'name')
            ->where('user_id', 2)->orderBy('id')->limit(1); // C
        $rows = $first->unionAll($second)->rows();

        self::assertSame(['B', 'C'], array_column($rows, 'name'),
            '首分支 DESC limit 1 应取 B，后续分支 ASC limit 1 应取 C');
    }

    public function testUnionSecondBranchOwnOffsetInsideDerivedBranch(): void
    {
        // 首分支无分页；后续分支 user_id=2 按 id ASC offset 1 limit 1 → D
        $first = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)->orderBy('id')->limit(1); // A
        $second = ComplexOrder::query()->selectAs('sku', 'name')
            ->where('user_id', 2)->orderBy('id')->limit(1)->offset(1); // D
        $sql = $first->unionAll($second)->build()['sql'];
        self::assertStringContainsString('OFFSET 1', $sql, '后续分支的 offset 必须落在派生表分支内部');
        $rows = $first->rows();
        self::assertSame(['A', 'D'], array_column($rows, 'name'));
    }

    public function testUnionTopLevelOrderLimitAndOffset(): void
    {
        $union = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)
            ->unionAll(ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2));
        // 顶层 DESC：D C B A；offset 1 limit 2 → C B
        $rows = $union->orderBy('name', 'DESC')->limit(2)->offset(1)->rows();
        self::assertSame(['C', 'B'], array_column($rows, 'name'));
        $sql = $union->build()['sql'];
        self::assertStringContainsString('orm_union_result', $sql, '顶层排序必须包裹 UNION 派生表');
        self::assertStringEndsWith('LIMIT 2 OFFSET 1', $sql, '顶层分页在派生表之外');
    }

    public function testUnionBranchPaginationDoesNotLeakIntoTopLevel(): void
    {
        // 首分支局部 limit 1 被 union() 冻结；union 之后再 limit(10) 是顶层行为，不能覆盖分支
        $first = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)->limit(1);
        $second = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2)->limit(1);
        $union = $first->unionAll($second)->limit(10);
        self::assertSame(['A', 'C'], array_column($union->rows(), 'name'));
    }

    // ==================== 嵌套 UNION 用作派生表 ====================

    public function testNestedUnionAsDerivedTable(): void
    {
        $inner = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)
            ->unionAll(ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2));
        $rows = ComplexOrder::query()->select('name')->from($inner, 'u')
            ->whereOp('name', '>=', 'C')
            ->orderBy('name')
            ->rows();

        self::assertSame(['C', 'D'], array_column($rows, 'name'),
            '嵌套 UNION 派生表外层过滤/排序必须生效');
        $sql = ComplexOrder::query()->select('name')->from($inner, 'u')->build()['sql'];
        self::assertStringContainsString('FROM (SELECT', $sql);
        self::assertStringContainsString('UNION ALL', $sql);
    }

    public function testUnionInsideUnionSecondBranchStaysIsolated(): void
    {
        // (A) UNION ALL ((B) UNION ALL (C)) —— 第二个分支自身也是 UNION，不能泄漏括号结构
        $a = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'A');
        $b = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'B');
        $c = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'C');
        $union = $a->unionAll($b->unionAll($c));
        $rows = $union->rows();
        self::assertSame(['A', 'B', 'C'], array_column($rows, 'name'));
    }

    public function testNestedUnionAsLaterBranchPreservesOperatorsAndTopPagination(): void
    {
        $a = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'A');
        $b = ComplexOrder::query()->selectAs('sku', 'name')->whereIn('sku', ['B', 'C']);
        $c = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'B');
        $nested = $b->union($c)->orderBy('name', 'DESC')->limit(1); // 分支内先去重，再取 C
        $union = $a->unionAll($nested);
        self::assertSame(['A', 'C'], array_column($union->rows(), 'name'));

        $a = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'A');
        $b = ComplexOrder::query()->selectAs('sku', 'name')->whereIn('sku', ['B', 'C']);
        $c = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'B');
        $outerDistinct = $a->union($b->unionAll($c)); // A UNION (B UNION ALL B UNION ALL C) = A,B,C
        self::assertSame(['A', 'B', 'C'], array_column($outerDistinct->rows(), 'name'));
    }

    public function testNestedUnionBranchKeepsOffsetAndParameterScope(): void
    {
        $nested = ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 1)
            ->unionAll(ComplexOrder::query()->selectAs('sku', 'name')->where('user_id', 2))
            ->orderBy('name')->limit(1)->offset(1); // B
        $outer = ComplexOrder::query()->selectAs('sku', 'name')->where('sku', 'A')
            ->unionAll($nested);
        $built = $outer->build();
        self::assertSame(['q0' => 'A', 'q1' => 1, 'q2' => 2], $built['params']);
        self::assertStringContainsString('LIMIT 1 OFFSET 1', $built['sql']);
        self::assertSame(['A', 'B'], array_column($outer->rows(), 'name'));
    }

    public function testDerivedTableAcceptsParameterizedRawProjection(): void
    {
        $raw = ComplexOrder::query()->selectRaw('qty * :factor AS amount', ['factor' => 2])
            ->where('sku', 'D');
        $rows = ComplexOrder::query()->select('amount')->from($raw, 'd')
            ->whereRaw('amount = :expected', ['expected' => 16])->rows();
        self::assertSame([['amount' => 16]], array_map(
            static fn (array $row): array => ['amount' => (int) $row['amount']],
            $rows,
        ));
    }

    // ==================== 参数顺序（JOIN/HAVING/子查询/raw 混合） ====================

    public function testParameterOrderAcrossJoinHavingAndSubquery(): void
    {
        $paid = ComplexTag::query()->select('order_id')->where('label', 'paid');
        $query = ComplexOrder::query()
            ->alias('o')
            ->selectAs('o.sku', 'order_sku')
            ->selectAs('t.label', 'tag_label')
            ->leftJoin('complex_tag', 't', fn (JoinClause $join) => $join
                ->on('o.id', '=', 't.order_id')
                ->where('t.label', '=', 'paid')) // q0 = 'paid'（JOIN 最先渲染）
            ->whereIn('o.id', $paid)              // q1 = 'paid'（子查询）
            ->where('o.user_id', 1)               // q2 = 1
            ->orderBy('o.id');

        $built = $query->build();
        self::assertSame(['q0' => 'paid', 'q1' => 'paid', 'q2' => 1], $built['params']);
        self::assertSame([['order_sku' => 'A', 'tag_label' => 'paid']], $query->rows());

        // HAVING 参数排在 GROUP 渲染位置（WHERE 之后）
        $grouped = ComplexOrder::query()
            ->select('user_id')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->whereOp('qty', '>=', 2)   // q0 = 2
            ->groupBy('user_id')
            ->having('SUM(qty)', '>=', 12) // q1 = 12
            ->build();
        self::assertSame(['q0' => 2, 'q1' => 12], $grouped['params']);
        self::assertStringContainsString('HAVING SUM("qty") >= :q1', $grouped['sql']);
    }

    // ==================== 跨 gateway 子查询拒绝 ====================

    public function testCrossGatewaySubqueryIsRejected(): void
    {
        $otherPdo = new PDO('sqlite::memory:');
        $otherPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $otherGateway = new EntityGateway(new PdoConnection($otherPdo));

        $foreignSub = new \Pixiaoka\Orm\Query\Builder(ComplexTag::class, $otherGateway);
        $foreignSub->select('order_id');

        $cases = [
            'IN 子查询' => fn () => ComplexOrder::query()->whereIn('id', $foreignSub)->build(),
            'EXISTS 子查询' => fn () => ComplexOrder::query()->whereExists($foreignSub)->build(),
            'NOT EXISTS 子查询' => fn () => ComplexOrder::query()->whereNotExists($foreignSub)->build(),
            '派生表' => fn () => ComplexOrder::query()->from($foreignSub, 'x')->build(),
            'UNION 分支' => fn () => ComplexOrder::query()->union($foreignSub),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail("$name：跨 gateway 子查询必须被拒绝");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('EntityGateway', $e->getMessage(), $name);
            }
        }
    }

    // ==================== UNION 输出形状 / 重复别名拒绝 ====================

    public function testUnionRejectsDifferentOutputShape(): void
    {
        $a = ComplexOrder::query()->selectAs('sku', 'name');
        $b = ComplexOrder::query()->selectAs('sku', 'title'); // 别名不同
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION');
        $a->unionAll($b);
    }

    public function testUnionRejectsDifferentColumnCount(): void
    {
        $a = ComplexOrder::query()->selectAs('sku', 'name');
        $b = ComplexOrder::query()->selectAs('sku', 'name')->selectAs('qty', 'amount');
        $this->expectException(InvalidArgumentException::class);
        $a->unionAll($b);
    }

    public function testUnionRejectsRawOrUnaliasedAggregateBranch(): void
    {
        $a = ComplexOrder::query()->selectAs('sku', 'name');
        $b = ComplexOrder::query()->aggregate('MAX', 'qty'); // 聚合无别名
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION');
        $a->unionAll($b);
    }

    public function testUnionRejectsDuplicateAliasInsideBranch(): void
    {
        $a = ComplexOrder::query()->selectAs('sku', 'name')->selectAs('qty', 'name');
        $b = ComplexOrder::query()->selectAs('sku', 'name')->selectAs('qty', 'name');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate output column names or aliases');
        $a->unionAll($b);
    }

    public function testSelectDuplicateAliasRejectedOutsideUnion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate SELECT output alias');
        ComplexOrder::query()->selectAs('sku', 'x')->aggregate('COUNT', '*', 'x')->build();
    }

    // ==================== 条件写拒绝投影/排序/分页/锁 ====================

    public function testConditionalWriteRejectsProjectionOrderLimitAndLock(): void
    {
        $cases = [
            'UPDATE 带投影' => fn () => ComplexOrder::query()->select('id')->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带排序' => fn () => ComplexOrder::query()->orderBy('id')->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带分页' => fn () => ComplexOrder::query()->limit(1)->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带 offset' => fn () => ComplexOrder::query()->offset(1)->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带行锁' => fn () => ComplexOrder::query()->forUpdate()->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带 JOIN' => fn () => ComplexOrder::query()
                ->join('complex_tag', 't', fn (JoinClause $j) => $j->on('id', '=', 't.order_id'))
                ->where('id', 1)->update(['qty' => 1]),
            'UPDATE 带 GROUP' => fn () => ComplexOrder::query()->groupBy('user_id')->where('id', 1)->update(['qty' => 1]),
            'DELETE 带投影' => fn () => ComplexOrder::query()->select('id')->where('id', 1)->delete(),
            'DELETE 带排序' => fn () => ComplexOrder::query()->orderBy('id')->where('id', 1)->delete(),
            'DELETE 带分页' => fn () => ComplexOrder::query()->limit(1)->where('id', 1)->delete(),
            'DELETE 带行锁' => fn () => ComplexOrder::query()->forUpdate()->where('id', 1)->delete(),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail("$name：必须被拒绝");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('UPDATE/DELETE', $e->getMessage(), $name);
            }
        }
        // 拒绝之后数据未被改动
        self::assertSame(4, ComplexOrder::query()->count());
    }

    // ==================== raw：重复参数 / 引号注释 / PG ::（仅结构） ====================

    public function testRawRepeatedNamedParameterBindsTwice(): void
    {
        $built = ComplexOrder::query()
            ->whereRaw('qty > :min AND qty < :max + :min', ['min' => 1, 'max' => 10])
            ->build();
        self::assertSame(['q0' => 1, 'q1' => 10, 'q2' => 1], $built['params'],
            '同一命名参数出现两次必须各自绑定一个唯一占位符');
        self::assertStringNotContainsString(':min', $built['sql']);
        self::assertSame(4, ComplexOrder::query()
            ->whereRaw('qty > :min AND qty < :max + :min', ['min' => 1, 'max' => 10])->count());
    }

    public function testRawRepeatedParamAcrossFragmentsKeepsScope(): void
    {
        // 两个不同 raw 片段用同一个参数名，各自独立重新编号，不能串
        $built = ComplexOrder::query()
            ->selectRaw('qty * :factor AS score', ['factor' => 2])
            ->whereRaw('qty > :factor', ['factor' => 3])
            ->build();
        self::assertSame(['q0' => 2, 'q1' => 3], $built['params']);
    }

    public function testRawIgnoresQuotedCommentedAndPgCastPlaceholders(): void
    {
        // 引号/注释里的 :fake 不是参数；PG ::cast 不是参数（仅结构断言，不执行 PG 语法）
        $built = ComplexOrder::query()
            ->whereRaw("qty > :real AND ':fake' = ':fake' /* :also_fake */ -- :line_fake\n", ['real' => 2])
            ->build();
        self::assertSame(['q0' => 2], $built['params']);
        self::assertStringContainsString("':fake'", $built['sql'], '引号内字面量必须原样保留');
        self::assertStringContainsString('/* :also_fake */', $built['sql']);
        self::assertStringContainsString('-- :line_fake', $built['sql']);

        $cast = ComplexOrder::query()->whereRaw("price::text = :val", ['val' => '20.00'])->build();
        self::assertSame(['q0' => '20.00'], $cast['params']);
        self::assertStringContainsString('price::text', $cast['sql'], 'PG :: 类型转换不能被当成命名参数');

        $boundCast = ComplexOrder::query()->whereRaw('qty = :value::int', ['value' => 2])->build();
        self::assertStringEndsWith(' FROM "complex_order" WHERE qty = :q0::int', $boundCast['sql']);
        self::assertSame(['q0' => 2], $boundCast['params'], '绑定参数后的 ::int 是类型转换，不能拆出额外参数');

        // 双引号标识符内的冒号、反引号内的冒号也不算参数
        $quoted = ComplexOrder::query()->whereRaw('qty > :v AND "col:x" = `col:y`', ['v' => 1])->build();
        self::assertSame(['q0' => 1], $quoted['params']);
    }

    public function testRawUnusedParamIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unused parameters');
        ComplexOrder::query()->whereRaw('qty > :min', ['min' => 1, 'extra' => 2])->build();
    }

    // ==================== Expression 参数严格匹配（queryRaw 逃生舱） ====================

    public function testExpressionParamsStrictlyMatched(): void
    {
        // 1. Expression 自带参数时不得再传第二份参数
        try {
            ComplexOrder::queryRaw(ComplexOrder::raw('SELECT :a AS v', ['a' => 1]), ['b' => 2]);
            self::fail('Expression 已携带参数，再传 $params 必须拒绝');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Expression', $e->getMessage());
        }

        // 2. 参数比占位符多 → 拒绝
        try {
            ComplexOrder::queryRaw('SELECT :a AS v', ['a' => 1, 'b' => 2]);
            self::fail('多余参数必须拒绝');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('parameter mismatch', $e->getMessage());
        }

        // 3. 占位符没有对应参数 → 拒绝
        try {
            ComplexOrder::queryRaw('SELECT :a AS v', []);
            self::fail('缺失参数必须拒绝');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('parameter mismatch', $e->getMessage());
        }

        // 4. 非命名（数字索引）参数 → 拒绝
        try {
            ComplexOrder::queryRaw('SELECT :a AS v', [1]);
            self::fail('数字索引参数必须拒绝');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('named placeholders', $e->getMessage());
        }

        // 5. 非标量参数值 → 拒绝
        try {
            ComplexOrder::queryRaw('SELECT :a AS v', ['a' => ['array']]);
            self::fail('非标量参数值必须拒绝');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('scalar', $e->getMessage());
        }

        // 6. 引号/注释/:: 与 Builder raw 一致地忽略
        $row = ComplexOrder::queryOneRaw(
            "SELECT :real AS v, ':fake' AS lit /* :nope */",
            ['real' => 42],
        );
        self::assertSame(42, (int) $row['v']);
        self::assertSame(':fake', $row['lit']);

        // 7. 重复命名参数只算一个 used，但同一参数可多次出现（PDO 命名参数原生支持）
        $row = ComplexOrder::queryOneRaw('SELECT :a AS x, :a AS y', ['a' => 7]);
        self::assertSame(7, (int) $row['x']);
        self::assertSame(7, (int) $row['y']);
    }

    public function testRawIgnoresPostgresDollarQuotedText(): void
    {
        $sql = 'SELECT $fn$:not_a_param$fn$ AS literal';
        $built = ComplexOrder::query()->selectRaw($sql)->build();
        self::assertSame('SELECT ' . $sql . ' FROM "complex_order"', $built['sql']);
        self::assertSame([], $built['params']);
    }

    public function testFullRawValidatesNamedParameterImmediatelyBeforePgCast(): void
    {
        $connection = new FakeDialectConnection('pgsql');
        $gateway = new EntityGateway($connection);
        $sql = 'SELECT $fn$:not_a_param$fn$ AS literal, :value::int AS n';

        $gateway->selectOneRaw($sql, ['value' => 42]);

        self::assertSame([
            ['sql' => $sql, 'params' => ['value' => 42]],
        ], $connection->queries);
    }

    public function testPdoPgsqlNormalizesDollarQuotedTextBeforeBinding(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('pgsql');
        $pdo->expects(self::once())->method('prepare')->with(
            "SELECT E':not_a_param ? ''quoted'' \\\\ slash' AS literal, E'it''s ?' AS other, "
            . ':value::int AS n /* $fn$not_sql$fn$ */',
        )->willReturn($statement);
        $statement->expects(self::once())->method('bindValue')
            ->with(':value', 42, PDO::PARAM_INT);
        $statement->expects(self::once())->method('execute');
        $statement->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'literal' => ":not_a_param ? 'quoted' \\ slash",
            'other' => "it's ?",
            'n' => 42,
        ]);

        $row = (new PdoConnection($pdo))->queryOne(
            "SELECT \$fn$:not_a_param ? 'quoted' \\ slash\$fn$ AS literal, "
            . "\$\$it's ?\$\$ AS other, :value::int AS n /* \$fn\$not_sql\$fn\$ */",
            ['value' => 42],
        );

        self::assertSame(":not_a_param ? 'quoted' \\ slash", $row['literal']);
        self::assertSame("it's ?", $row['other']);
        self::assertSame(42, $row['n']);
    }

    public function testPdoPgsqlDoesNotNormalizeDollarTextInsideStringsOrComments(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createStub(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('pgsql');
        $sql = <<<'SQL'
SELECT E'escaped \' $fn$still_text$fn$' AS literal, "$fn$identifier$fn$" AS name /* outer $fn$text$fn$ /* nested */ */
SQL;
        $prepared = [];
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnCallback(
            static function (string $preparedSql) use (&$prepared, $statement): PDOStatement {
                $prepared[] = $preparedSql;

                return $statement;
            },
        );
        $statement->method('fetch')->willReturn(['literal' => 'text', 'name' => 'name']);
        $statement->method('fetchAll')->willReturn([]);

        $connection = new PdoConnection($pdo);
        self::assertSame('text', $connection->queryOne($sql, [])['literal']);
        self::assertSame([], $connection->queryAll('SELECT $fn$not_closed', []));
        self::assertSame([$sql, 'SELECT $fn$not_closed'], $prepared);
    }

    // ==================== UPSERT 自动时间戳与 created_at 语义 ====================

    public function testUpsertConflictDoesNotOverwriteCreatedAt(): void
    {
        $rowBefore = ComplexOrder::query()->where('id', 1)->row();
        $createdBefore = (int) $rowBefore['created_at'];
        self::assertGreaterThan(0, $createdBefore);

        // 显式 created_at 与自动填充并存时：冲突更新默认不覆盖 created_at
        ComplexOrder::upsert([
            'id' => 1,
            'user_id' => 1,
            'sku' => 'A-UPSERTED',
            'qty' => 99,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('11.00'),
            'happened_at' => new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
            'created_at' => $createdBefore + 100000, // 即使显式给了新值，也不许覆盖
            'updated_at' => $createdBefore + 200000,
        ]);

        $rowAfter = ComplexOrder::query()->where('id', 1)->row();
        self::assertSame('A-UPSERTED', $rowAfter['sku']);
        self::assertSame(99, (int) $rowAfter['qty']);
        self::assertSame($createdBefore, (int) $rowAfter['created_at'],
            '冲突更新默认不能覆盖 created_at（即使显式传了新值）');
        self::assertSame($createdBefore + 200000, (int) $rowAfter['updated_at'],
            '显式 updated_at 必须被尊重');
    }

    public function testUpsertAutoFillsTimestampsOnInsert(): void
    {
        $before = time();
        ComplexOrder::upsert([
            'id' => 100,
            'user_id' => 9,
            'sku' => 'NEW-UPSERT',
            'qty' => 1,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('1.00'),
            'happened_at' => new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
        ]);
        $after = time();

        $row = ComplexOrder::query()->where('id', 100)->row();
        self::assertNotNull($row, 'UPSERT 新行必须真实插入');
        $created = (int) $row['created_at'];
        self::assertGreaterThanOrEqual($before, $created, 'created_at 未显式提供时必须自动填充');
        self::assertLessThanOrEqual($after, $created);
    }

    public function testUpsertExplicitCreatedAtAllowedOnInsert(): void
    {
        // 显式 created_at：插入分支必须尊重调用方给定值
        ComplexOrder::upsert([
            'id' => 101,
            'user_id' => 9,
            'sku' => 'EXPLICIT-CREATED',
            'qty' => 1,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('1.00'),
            'happened_at' => new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
            'created_at' => 123456789,
        ]);
        $row = ComplexOrder::query()->where('id', 101)->row();
        self::assertSame(123456789, (int) $row['created_at'], '插入时显式 created_at 必须落库');
    }

    public function testUpsertIgnoreModeKeepsOriginalRow(): void
    {
        $before = ComplexOrder::query()->where('id', 1)->row();
        ComplexOrder::upsert([
            'id' => 1,
            'user_id' => 1,
            'sku' => 'SHOULD-NOT-CHANGE',
            'qty' => 777,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('77.00'),
            'happened_at' => new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
        ], []); // 冲突时忽略

        $after = ComplexOrder::query()->where('id', 1)->row();
        self::assertSame($before['sku'], $after['sku']);
        self::assertSame((int) $before['qty'], (int) $after['qty']);
        self::assertSame((int) $before['created_at'], (int) $after['created_at']);
    }

    public function testUpsertExplicitColumnsAlwaysRefreshUpdatedAt(): void
    {
        $before = ComplexOrder::query()->where('id', 1)->row();
        ComplexOrder::upsert([
            'id' => 1,
            'user_id' => 1,
            'sku' => 'EXPLICIT-UPDATE',
            'qty' => 10,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('12.00'),
            'happened_at' => new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
            'updated_at' => 123456789,
        ], ['qty']);
        $after = ComplexOrder::query()->where('id', 1)->row();
        self::assertSame('A', $after['sku'], '显式更新列表不能把 sku 带入 UPDATE');
        self::assertSame(10, (int) $after['qty']);
        self::assertSame(123456789, (int) $after['updated_at']);
    }

    public function testSqliteLocksRejectedOnEveryReadEntry(): void
    {
        foreach (['rows', 'row', 'value', 'get', 'first'] as $method) {
            try {
                ComplexOrder::query()->forUpdate()->{$method}();
                self::fail($method . '() 不能在 SQLite 静默执行 FOR UPDATE');
            } catch (LogicException $e) {
                self::assertStringContainsString('SQLite', $e->getMessage());
            }
        }
    }

    public function testConditionalDeleteInvalidatesLiveEntityAfterUpsertEvictsItFromLru(): void
    {
        $entity = ComplexOrder::find(['id' => 1]);
        self::assertNotNull($entity);
        ComplexOrder::upsert([
            'id' => 2,
            'user_id' => 2,
            'sku' => 'OTHER-UPSERT',
            'qty' => 3,
            'price' => \Pixiaoka\Orm\Cast\BcDecimal::fromString('20.00'),
            'happened_at' => new DateTimeImmutable('2026-03-02 00:00:00', new DateTimeZone('UTC')),
        ]);
        $survivor = ComplexOrder::find(['id' => 3]);
        self::assertNotSame($entity, ComplexOrder::find(['id' => 1]),
            '前置条件：UPSERT 已把原实体清出 LRU，而不是继续命中缓存');

        self::assertSame(1, ComplexOrder::query()->where('id', 1)->delete());
        self::assertTrue($entity->isNew(), '已清出 LRU 但仍被调用方持有的对象也必须精确失效');
        self::assertFalse($survivor->isNew(), '未删除主键对应的实体不能被误标删除');
        self::assertNotNull(ComplexOrder::find(['id' => 3]));
    }

    public function testMysqlDeleteNormalizesBoolPrimaryKeyBeforeInvalidatingCache(): void
    {
        $connection = new FakeDialectConnection('mysql');
        $connection->selectResults = [['active' => 1]];
        $gateway = new EntityGateway($connection);
        Session::use($gateway);

        $entity = new BoolKeyEntity();
        $entity->active = true;
        $entity->value = 'old';
        $entity->save();
        self::assertSame($entity, BoolKeyEntity::find(['active' => true]));
        self::assertSame(1, BoolKeyEntity::query()->where('active', true)->delete());
        self::assertTrue($entity->isNew());
        self::assertStringStartsWith('SELECT `active` FROM `bool_key` WHERE `active` = :q0', $connection->queries[0]['sql']);
        self::assertStringStartsWith('DELETE FROM `bool_key` WHERE `active` = :q0', $connection->lastSql);
    }

    public function testMysqlConditionalDeletePreselectsOnlyTargetModelIdentities(): void
    {
        $connection = new RecordingIdentityConnection();
        $gateway = new EntityGateway($connection);
        Session::use($gateway);

        $order = ComplexOrder::find(['id' => 1]);
        $tag = ComplexTag::find(['order_id' => 1, 'label' => 'new']);
        self::assertNotNull($order);
        self::assertNotNull($tag);
        $cache = (new \ReflectionProperty(EntityGateway::class, 'cache'))->getValue($gateway);
        $cache->set($tag, ['order_id' => 1, 'label' => 'new']);
        self::assertSame(1, ComplexOrder::query()->where('id', 1)->delete());
        self::assertCount(1, $connection->selects);
        $preselect = $connection->selects[0];
        self::assertSame(
            'SELECT `id` FROM `complex_order` WHERE `id` = :q0 AND ((`id` = :q1))',
            $preselect['sql'],
        );
        self::assertSame(['q0' => 1, 'q1' => 1], $preselect['params']);
        self::assertTrue($order->isNew());
        self::assertFalse($tag->isNew(), '其他模型的复合身份不能被误标为已删除');
    }

    public function testDeleteReturningNormalizesBoolPrimaryKey(): void
    {
        $connection = new FakeDialectConnection('pgsql');
        $connection->selectResults = [['active' => 1]];
        $gateway = new EntityGateway($connection);
        Session::use($gateway);

        $entity = new BoolKeyEntity();
        $entity->active = true;
        $entity->value = 'old';
        $entity->save();
        self::assertSame(1, BoolKeyEntity::query()->where('active', true)->delete());
        self::assertTrue($entity->isNew());
        self::assertStringEndsWith(' RETURNING "active"', $connection->queries[0]['sql']);
    }

    public function testUnionSqlForNestedBranchesOnMysqlAndPostgres(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            Session::use(new EntityGateway(new FakeDialectConnection($driver)));
            $inner = ComplexOrder::query()->selectAs('sku', 'name')
                ->unionAll(ComplexOrder::query()->selectAs('sku', 'name'));
            $sql = ComplexOrder::query()->selectAs('sku', 'name')
                ->unionAll($inner)->build()['sql'];
            self::assertStringContainsString('UNION ALL SELECT * FROM (SELECT', $sql);
            self::assertStringContainsString('orm_union_branch', $sql);
        }
    }

    public function testNestedUnionTopPaginationSqlOnMysqlAndPostgres(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            $quote = $driver === 'mysql' ? '`' : '"';
            Session::use(new EntityGateway(new FakeDialectConnection($driver)));
            $inner = ComplexOrder::query()->selectAs('sku', 'name')
                ->unionAll(ComplexOrder::query()->selectAs('sku', 'name'))
                ->orderBy('name', 'DESC')->limit(2)->offset(1);
            $built = ComplexOrder::query()->selectAs('sku', 'name')
                ->unionAll($inner)->build();

            self::assertStringContainsString('UNION ALL SELECT * FROM (SELECT * FROM (SELECT', $built['sql'], $driver);
            self::assertStringContainsString('ORDER BY ' . $quote . 'name' . $quote . ' DESC LIMIT 2 OFFSET 1) AS ' . $quote . 'orm_union_branch' . $quote, $built['sql'], $driver);
            $outerAlias = $driver === 'mysql' ? 'orm_union_result' : 'orm_union_branch';
            self::assertStringEndsWith(' AS ' . $quote . $outerAlias . $quote, $built['sql'], $driver);
            self::assertSame([], $built['params'], $driver);
        }
    }

    // ==================== SQLite 行锁拒绝（执行期，不只 build） ====================

    public function testSqliteRowLockRejectedAtExecution(): void
    {
        // build 只是拼 SQL，执行期必须被 driver 检查拦下
        self::assertStringEndsWith(' FOR UPDATE', ComplexOrder::query()->forUpdate()->build()['sql']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SQLite');
        ComplexOrder::query()->forShare()->rows();
    }
}

final class RecordingIdentityConnection implements \Pixiaoka\Orm\Gateway\Connection
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $selects = [];

    public function execute(string $sql, array $params): int
    {
        return 1;
    }

    public function queryOne(string $sql, array $params): ?array
    {
        if (str_contains($sql, 'complex_order')) {
            return [
                'id' => 1, 'user_id' => 1, 'sku' => 'A', 'qty' => 2, 'price' => '10.50',
                'happened_at' => '2026-01-01 00:00:00', 'created_at' => 1, 'updated_at' => 1,
            ];
        }

        return ['order_id' => 1, 'label' => 'new'];
    }

    public function queryAll(string $sql, array $params): array
    {
        $this->selects[] = ['sql' => $sql, 'params' => $params];

        return [['id' => 1]];
    }

    public function lastInsertId(): string
    {
        return '1';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function supportsReturning(): bool
    {
        return false;
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

final class BoolKeyEntity extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public bool $active;
    #[Column]
    public string $value;

    public static function table(): string
    {
        return 'bool_key';
    }
}
