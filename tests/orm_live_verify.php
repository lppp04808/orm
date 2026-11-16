<?php

// 真实数据库实连验证脚本（独立 Docker / CI 测试库，或明确提供的 MySQL/PostgreSQL DSN）
//
// 目的：
//   1. 验证 ORM 内核生成的 SQL 在真实数据库上正确可执行
//   2. 验证数据库连接是否正常
//
// 安全铁律（生产库）：
//   - 每次运行使用 orm_verify_item_<随机后缀> / orm_verify_tag_<随机后缀> 两张表
//   - 表名登记到 $ownedTables，清理时只 DROP 本次自己创建的表（tag 先、item 后）
//   - 绝不 DROP 固定名或任何未登记表，避免误删生产同名表
//   - 连通性只测 SELECT 1，不读写任何其他表
//
// 运行：
//   "/d/php/8.3.3/php" -d extension=pdo_sqlite -d extension=pdo_pgsql -d extension=pdo_mysql \
//       tests/orm_live_verify.php
//
// 两个库都连不上时：自动跑 SQLite 全量场景（脚本内建表），证明脚本断言逻辑本身正确。

declare(strict_types=1);

// 自动定位 autoload：库内 vendor（composer install 后）→ 兜底 bootstrap 手工注册
$libAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($libAutoload)) {
    require $libAutoload;
} else {
    require __DIR__ . '/bootstrap.php';
}

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Query\JoinClause;
use Pixiaoka\Orm\Session;

// ----------------------------------------------------------------------------
// .env 解析（逐行 KEY=VALUE，跳过注释与空行，剥 CRLF）
// ----------------------------------------------------------------------------

/** @return array<string, string> */
function parseEnv(string $path): array
{
    $vars = [];
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
    if ($lines === false) {
        return $vars;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key !== '') {
            $vars[$key] = $value;
        }
    }

    return $vars;
}

// ----------------------------------------------------------------------------
// SQL 记录装饰器（包裹 PdoConnection，记录全部经过内核的 SQL）
// ----------------------------------------------------------------------------

final class RecordingConnection implements Connection, \Pixiaoka\Orm\Gateway\DriverAwareConnection
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $log = [];

    public function __construct(private readonly Connection $inner)
    {
    }

    /** @return list<string> 去重后的 SQL 语句清单（保序） */
    public function sqlList(): array
    {
        $seen = [];
        foreach ($this->log as $entry) {
            $seen[$entry['sql']] = true;
        }

        return array_keys($seen);
    }

    /** 打印自 $since 起新记录的 SQL + 参数 */
    public function printSince(int $since): void
    {
        for ($i = $since; $i < count($this->log); $i++) {
            echo '  SQL> ' . $this->log[$i]['sql'] . "\n";
            if ($this->log[$i]['params'] !== []) {
                echo '  PARAMS> ' . json_encode($this->log[$i]['params'], JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }

    public function execute(string $sql, array $params): int
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        return $this->inner->execute($sql, $params);
    }

    public function queryOne(string $sql, array $params): ?array
    {
        // SELECT ... / INSERT ... RETURNING 都可能走这里
        $this->log[] = ['sql' => $sql, 'params' => $params];

        return $this->inner->queryOne($sql, $params);
    }

    public function queryAll(string $sql, array $params): array
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

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

    public function driver(): string
    {
        return $this->inner->driver();
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

// ----------------------------------------------------------------------------
// 测试模型（表名由静态 override 注入：每次运行使用 orm_verify_*_<随机后缀>）
// ----------------------------------------------------------------------------

final class VerifyItem extends Entity
{
    public static string $tableName = 'orm_verify_item';

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
    public bool $flag;

    #[Column]
    #[Temporal]
    public DateTimeImmutable $happened_at;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return self::$tableName;
    }
}

final class VerifyTag extends Entity
{
    public static string $tableName = 'orm_verify_tag';

    #[Column]
    #[PrimaryKey(order: 1)]
    public int $item_id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public string $label;

    public static function table(): string
    {
        return self::$tableName;
    }
}

// ----------------------------------------------------------------------------
// 验证结果收集
// ----------------------------------------------------------------------------

final class Check
{
    public int $passed = 0;
    public int $failed = 0;
    /** @var list<string> */
    public array $failures = [];

    public function ok(string $name): void
    {
        $this->passed++;
        echo "  [PASS] $name\n";
    }

    public function no(string $name, string $reason): void
    {
        $this->failed++;
        $this->failures[] = "$name: $reason";
        echo "  [FAIL] $name\n         $reason\n";
    }

    public function assert(bool $cond, string $name, string $detail = ''): void
    {
        if ($cond) {
            $this->ok($name);
        } else {
            $this->no($name, $detail === '' ? '断言为 false' : $detail);
        }
    }
}

// ----------------------------------------------------------------------------
// 建表 / 清理（按方言；随机后缀表名 + 拥有权登记，只清理自己的表）
// ----------------------------------------------------------------------------

/**
 * 本次运行拥有的表（登记后才允许 DROP）。
 *
 * @var list<string>
 */
$ownedTables = [];

function createTables(PDO $pdo, string $driver): void
{
    global $ownedTables;
    $item = VerifyItem::table();
    $tag = VerifyTag::table();

    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE {$item} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sku VARCHAR(64) NOT NULL,
            qty INT NOT NULL,
            price DECIMAL(12,2) NOT NULL,
            flag TINYINT(1) NOT NULL,
            happened_at DATETIME NOT NULL,
            created_at INT NOT NULL,
            updated_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ownedTables[] = $item;
        $pdo->exec("CREATE TABLE {$tag} (
            item_id INT NOT NULL,
            label VARCHAR(64) NOT NULL,
            PRIMARY KEY (item_id, label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ownedTables[] = $tag;
    } elseif ($driver === 'sqlite') {
        // 与 tests/Orm 既有套件同构：AUTOINCREMENT + price TEXT 保精度
        $pdo->exec("CREATE TABLE {$item} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            sku TEXT NOT NULL,
            qty INTEGER NOT NULL,
            price TEXT NOT NULL,
            flag BOOLEAN NOT NULL,
            happened_at TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL DEFAULT 0
        )");
        $ownedTables[] = $item;
        $pdo->exec("CREATE TABLE {$tag} (
            item_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            PRIMARY KEY (item_id, label)
        )");
        $ownedTables[] = $tag;
    } else {
        // pgsql
        $pdo->exec("CREATE TABLE {$item} (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            sku VARCHAR(64) NOT NULL,
            qty INT NOT NULL,
            price DECIMAL(12,2) NOT NULL,
            flag BOOLEAN NOT NULL,
            happened_at TIMESTAMP NOT NULL,
            created_at INT NOT NULL,
            updated_at INT NOT NULL DEFAULT 0
        )");
        $ownedTables[] = $item;
        $pdo->exec("CREATE TABLE {$tag} (
            item_id INT NOT NULL,
            label VARCHAR(64) NOT NULL,
            PRIMARY KEY (item_id, label)
        )");
        $ownedTables[] = $tag;
    }
}

/** 只清理本次登记过的表：tag 先（外键/依赖方向），item 后。 */
function dropOwnedTables(PDO $pdo): void
{
    global $ownedTables;
    $item = VerifyItem::table();
    $tag = VerifyTag::table();
    // drop 顺序：tag 后 item（先删子表/标签表，再删主表）
    foreach ([$tag, $item] as $table) {
        if (in_array($table, $ownedTables, true)) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
    $ownedTables = [];
}

// ----------------------------------------------------------------------------
// 场景 b~i（驱动无关；mysql 走 lastInsertId/拒绝批量回填，pgsql/sqlite 走 RETURNING）
// ----------------------------------------------------------------------------

function runScenarios(string $driver, PDO $pdo, RecordingConnection $rec, Check $check): void
{
    // b. 代理键 insert：id 回填
    $item = new VerifyItem();
    $item->user_id = 7;
    $item->sku = 'SKU-001';
    $item->qty = 3;
    $item->price = BcDecimal::fromString('19.99');
    $item->flag = true;
    $item->happened_at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    try {
        $mark = count($rec->log);
        $item->save();
        $rec->printSince($mark);
        $path = $driver === 'mysql' ? 'lastInsertId 路径' : 'INSERT...RETURNING 路径';
        $check->assert($item->id > 0, "b. 代理键 insert（{$path}）id 回填 > 0", 'id=' . var_export($item->id ?? null, true));
    } catch (Throwable $e) {
        $check->no('b. 代理键 insert', $e->getMessage());
    }

    // c. find 按主键查回（query 直读库，绕开行缓存）
    try {
        $found = VerifyItem::query()->where('id', $item->id)->first();
        $same = $found !== null
            && $found->id === $item->id
            && $found->user_id === 7
            && $found->sku === 'SKU-001'
            && $found->qty === 3
            && (string) $found->price === '19.99'  // 字符串比较保精度
            && $found->flag === true                // 布尔
            && $found->happened_at->format('Y-m-d H:i:s') === '2026-01-01 00:00:00'; // 时间列 UTC 往返
        $detail = $found === null ? 'first() 返回 null' : json_encode($found->toArray(), JSON_UNESCAPED_UNICODE);
        $check->assert($same, 'c. 按主键查回（字段一致，price 字符串比、flag 布尔、时间 UTC 往返）', $detail);
    } catch (Throwable $e) {
        $check->no('c. find 按主键查回', $e->getMessage());
    }

    // d. update 脏跟踪：只改 qty，SET 子句只含 qty 和 updated_at
    try {
        $found = VerifyItem::query()->where('id', $item->id)->first();
        if ($found === null) {
            $check->no('d. update 脏跟踪', '前置：first() 拿不到实体');
        } else {
            $found->qty = 5;
            $mark = count($rec->log);
            $found->save();
            $updateSql = '';
            foreach (array_slice($rec->log, $mark) as $entry) {
                if (str_starts_with($entry['sql'], 'UPDATE')) {
                    $updateSql = $entry['sql'];
                }
            }
            $rec->printSince($mark);
            if ($updateSql === '') {
                $check->no('d. update 脏跟踪', '没有捕获到 UPDATE 语句');
            } else {
                preg_match('/\bSET\b(.*?)\bWHERE\b/s', $updateSql, $m);
                $setClause = trim($m[1] ?? '');
                $onlyQty = str_contains($setClause, 'qty')
                    && str_contains($setClause, 'updated_at')
                    && !str_contains($setClause, 'sku')
                    && !str_contains($setClause, 'user_id')
                    && !str_contains($setClause, 'price')
                    && !str_contains($setClause, 'flag')
                    && !str_contains($setClause, 'happened_at')
                    && !str_contains($setClause, 'created_at');
                $check->assert($onlyQty, 'd. update 脏跟踪（SET 只含 qty 和 updated_at）', "SET 子句: $setClause");

                $reread = VerifyItem::query()->where('id', $item->id)->first();
                $check->assert($reread !== null && $reread->qty === 5, 'd2. 更新后直读库 qty=5', $reread === null ? 'null' : 'qty=' . $reread->qty);
            }
        }
    } catch (Throwable $e) {
        $check->no('d. update 脏跟踪', $e->getMessage());
    }

    // e. 复合主键表：insert -> find -> delete 全链路
    try {
        $tag = new VerifyTag();
        $tag->item_id = $item->id;
        $tag->label = 'red';
        $mark = count($rec->log);
        $tag->save();
        $rec->printSince($mark);

        $tagFound = VerifyTag::query()->where('item_id', $item->id)->where('label', 'red')->first();
        $foundOk = $tagFound !== null && $tagFound->item_id === $item->id && $tagFound->label === 'red';
        $check->assert($foundOk, 'e1. 复合主键 insert + 查回', $tagFound === null ? 'first() 返回 null' : 'label=' . $tagFound->label);

        if ($tagFound !== null) {
            $mark = count($rec->log);
            $tagFound->delete();
            $rec->printSince($mark);
            $after = VerifyTag::query()->where('item_id', $item->id)->where('label', 'red')->first();
            $check->assert($after === null, 'e2. 复合主键 delete（删后查不到）', '删除后仍能查到');
        }
    } catch (Throwable $e) {
        $check->no('e. 复合主键全链路', $e->getMessage());
    }

    // f. 查询构建器
    try {
        VerifyItem::insertAll([
            ['user_id' => 8, 'sku' => 'QB-1', 'qty' => 1, 'price' => '1.10', 'flag' => 0, 'happened_at' => '2026-01-02 00:00:00'],
            ['user_id' => 8, 'sku' => 'QB-2', 'qty' => 2, 'price' => '2.20', 'flag' => 1, 'happened_at' => '2026-01-03 00:00:00'],
            ['user_id' => 8, 'sku' => 'QB-3', 'qty' => 4, 'price' => '3.30', 'flag' => 0, 'happened_at' => '2026-01-04 00:00:00'],
            ['user_id' => 9, 'sku' => 'QB-4', 'qty' => 9, 'price' => '4.40', 'flag' => 1, 'happened_at' => '2026-01-05 00:00:00'],
        ]);

        // f1. where + BETWEEN + orGroup（嵌套一层）+ orderBy + limit
        // 语义：user_id=8 AND qty BETWEEN 1..5 AND (sku='QB-1' OR (sku='QB-3'))
        $mark = count($rec->log);
        $rows = VerifyItem::query()
            ->where('user_id', 8)
            ->whereOp('qty', 'BETWEEN', [1, 5])
            ->orGroup(fn ($g) => $g
                ->where('sku', 'QB-1')
                ->orGroup(fn ($h) => $h->where('sku', 'QB-3')))
            ->orderBy('qty', 'DESC')
            ->limit(10)
            ->offset(0)
            ->get();
        $rec->printSince($mark);
        $skus = array_map(fn ($r) => $r->sku, $rows); // qty DESC: QB-3(4) 在前
        $check->assert($skus === ['QB-3', 'QB-1'], 'f1. where+BETWEEN+orGroup(嵌套)+orderBy+limit', '返回: ' . implode(',', $skus));

        // f2. first() SQL 含 LIMIT 1
        $mark = count($rec->log);
        $first = VerifyItem::query()->where('user_id', 8)->orderBy('qty')->first();
        $firstSql = '';
        foreach (array_slice($rec->log, $mark) as $entry) {
            if (str_starts_with($entry['sql'], 'SELECT')) {
                $firstSql = $entry['sql'];
            }
        }
        $rec->printSince($mark);
        $check->assert(str_contains($firstSql, 'LIMIT 1'), 'f2. first() 的 SQL 含 LIMIT 1', $firstSql);
        $check->assert($first !== null && $first->sku === 'QB-1', 'f3. first() 取到排序首行', $first?->sku ?? 'null');

        // f4. 带 limit 的 count() 不受 limit 影响
        $q = VerifyItem::query()->where('user_id', 8)->orderBy('id')->limit(2)->offset(0);
        $mark = count($rec->log);
        $cnt = $q->count();
        $rec->printSince($mark);
        $check->assert($cnt === 3, 'f4. 带 limit(2) 的 count() 不受 limit 影响（=3）', 'count=' . $cnt);

        // f5. 同一查询 get() 仍受 limit 约束
        $limited = $q->get();
        $check->assert(count($limited) === 2, 'f5. 同一查询 get() 仍受 limit 约束（=2）', 'rows=' . count($limited));

        // f6. offset 真实生效：user 8 按 qty 升序，跳 1 取 2 → QB-2, QB-3
        $mark = count($rec->log);
        $paged = VerifyItem::query()->where('user_id', 8)->orderBy('qty')->limit(2)->offset(1)->get();
        $rec->printSince($mark);
        $pagedSkus = array_map(fn ($r) => $r->sku, $paged);
        $check->assert($pagedSkus === ['QB-2', 'QB-3'], 'f6. limit+offset 分页（跳 1 取 2）', '返回: ' . implode(',', $pagedSkus));
    } catch (Throwable $e) {
        $check->no('f. 查询构建器', $e->getMessage());
    }

    // g. batchInsert
    try {
        // g1. 3 行数组：两个库都应成功
        VerifyItem::insertAll([
            ['user_id' => 20, 'sku' => 'BI-A', 'qty' => 1, 'price' => '5.00', 'flag' => 1, 'happened_at' => '2026-01-06 00:00:00'],
            ['user_id' => 21, 'sku' => 'BI-B', 'qty' => 2, 'price' => '6.00', 'flag' => 0, 'happened_at' => '2026-01-07 00:00:00'],
            ['user_id' => 22, 'sku' => 'BI-C', 'qty' => 3, 'price' => '7.00', 'flag' => 1, 'happened_at' => '2026-01-08 00:00:00'],
        ]);
        $cnt = VerifyItem::query()->whereOp('user_id', '>=', 20)->whereOp('user_id', '<=', 22)->count();
        $check->assert($cnt === 3, 'g1. batchInsert 3 行数组', 'count=' . $cnt);
    } catch (Throwable $e) {
        $check->no('g1. batchInsert 数组行', $e->getMessage());
    }

    try {
        // g2. 3 行实体
        $entities = [];
        foreach ([31, 32, 33] as $uid) {
            $e = new VerifyItem();
            $e->user_id = $uid;
            $e->sku = 'BI-E' . $uid;
            $e->qty = 1;
            $e->price = BcDecimal::fromString('8.00');
            $e->flag = false;
            $e->happened_at = new DateTimeImmutable('2026-01-09 00:00:00', new DateTimeZone('UTC'));
            $entities[] = $e;
        }
        $mark = count($rec->log);
        VerifyItem::insertAll($entities);
        $rec->printSince($mark);

        if ($driver === 'mysql') {
            // mysql 不支持 RETURNING，多实体批量回填应被拒绝——预期行为。
            // 走到这里说明没抛异常，是问题。
            $check->no('g2. mysql 多实体 batchInsert 应抛 IdentityException 但未抛出', '未抛出异常');
        } else {
            $ids = array_map(fn ($e) => $e->id, $entities);
            $check->assert(count(array_unique($ids)) === 3, 'g2. batchInsert 3 实体（RETURNING 回填）ID 互不相同', 'ids=' . implode(',', $ids));
            sort($ids);
            $consecutive = ($ids[2] - $ids[1] === 1) && ($ids[1] - $ids[0] === 1);
            $check->assert($consecutive, 'g3. RETURNING 回填的 3 个 ID 连续', 'ids=' . implode(',', $ids));
            $inDb = VerifyItem::query()->whereOp('user_id', '>=', 31)->whereOp('user_id', '<=', 33)->count();
            $check->assert($inDb === 3, 'g4. 实体批量入库可查回（=3）', 'count=' . $inDb);
        }
    } catch (IdentityException $e) {
        if ($driver === 'mysql') {
            $check->assert(
                str_contains($e->getMessage(), 'RETURNING'),
                'g2. mysql 多实体 batchInsert 抛 IdentityException（预期行为）',
                $e->getMessage(),
            );
        } else {
            $check->no('g2. batchInsert 实体', $e->getMessage());
        }
    } catch (Throwable $e) {
        $check->no('g2. batchInsert 实体', $e->getMessage());
    }

    // h. 事务回滚
    try {
        $marker = 'TXN-ROLLBACK-' . random_int(100000, 999999);
        try {
            VerifyItem::transaction(function () use ($marker): void {
                $t = new VerifyItem();
                $t->user_id = 99;
                $t->sku = $marker;
                $t->qty = 1;
                $t->price = BcDecimal::fromString('1.00');
                $t->flag = true;
                $t->happened_at = new DateTimeImmutable('2026-01-10 00:00:00', new DateTimeZone('UTC'));
                $t->save();
                throw new RuntimeException('事务内故意失败');
            });
            $check->no('h. 事务回滚', '事务没有抛出异常');
        } catch (RuntimeException $e) {
            $check->assert($e->getMessage() === '事务内故意失败', 'h1. 事务异常向上传播', $e->getMessage());
        }
        $leak = VerifyItem::query()->where('sku', $marker)->first();
        $check->assert($leak === null, 'h2. 回滚后行未入库（查不到）', '仍能查到 sku=' . $marker);
    } catch (Throwable $e) {
        $check->no('h. 事务回滚', $e->getMessage());
    }

    // i. 删除后 save 重新插入（同对象复活）
    // 说明：代理键实体删除后，typed 属性 id 仍持有旧值。内核 insert() 对生成列
    // 有“调用方不能提供”的防串号保护，复活前应 unset($e->id) 让生成列回到未初始化态。
    // （既有 OrmKernelTest::testDeleteThenSaveReinserts 用自然键模型，无此问题。）
    try {
        $zombie = new VerifyItem();
        $zombie->user_id = 100;
        $zombie->sku = 'REVIVE';
        $zombie->qty = 1;
        $zombie->price = BcDecimal::fromString('2.50');
        $zombie->flag = true;
        $zombie->happened_at = new DateTimeImmutable('2026-01-11 00:00:00', new DateTimeZone('UTC'));
        $zombie->save();
        $oldId = $zombie->id;

        $zombie->delete();
        $gone = VerifyItem::query()->where('id', $oldId)->first();
        $check->assert($gone === null, 'i1. delete 后查不到', '仍能查到 id=' . $oldId);

        $zombie->qty = 8;   // 复活时顺带改值
        unset($zombie->id); // 生成列回到未初始化，交还数据库生成
        $mark = count($rec->log);
        $zombie->save();
        $rec->printSince($mark);
        $check->assert($zombie->id > 0 && $zombie->id !== $oldId, 'i2. 复活后重新 insert 拿到新 id', 'old=' . $oldId . ' new=' . var_export($zombie->id ?? null, true));

        $revived = VerifyItem::query()->where('id', $zombie->id)->first();
        $check->assert($revived !== null && $revived->qty === 8 && $revived->sku === 'REVIVE', 'i3. 复活行直读库可查回且值正确', $revived === null ? 'null' : json_encode($revived->toArray(), JSON_UNESCAPED_UNICODE));

        // i4. 不 unset 生成列就复活：内核应拒绝（防串号保护）
        $stubborn = new VerifyItem();
        $stubborn->user_id = 101;
        $stubborn->sku = 'STUBBORN';
        $stubborn->qty = 1;
        $stubborn->price = BcDecimal::fromString('3.50');
        $stubborn->flag = false;
        $stubborn->happened_at = new DateTimeImmutable('2026-01-12 00:00:00', new DateTimeZone('UTC'));
        $stubborn->save();
        $stubborn->delete();
        $stubborn->qty = 2;
        try {
            $stubborn->save();
            $check->no('i4. 携带旧生成列复活应被内核拒绝', '内核没有拒绝，id 被复用或旧 id 插入');
        } catch (IdentityException $e) {
            $check->assert(
                str_contains($e->getMessage(), '生成列由数据库填写'),
                'i4. 携带旧生成列复活被内核拒绝（预期行为）',
                $e->getMessage(),
            );
        }
    } catch (Throwable $e) {
        $check->no('i. 删除后 save 复活', $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// 场景 j~q：复杂查询真实执行（JOIN / 聚合 / 子查询 / 派生表 / UNION / raw / UPSERT /
//           条件写 / 行锁）。独立数据集 user_id=201/202，避免与基础场景互相影响。
// ----------------------------------------------------------------------------

function runComplexScenarios(string $driver, PDO $pdo, RecordingConnection $rec, Check $check): void
{
    // 数据集：CQ-A(201,2) CQ-B(201,3) CQ-C(202,4) CQ-D(202,8)
    // 标签：A=paid,new; C=paid; D=void —— B 无标签用于 LEFT JOIN unmatched
    try {
        VerifyItem::insertAll([
            ['user_id' => 201, 'sku' => 'CQ-A', 'qty' => 2, 'price' => '10.50', 'flag' => 1, 'happened_at' => '2026-02-01 00:00:00'],
            ['user_id' => 201, 'sku' => 'CQ-B', 'qty' => 3, 'price' => '20.00', 'flag' => 0, 'happened_at' => '2026-02-02 00:00:00'],
            ['user_id' => 202, 'sku' => 'CQ-C', 'qty' => 4, 'price' => '30.00', 'flag' => 1, 'happened_at' => '2026-02-03 00:00:00'],
            ['user_id' => 202, 'sku' => 'CQ-D', 'qty' => 8, 'price' => '40.00', 'flag' => 0, 'happened_at' => '2026-02-04 00:00:00'],
        ]);
        $idOf = [];
        foreach (VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)->rows() as $row) {
            $idOf[$row['sku']] = (int) $row['id'];
        }
        VerifyTag::insertAll([
            ['item_id' => $idOf['CQ-A'], 'label' => 'paid'],
            ['item_id' => $idOf['CQ-A'], 'label' => 'new'],
            ['item_id' => $idOf['CQ-C'], 'label' => 'paid'],
            ['item_id' => $idOf['CQ-D'], 'label' => 'void'],
        ]);
        $check->ok('j0. 复杂查询数据集就绪（4 订单 + 4 标签）');
    } catch (Throwable $e) {
        $check->no('j0. 复杂查询数据集', $e->getMessage());

        return;
    }

    // j1. LEFT JOIN（含 unmatched 分支）与 INNER JOIN 对照
    try {
        $rows = VerifyItem::query()
            ->alias('o')
            ->selectAs('o.sku', 'order_sku')
            ->selectAs('t.label', 'tag_label')
            ->leftJoin(VerifyTag::table(), 't', fn (JoinClause $join) => $join->on('o.id', '=', 't.item_id'))
            ->where('o.sku', 'CQ-B') // B 无标签
            ->rows();
        $ok = count($rows) === 1 && $rows[0]['order_sku'] === 'CQ-B' && $rows[0]['tag_label'] === null;
        $check->assert($ok, 'j1. LEFT JOIN unmatched 保留基表行且 JOIN 列为 NULL', json_encode($rows, JSON_UNESCAPED_UNICODE));

        $innerCount = VerifyItem::query()
            ->alias('o')
            ->join(VerifyTag::table(), 't', fn (JoinClause $join) => $join->on('o.id', '=', 't.item_id'))
            ->where('o.sku', 'CQ-B')
            ->count();
        $check->assert($innerCount === 0, 'j2. 同条件 INNER JOIN 返回 0 行', 'count=' . $innerCount);
    } catch (Throwable $e) {
        $check->no('j1/j2. LEFT/INNER JOIN', $e->getMessage());
    }

    // k. 聚合 GROUP BY / HAVING（两组真实执行）
    try {
        $mark = count($rec->log);
        $rows = VerifyItem::query()
            ->select('user_id')
            ->aggregate('COUNT', '*', 'orders')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->whereOp('user_id', '>=', 201)
            ->whereOp('user_id', '<=', 202)
            ->groupBy('user_id')
            ->having('SUM(qty)', '>', 5)
            ->rows();
        $rec->printSince($mark);
        $ok = count($rows) === 1 && (int) $rows[0]['user_id'] === 202 && (int) $rows[0]['total_qty'] === 12;
        $check->assert($ok, 'k1. GROUP BY + HAVING SUM(qty)>5 只留 user 202（sum=12）', json_encode($rows, JSON_UNESCAPED_UNICODE));

        $all = VerifyItem::query()
            ->select('user_id')
            ->aggregate('SUM', 'qty', 'total_qty')
            ->whereOp('user_id', '>=', 201)
            ->whereOp('user_id', '<=', 202)
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->rows();
        $ok = count($all) === 2
            && (int) $all[0]['total_qty'] === 5 && (int) $all[1]['total_qty'] === 12;
        $check->assert($ok, 'k2. 两组聚合值正确（201→5, 202→12）', json_encode($all, JSON_UNESCAPED_UNICODE));

        $scalar = VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)
            ->aggregate('SUM', 'qty')->value();
        $check->assert((int) $scalar === 17, 'k3. SUM(qty) 标量值=17', 'value=' . var_export($scalar, true));
    } catch (Throwable $e) {
        $check->no('k. 聚合 GROUP/HAVING', $e->getMessage());
    }

    // l. IN 子查询 / 标量子查询 / EXISTS / NOT EXISTS
    try {
        $paidItems = VerifyTag::query()->select('item_id')->where('label', 'paid');
        $rows = VerifyItem::query()->whereIn('id', $paidItems)->orderBy('id')->rows();
        $skus = array_column($rows, 'sku');
        $check->assert($skus === ['CQ-A', 'CQ-C'], 'l1. IN(子查询) 查回 paid 订单', implode(',', $skus));

        $maxQty = VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)->aggregate('MAX', 'qty');
        $top = VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)
            ->whereOp('qty', '=', $maxQty)->row();
        $check->assert($top !== null && $top['sku'] === 'CQ-D', 'l2. 标量子查询 qty=(SELECT MAX(qty)) 取到 CQ-D', $top['sku'] ?? 'null');

        $exists = VerifyTag::query()->select('item_id')
            ->whereRaw(VerifyTag::table() . '.item_id = ' . VerifyItem::table() . '.id')
            ->where('label', 'paid');
        $hasCount = VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)->whereExists($exists)->count();
        $hasNotCount = VerifyItem::query()->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)->whereNotExists($exists)->count();
        $check->assert($hasCount === 2 && $hasNotCount === 2, 'l3. EXISTS=2 / NOT EXISTS=2', "exists={$hasCount} notExists={$hasNotCount}");
    } catch (Throwable $e) {
        $check->no('l. 子查询', $e->getMessage());
    }

    // m. 派生表 + UNION（首/后续分支局部分页 + 顶层排序分页）
    try {
        $top = VerifyItem::query()->selectAs('user_id', 'uid')->selectAs('qty', 'amount')
            ->whereOp('user_id', '>=', 201)->whereOp('user_id', '<=', 202)
            ->whereOp('qty', '>=', 3);
        $rows = VerifyItem::query()->select('uid')->from($top, 'ranked')
            ->whereOp('amount', '>', 5)->rows();
        $uids = array_map('intval', array_column($rows, 'uid'));
        $check->assert($uids === [202], 'm1. 派生表外层过滤 amount>5 只留 user 202', implode(',', $uids));

        $first = VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 201)->orderBy('id', 'DESC')->limit(1); // B
        $second = VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 202)->orderBy('id')->limit(1); // C
        $union = $first->unionAll($second)->orderBy('name');
        $mark = count($rec->log);
        $rows = $union->rows();
        $rec->printSince($mark);
        $check->assert(array_column($rows, 'name') === ['CQ-B', 'CQ-C'], 'm2. UNION 分支局部分页（首分支 DESC limit1=B，次分支 ASC limit1=C）', implode(',', array_column($rows, 'name')));

        // 顶层排序+分页：全量 4 行 DESC → D C B A，offset1 limit2 → C B
        $u2 = VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 201)
            ->unionAll(VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 202));
        $rows = $u2->orderBy('name', 'DESC')->limit(2)->offset(1)->rows();
        $check->assert(array_column($rows, 'name') === ['CQ-C', 'CQ-B'], 'm3. UNION 顶层 orderBy DESC + limit2 offset1', implode(',', array_column($rows, 'name')));

        // 嵌套 UNION 作派生表
        $inner = VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 201)
            ->unionAll(VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 202));
        $rows = VerifyItem::query()->select('name')->from($inner, 'u')->whereOp('name', '>=', 'CQ-C')->orderBy('name')->rows();
        $check->assert(array_column($rows, 'name') === ['CQ-C', 'CQ-D'], 'm4. 嵌套 UNION 派生表外层过滤排序', implode(',', array_column($rows, 'name')));

        $inner = VerifyItem::query()->selectAs('sku', 'name')->whereIn('sku', ['CQ-B', 'CQ-C'])
            ->union(VerifyItem::query()->selectAs('sku', 'name')->where('sku', 'CQ-B'))
            ->orderBy('name', 'DESC')->limit(1); // 分支内先去重，再取 CQ-C
        $outer = VerifyItem::query()->selectAs('sku', 'name')->where('sku', 'CQ-A')
            ->unionAll($inner);
        $rows = $outer->rows();
        $check->assert(array_column($rows, 'name') === ['CQ-A', 'CQ-C'], 'm5. 嵌套 UNION 后续分支保留去重和局部分页', implode(',', array_column($rows, 'name')));

        $inner = VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 201)
            ->unionAll(VerifyItem::query()->selectAs('sku', 'name')->where('user_id', 202));
        $outer = VerifyItem::query()->selectAs('sku', 'name')->where('sku', 'CQ-A')
            ->union($inner);
        $rows = $outer->rows();
        $check->assert(count($rows) === 4 && count(array_unique(array_column($rows, 'name'))) === 4, 'm6. 外层 UNION 对嵌套分支整体去重', implode(',', array_column($rows, 'name')));

        $inner = VerifyItem::query()->selectAs('sku', 'name')->whereIn('sku', ['CQ-B', 'CQ-C', 'CQ-D'])
            ->unionAll(VerifyItem::query()->selectAs('sku', 'name')->where('sku', 'CQ-A2'))
            ->orderBy('name', 'DESC')->limit(2)->offset(1); // D,C,B,A2 分支内取 C、B
        $outer = VerifyItem::query()->selectAs('sku', 'name')->where('sku', 'CQ-D')
            ->unionAll($inner);
        $rows = $outer->rows();
        $check->assert(array_column($rows, 'name') === ['CQ-D', 'CQ-C', 'CQ-B'],
            'm7. 嵌套 UNION 顶层排序分页只在分支内生效', implode(',', array_column($rows, 'name')));

        if ($driver === 'pgsql') {
            $literal = VerifyItem::queryOneRaw(
                'SELECT $fn$:not_a_param ? \'quoted\' \\ slash$fn$ AS literal, :value::int AS n',
                ['value' => 42],
            );
            $check->assert($literal !== null
                && $literal['literal'] === ":not_a_param ? 'quoted' \\ slash"
                && (int) $literal['n'] === 42,
                'n5. PostgreSQL dollar-quoted 文本真实执行', json_encode($literal, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        $check->no('m. 派生表 / UNION', $e->getMessage());
    }

    // n. raw 逃生舱真实执行（含引号/注释里的伪占位符、重复参数、类型转换结构）
    try {
        $rows = VerifyItem::query()
            ->selectRaw('sku, qty * :multiplier AS score', ['multiplier' => 2])
            ->whereRaw('qty > :minimum AND user_id >= :lo AND user_id <= :hi', ['minimum' => 2, 'lo' => 201, 'hi' => 202])
            ->orderByRaw('id')
            ->rows();
        $skus = array_column($rows, 'sku');
        $check->assert($skus === ['CQ-B', 'CQ-C', 'CQ-D'], 'n1. selectRaw/whereRaw/orderByRaw 真实执行', implode(',', $skus));

        $one = VerifyItem::queryOneRaw(
            "SELECT :value AS n, ':not_a_param' AS literal /* :also_not */",
            ['value' => 42],
        );
        $check->assert($one !== null && (int) $one['n'] === 42 && $one['literal'] === ':not_a_param', 'n2. queryOneRaw 引号/注释伪占位符原样保留', json_encode($one, JSON_UNESCAPED_UNICODE));

        // raw 参数不匹配必须拒绝（缺失/多余/? 占位符/非标量值）
        $rawReject = 0;
        foreach ([
            fn () => VerifyItem::queryRaw('SELECT :a AS v', []),
            fn () => VerifyItem::queryRaw('SELECT :a AS v', ['a' => 1, 'b' => 2]),
            fn () => VerifyItem::queryRaw('SELECT ?', [1]),
            fn () => VerifyItem::queryRaw('SELECT :a AS v', ['a' => ['x']]),
        ] as $badRaw) {
            try {
                $badRaw();
            } catch (InvalidArgumentException) {
                $rawReject++;
            }
        }
        $check->assert($rawReject === 4, 'n3. raw 参数严格校验 4 种非法形态全部拒绝', "拒绝 {$rawReject}/4");

        $cte = VerifyItem::queryRaw(
            'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < :last) '
            . 'SELECT n, ROW_NUMBER() OVER (ORDER BY n DESC) AS position FROM seq ORDER BY n',
            ['last' => 3],
        );
        $ok = array_column($cte, 'n') === [1, 2, 3] && array_column($cte, 'position') === [3, 2, 1];
        $check->assert($ok, 'n4. 参数化递归 CTE + 窗口函数真实执行', json_encode($cte));
    } catch (Throwable $e) {
        $check->no('n. raw 逃生舱', $e->getMessage());
    }

    // o. UPSERT（含不覆盖 created_at、自动时间戳、忽略模式）
    try {
        $target = VerifyItem::query()->where('sku', 'CQ-A')->row();
        $createdBefore = (int) $target['created_at'];
        $targetId = (int) $target['id'];

        // o1. 冲突更新：默认不覆盖 created_at，即使显式传了新值
        $mark = count($rec->log);
        VerifyItem::upsert([
            'id' => $targetId,
            'user_id' => 201,
            'sku' => 'CQ-A2',
            'qty' => 99,
            'price' => BcDecimal::fromString('11.00'),
            'flag' => 1,
            'happened_at' => '2026-02-09 00:00:00',
            'created_at' => $createdBefore + 100000,
        ]);
        $rec->printSince($mark);
        $after = VerifyItem::query()->where('id', $targetId)->row();
        $ok = $after['sku'] === 'CQ-A2'
            && (int) $after['qty'] === 99
            && (int) $after['created_at'] === $createdBefore;
        $check->assert($ok, 'o1. UPSERT 冲突更新生效且不覆盖 created_at', json_encode($after, JSON_UNESCAPED_UNICODE));

        // o2. UPSERT 新行：created_at 未提供必须自动填充
        $newId = $targetId + 1000;
        VerifyItem::upsert([
            'id' => $newId,
            'user_id' => 203,
            'sku' => 'CQ-NEW',
            'qty' => 1,
            'price' => BcDecimal::fromString('1.00'),
            'flag' => 0,
            'happened_at' => '2026-02-10 00:00:00',
        ]);
        $newRow = VerifyItem::query()->where('id', $newId)->row();
        $check->assert($newRow !== null && (int) $newRow['created_at'] > 0, 'o2. UPSERT 新行自动填充 created_at', json_encode($newRow, JSON_UNESCAPED_UNICODE));
        $newCached = VerifyItem::find(['id' => $newId]);

        // o3. 忽略模式：冲突时原行不变
        VerifyItem::upsert([
            'id' => $targetId,
            'user_id' => 201,
            'sku' => 'SHOULD-NOT-CHANGE',
            'qty' => 777,
            'price' => BcDecimal::fromString('77.00'),
            'flag' => 0,
            'happened_at' => '2026-02-11 00:00:00',
        ], []);
        $ignored = VerifyItem::query()->where('id', $targetId)->row();
        $check->assert($ignored['sku'] === 'CQ-A2' && (int) $ignored['qty'] === 99, 'o3. UPSERT 忽略模式原行不变', json_encode($ignored, JSON_UNESCAPED_UNICODE));

        // o4. 复合自然键 UPSERT（tag 表）
        VerifyTag::upsert(['item_id' => $targetId, 'label' => 'paid'], []);
        $tagCount = VerifyTag::query()->where('item_id', $targetId)->count();
        $check->assert($tagCount === 2, 'o4. 复合自然键 UPSERT 冲突忽略不重复插', 'tagCount=' . $tagCount);

        VerifyItem::upsert([
            'id' => $targetId,
            'user_id' => 201,
            'sku' => 'MUST-NOT-UPDATE',
            'qty' => 100,
            'price' => BcDecimal::fromString('88.00'),
            'flag' => 0,
            'happened_at' => '2026-02-12 00:00:00',
            'updated_at' => 123456789,
        ], ['qty']);
        $explicit = VerifyItem::query()->where('id', $targetId)->row();
        $ok = $explicit['sku'] === 'CQ-A2' && (int) $explicit['qty'] === 100
            && (int) $explicit['updated_at'] === 123456789;
        $check->assert($ok, 'o5. UPSERT 显式更新 qty 并自动包含 updated_at，不更新 sku', json_encode($explicit, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $check->no('o. UPSERT', $e->getMessage());
    }

    // p. 条件 UPDATE / DELETE（真实执行 + 守卫拒绝）
    try {
        $affected = VerifyItem::query()->where('sku', 'CQ-C')->update(['qty' => 44]);
        $reread = VerifyItem::query()->where('sku', 'CQ-C')->row();
        $check->assert($affected === 1 && (int) $reread['qty'] === 44, 'p1. 条件 UPDATE 返回受影响行数且落库', "affected={$affected} qty=" . ($reread['qty'] ?? 'null'));

        $deleted = VerifyItem::query()->where('sku', 'CQ-NEW')->delete();
        $stillThere = VerifyItem::query()->where('sku', 'CQ-NEW')->row();
        $check->assert($deleted === 1 && $stillThere === null, 'p2. 条件 DELETE 返回 1 且行消失', "deleted={$deleted}");
        $check->assert($newCached->isNew(), 'p2b. 条件 DELETE 使命中缓存的实体失效', json_encode([
            'newId' => $newId,
            'deleteSql' => $rec->log[count($rec->log) - 2] ?? null,
            'returned' => $rec->log[count($rec->log) - 1] ?? null,
            'cachedStillPersisted' => !$newCached->isNew(),
        ], JSON_UNESCAPED_UNICODE));

        $guards = 0;
        foreach ([
            fn () => VerifyItem::query()->update(['qty' => 1]),                              // 无 WHERE
            fn () => VerifyItem::query()->where('id', 1)->update(['id' => 5]),               // 改主键
            fn () => VerifyItem::query()->select('id')->where('id', 1)->update(['qty' => 1]),  // 带投影
            fn () => VerifyItem::query()->orderBy('id')->where('id', 1)->delete(),             // 带排序
            fn () => VerifyItem::query()->limit(1)->where('id', 1)->delete(),                  // 带分页
            fn () => VerifyItem::query()->forUpdate()->where('id', 1)->delete(),               // 带行锁
        ] as $guard) {
            try {
                $guard();
            } catch (InvalidArgumentException) {
                $guards++;
            }
        }
        $check->assert($guards === 6, 'p3. 条件写守卫（无WHERE/主键/投影/排序/分页/锁）全部拒绝', "拒绝 {$guards}/6");
    } catch (Throwable $e) {
        $check->no('p. 条件写', $e->getMessage());
    }

    // q. 行锁：非 sqlite 事务内 FOR UPDATE 真实执行；sqlite 必须拒绝
    try {
        if ($driver === 'sqlite') {
            $refused = false;
            try {
                VerifyItem::query()->forUpdate()->rows();
            } catch (LogicException $e) {
                $refused = str_contains($e->getMessage(), 'SQLite');
            }
            $check->assert($refused, 'q1. SQLite 执行期拒绝 FOR UPDATE（预期行为）');
        } else {
            $lockSku = VerifyItem::transaction(function (): ?string {
                $row = VerifyItem::query()->where('sku', 'CQ-D')->forUpdate()->row();

                return $row['sku'] ?? null;
            });
            $check->assert($lockSku === 'CQ-D', 'q1. ' . $driver . ' 事务内 FOR UPDATE 真实执行成功', 'sku=' . var_export($lockSku, true));
            $shared = VerifyItem::transaction(function (): ?string {
                $row = VerifyItem::query()->where('sku', 'CQ-D')->forShare()->row();

                return $row['sku'] ?? null;
            });
            $check->assert($shared === 'CQ-D', 'q2. ' . $driver . ' 事务内 FOR SHARE 真实执行成功', 'sku=' . var_export($shared, true));
        }
    } catch (Throwable $e) {
        $check->no('q. 行锁', $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// 单库验证全流程（连接 + 建表 + 场景 + 清理）
// ----------------------------------------------------------------------------

function verifyOn(string $label, string $dsn, string $user, string $pass): Check
{
    echo "==== $label ====\n";
    $check = new Check();

    // a. 连接 + SELECT 1
    $t0 = microtime(true);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $elapsed = round(microtime(true) - $t0, 2);
        $one = $pdo->query('SELECT 1')->fetchColumn();
        $check->assert((string) $one === '1', "a. 连接 + SELECT 1（{$elapsed}s）", 'SELECT 1 返回: ' . var_export($one, true));
    } catch (PDOException $e) {
        $elapsed = round(microtime(true) - $t0, 2);
        $check->no("a. 连接 + SELECT 1（{$elapsed}s）", $e->getMessage());
        echo "\n$label 连接失败，跳过该库后续验证\n";

        return $check;
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    try {
        createTables($pdo, $driver);
        $check->ok('建表 ' . VerifyItem::table() . ' / ' . VerifyTag::table());
    } catch (PDOException $e) {
        $check->no('建表 ' . VerifyItem::table() . ' / ' . VerifyTag::table(), $e->getMessage());
        try {
            dropOwnedTables($pdo);
        } catch (PDOException) {
            // 建表都失败，清理尽力而为
        }

        return $check;
    }

    $rec = new RecordingConnection(new PdoConnection($pdo));
    $gateway = new EntityGateway($rec);
    Session::use($gateway);

    try {
        runScenarios($driver, $pdo, $rec, $check);
    } catch (Throwable $e) {
        $check->no('基础场景执行意外中断', $e->getMessage());
    }
    try {
        runComplexScenarios($driver, $pdo, $rec, $check);
    } catch (Throwable $e) {
        $check->no('复杂场景执行意外中断', $e->getMessage());
    }

    // 清理：只删本次自己创建的表，tag 先 item 后
    try {
        dropOwnedTables($pdo);
        $check->ok('清理：' . VerifyTag::table() . ' / ' . VerifyItem::table() . ' 已 DROP（仅本次创建）');
    } catch (PDOException $e) {
        $check->no('清理 DROP TABLE', $e->getMessage());
    }

    // SQL 清单
    echo "\n---- $label ORM 生成的 SQL 清单（去重）----\n";
    foreach ($rec->sqlList() as $sql) {
        echo "  $sql\n";
    }
    echo "-------------------------------------------\n";

    return $check;
}

// ----------------------------------------------------------------------------
// SQLite 兜底（两个库都连不上时，证明脚本断言逻辑正确）
// ----------------------------------------------------------------------------

function verifySqliteFull(): Check
{
    echo "==== SQLite 全量场景兜底（证明脚本逻辑正确；RETURNING 路径与 PG 同构）====\n";
    $check = new Check();
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $one = $pdo->query('SELECT 1')->fetchColumn();
        $check->assert((string) $one === '1', 'a. 连接 + SELECT 1');

        createTables($pdo, 'sqlite');
        $check->ok('建表 ' . VerifyItem::table() . ' / ' . VerifyTag::table());

        $rec = new RecordingConnection(new PdoConnection($pdo));
        $gateway = new EntityGateway($rec);
        Session::use($gateway);

        runScenarios('sqlite', $pdo, $rec, $check);
        runComplexScenarios('sqlite', $pdo, $rec, $check);

        dropOwnedTables($pdo);
        $check->ok('清理：' . VerifyTag::table() . ' / ' . VerifyItem::table() . ' 已 DROP（仅本次创建）');

        echo "\n---- SQLite ORM 生成的 SQL 清单（去重）----\n";
        foreach ($rec->sqlList() as $sql) {
            echo "  $sql\n";
        }
        echo "-------------------------------------------\n";
    } catch (Throwable $e) {
        $check->no('SQLite 兜底执行', $e->getMessage());
    }

    return $check;
}

// ----------------------------------------------------------------------------
// 主流程
// ----------------------------------------------------------------------------

// 每次运行使用随机后缀表名，避免与生产同名表或并发运行冲突
$suffix = strtolower(bin2hex(random_bytes(4))); // 8 位十六进制
VerifyItem::$tableName = 'orm_verify_item_' . $suffix;
VerifyTag::$tableName = 'orm_verify_tag_' . $suffix;
echo '本次验证表名: ' . VerifyItem::table() . ' / ' . VerifyTag::table() . "\n";

// .env 依序探测：库根 → webman 主项目（作为库在主项目内开发时复用其连接配置）
$envCandidates = [
    __DIR__ . '/../.env',
    __DIR__ . '/../../webman/.env',
];
$envFile = '';
foreach ($envCandidates as $candidate) {
    if (is_file($candidate)) {
        $envFile = $candidate;
        break;
    }
}
$env = $envFile === '' ? [] : parseEnv($envFile);
// Docker/CI 等场景用进程环境显式覆盖 .env，避免误连外部数据库。
foreach ([
    'DB_PGSQL_HOST', 'DB_PGSQL_PORT', 'DB_PGSQL_NAME', 'DB_PGSQL_USER', 'DB_PGSQL_PASSWORD',
    'DB_MYSQL_HOST', 'DB_MYSQL_PORT', 'DB_MYSQL_NAME', 'DB_MYSQL_USER', 'DB_MYSQL_PASSWORD',
] as $name) {
    $value = getenv($name);
    if ($value !== false) {
        $env[$name] = $value;
    }
}

$pgHost = $env['DB_PGSQL_HOST'] ?? '';
$pgPort = $env['DB_PGSQL_PORT'] ?? '5432';
$pgName = $env['DB_PGSQL_NAME'] ?? '';
$pgUser = $env['DB_PGSQL_USER'] ?? '';
$pgPass = $env['DB_PGSQL_PASSWORD'] ?? '';

$myHost = $env['DB_MYSQL_HOST'] ?? '';
$myPort = $env['DB_MYSQL_PORT'] ?? '3306';
$myName = $env['DB_MYSQL_NAME'] ?? '';
$myUser = $env['DB_MYSQL_USER'] ?? '';
$myPass = $env['DB_MYSQL_PASSWORD'] ?? '';

$drivers = PDO::getAvailableDrivers();
echo 'PDO 驱动: ' . implode(', ', $drivers) . "\n\n";

$pgCheck = null;
$myCheck = null;

if (in_array('pgsql', $drivers, true) && $pgHost !== '') {
    $pgCheck = verifyOn(
        'PostgreSQL (' . $pgHost . ':' . $pgPort . ')',
        "pgsql:host={$pgHost};port={$pgPort};dbname={$pgName}",
        $pgUser,
        $pgPass,
    );
    echo "\n";
} else {
    echo "==== PostgreSQL ====\n  跳过：pdo_pgsql 扩展未加载或缺少 DB_PGSQL_HOST 配置\n\n";
}

if (in_array('mysql', $drivers, true) && $myHost !== '') {
    $myCheck = verifyOn(
        'MySQL (' . $myHost . ':' . $myPort . ')',
        "mysql:host={$myHost};port={$myPort};dbname={$myName};charset=utf8mb4",
        $myUser,
        $myPass,
    );
    echo "\n";
} else {
    echo "==== MySQL ====\n  跳过：pdo_mysql 扩展未加载或缺少 DB_MYSQL_HOST 配置\n\n";
}

// 至少一个真实库存在失败或无法连接时，才运行独立的 SQLite 兜底。
$anyFullPass = ($pgCheck !== null && $pgCheck->failed === 0)
    || ($myCheck !== null && $myCheck->failed === 0);
$smokeCheck = null;
if (!$anyFullPass && in_array('sqlite', $drivers, true)) {
    $smokeCheck = verifySqliteFull();
    echo "\n";
}

// ----------------------------------------------------------------------------
// 总结
// ----------------------------------------------------------------------------

echo "================ 验证总结 ================\n";

$totalPass = 0;
$totalFail = 0;
foreach ([['PostgreSQL', $pgCheck], ['MySQL', $myCheck], ['SQLite 兜底', $smokeCheck]] as [$name, $c]) {
    if ($c === null) {
        echo "$name: 未执行\n";
        continue;
    }
    $totalPass += $c->passed;
    $totalFail += $c->failed;
    echo sprintf('%s: 通过 %d / 失败 %d', $name, $c->passed, $c->failed) . "\n";
    foreach ($c->failures as $f) {
        echo "  - [失败] $f\n";
    }
}
echo "-----------------------------------------\n";
echo "总计: 通过 $totalPass / 失败 $totalFail\n";

// 真实库判定单独输出：SQLite 兜底不计入真实库结论
$realFail = ($pgCheck?->failed ?? 0) + ($myCheck?->failed ?? 0);
if ($pgCheck !== null && $myCheck !== null && $realFail === 0) {
    echo "结论：PostgreSQL 与 MySQL 真实库验证全部通过\n";
} elseif ($realFail > 0) {
    echo "结论：真实库存在失败项（详见上方失败清单）；未执行的数据库不计入通过\n";
} elseif ($pgCheck === null && $myCheck === null) {
    echo "结论：PostgreSQL 与 MySQL 均未执行（环境阻塞）；SQLite 结果不代表真实库通过\n";
} else {
    $passed = $pgCheck !== null ? 'PostgreSQL' : 'MySQL';
    $missing = $pgCheck === null ? 'PostgreSQL' : 'MySQL';
    echo "结论：{$passed} 真实库通过；{$missing} 未执行，不能宣称全部真实库通过\n";
}

if ($totalFail > 0) {
    exit(1);
}
exit(0);
