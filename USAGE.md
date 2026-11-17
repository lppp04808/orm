# ORM 内核使用指南

命名空间 `Pixiaoka\Orm\`，独立 Composer 包代码在 `src/`。这是一个独立于 webman 的 Active Record ORM 内核，通过注解定义字段和主键，在网关层做类型进出转换。

## 1. 初始化

ORM 不依赖 webman，也不拥有 PDO 或强制连接池。普通 PHP-FPM 可在请求开始时登记 Session，并在 `finally` 中调用 `Session::discard()`。常驻进程和并发 Fiber/协程建议使用严格模式与作用域：

```php
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

$pdo = new \PDO('mysql:host=...;dbname=...', 'user', 'pass');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

Session::use(new EntityGateway(new PdoConnection($pdo)));
try {
    // 业务操作
} finally {
    Session::discard();
}

// 每个 Fiber/协程使用自己的连接；连接可由应用或可选连接池提供。
Session::strict();
Session::scope(new EntityGateway(new PdoConnection($pdo)), function (): void {
    // 正常返回、异常均自动恢复此前的 Session。
});
```

作用域不关闭 PDO，应用负责创建、重连、健康检查及归还。`Session::discard()` 会回滚当前上下文遗留的外层事务并解除绑定；`Session::scope()` 关闭时先执行同样的清理，再恢复此前的 Session，即使回滚失败也会恢复绑定并透传异常。事务回调内清理 Session 后不能继续提交。同一个 `EntityGateway` 被多个 Fiber/协程同时用于事务会被提前拒绝。

**webman 只是可选适配方式**，可在应用中间件中为每个请求调用上述作用域；不要把根 PDO 隐式共享给所有 Fiber。嵌套上下文必须显式选择连接。Session 与事务状态按 Fiber/运行时协程对象弱引用隔离，不依赖可复用的数字 ID。Swoole/Swow 仍应在真实扩展环境验证，不能仅以 Fiber 测试替代。

## 2. 定义模型

### 代理键（自增 ID）

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;

final class User extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public int $age;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'user';
    }
}
```

### 复合自然主键

```php
final class OrderItem extends Entity
{
    #[Column]
    #[PrimaryKey(order: 1)]
    public int $order_id;

    #[Column]
    #[PrimaryKey(order: 2)]
    public string $sku;

    #[Column]
    public int $quantity;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'order_item';
    }
}
```

### 禁止：生成键和自然键混用

```php
// ❌ 一个模型里不能同时有 generated: true 和 order: N 的主键
```

### 字段类型支持

| PHP 类型 | 数据库 | 示例属性 |
|----------|--------|----------|
| `int` | INTEGER | `public int $count;` |
| `float` | REAL / FLOAT | `public float $amount;` |
| `string` | VARCHAR / TEXT | `public string $name;` |
| `bool` | 0/1 | `public bool $active;` |
| `array` | JSON | `public array $tags;` |
| `Pixiaoka\Orm\Cast\BcDecimal` | TEXT（高精度十进制） | `public BcDecimal $price;` |
| `BackedEnum` | 枚举值 | `public SampleStatus $status;` |
| `DateTimeImmutable` | `Y-m-d H:i:s` UTC | `#[Temporal] public DateTimeImmutable $at;` |
| 可空 `?` 形式 | NULL | `public ?string $desc;` |

### DateTimeImmutable 的存储格式

```php
use Pixiaoka\Orm\Annotation\Temporal;

// 默认：存成 UTC 文本 "2026-11-13 21:17:45"
#[Column]
#[Temporal]
public DateTimeImmutable $happened_at;

// Unix 时间戳
#[Column]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $created_at;
```

### 可配置时间字段自动填充

传统 `int $created_at` / `int $updated_at` 不写其他注解仍兼容。自定义字段名使用 `#[CreatedAt]` 和 `#[UpdatedAt]`，支持 `int` 与 `DateTimeImmutable`：

```php
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Annotation\Temporal;

#[Column]
#[CreatedAt]
public DateTimeImmutable $created_on; // 默认 UTC Y-m-d H:i:s

#[Column(name: 'modify_time')]
#[UpdatedAt]
public int $modify_time; // Unix 秒

#[Column]
#[UpdatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $updated_unix; // DateTimeImmutable 也可存 Unix 秒
```

插入时填充两者，更新时只刷新更新时间。调用方显式修改过更新时间则不覆盖；创建时间不会在普通更新或 UPSERT 冲突更新中被覆盖。插入、批量插入和 UPSERT 都支持自动填充。每个角色只能声明一次、不能共用同一字段、不能标在主键上；不支持其他 PHP 类型。没有这些字段的模型无需配置。

模型生成器会为传统的 `created_at` / `updated_at` 自动附加时间角色注解，但仅当它们映射为 `int` 或可无损读取的 `DateTimeImmutable` 时；其他列名需自行添加上述注解。

## 3. Active Record

### save() —— 智能插入/更新

`save()` 看 `new` 出来的走插入，`find` 出来的走更新。**不看主键值**。

```php
// 插入
$u = new User();
$u->name = '张三';
$u->age = 28;
$u->save();
// $u->id 已被回填

// 查询后更新
$u = User::find(['id' => 1]);
$u->age = 29;
$u->save();  // UPDATE —— 只改变了的字段

// 删除后重新插入
$u = User::find(['id' => 1]);
$u->delete();
$u->save();  // 重新 INSERT
```

### insert() —— 强制插入

```php
$u = new User();
$u->name = '李四';
$u->insert(); // 只在 isNew=true 时可用

$u->insert(); // ❌ IdentityException:"已经在库里，不能再 insert"
```

### delete() —— 删除

```php
$u = User::find(['id' => 5]);
$u->delete(); // 返回 void；受影响行 !== 1 时抛异常

$u->delete(); // ❌ IdentityException:"还没入库，不能 delete"
```

### find() —— 按主键查找

```php
// 代理键
$u = User::find(['id' => 42]);

// 复合主键 —— 必须给全
$item = OrderItem::find(['order_id' => 10, 'sku' => 'ABC']);

// 少给一列不会发 SQL，直接拒绝
OrderItem::find(['order_id' => 10]); // ❌ IdentityException
```

### toArray() —— 导出

```php
$u = User::find(['id' => 1]);
print_r($u->toArray());
// ['id' => 1, 'name' => '张三', 'age' => 29, 'created_at' => 1700000000, 'updated_at' => 1700000000]
```

### refresh() —— 重新从数据库读

```php
$u = User::find(['id' => 1]);
// ... 其他进程改了 ...
$u->refresh(); // 重新 SELECT，覆盖所有字段
```

## 4. 查询构建器

### 基础查询

```php
use app\model\User;

// 条件查询
$users = User::query()
    ->where('age', 28)
    ->whereOp('name', 'LIKE', '张%')
    ->get();

// 单条
$user = User::query()
    ->where('name', '张三')
    ->first();

// 计数
$count = User::query()
    ->where('age', 28)
    ->count();
```

### 可用条件方法

```php
->where('column', $value)                     // column = :value；null 自动转为 IS NULL
->whereOp('column', '>', 10)                  // column > 10
->whereOp('column', '!=', null)               // column IS NOT NULL
->whereOp('column', 'BETWEEN', [1, 5])        // 两个参数
->whereNull('column')                         // column IS NULL
->whereNotNull('column')                      // column IS NOT NULL
->whereIn('column', [1, 2, 3])               // column IN (1,2,3)
->whereNotIn('column', [])                    // 空 IN 恒假；空 NOT IN 恒真
```

### OR 条件

```php
// (age < 18 OR age > 60) AND city = '北京'
$users = User::query()
    ->orGroup(fn($q) => $q->whereOp('age', '<', 18)->whereOp('age', '>', 60))
    ->where('city', '北京')
    ->get();
```

### 排序和分页

```php
$users = User::query()
    ->whereOp('age', '>=', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->offset(0)
    ->get();
```

## 5. 复杂查询

### 5.1 结果类型

`get()` / `first()` 只返回完整实体。自定义投影、JOIN、聚合、GROUP/HAVING、UNION、派生表的结果必须走：

- `rows()`：数组列表；
- `row()`：一行数组或 `null`；
- `value()`：第一个字段或指定字段的标量。

ORM 不会把缺列数组强行 hydrate 成实体，也不会把部分实体写进行缓存。原始数组不会自动转为 `Decimal`、`DateTimeImmutable` 等 PHP 对象。

### 5.2 投影、JOIN 和表别名

```php
use Pixiaoka\Orm\Query\JoinClause;

$rows = Order::query()->alias('o')
    ->selectAs('o.sku', 'order_sku')
    ->selectAs('t.label', 'tag_label')
    ->leftJoin('order_tag', 't', fn (JoinClause $j) => $j
        ->on('o.id', '=', 't.order_id')       // 列对列，标识符校验并引用
        ->where('t.label', '=', 'paid'))      // ON 中的绑定参数
    ->where('o.user_id', 7)
    ->rows();
```

`join()` / `innerJoin()` / `leftJoin()` / `rightJoin()` 使用结构化 ON。无 ON 的特殊连接必须显式 `joinRaw($sql, $params)`。JOIN 不带 select 时默认只返回基表的完整列；仍建议使用 `selectAs()` 并调用 `rows()`。

基表模型字段的 WHERE/IN/BETWEEN/SET 值会复用模型 Caster，支持属性名和真实列名。带 `alias.column` 的字段不自动推断其他表模型，应传数据库标量或用 raw 条件。

### 5.3 聚合、DISTINCT、GROUP BY / HAVING

```php
$stats = Order::query()
    ->select('user_id')
    ->aggregate('COUNT', '*', 'orders')
    ->aggregate('SUM', 'qty', 'total_qty')
    ->aggregate('AVG', 'qty', 'average_qty')
    ->aggregate('MIN', 'qty', 'min_qty')
    ->aggregate('MAX', 'qty', 'max_qty')
    ->groupBy('user_id')
    ->having('SUM(qty)', '>=', 10)
    ->rows();

$sum = Order::query()->aggregate('SUM', 'qty')->value();
$users = Order::query()->select('user_id')->distinct()->count();
```

聚合函数只允许 COUNT/SUM/AVG/MIN/MAX。结构化 HAVING 仅接受标识符或上述聚合表达式；复杂条件使用参数化 `havingRaw()`。

### 5.4 子查询与派生表

```php
$paid = OrderTag::query()->select('order_id')->where('label', 'paid');
$rows = Order::query()->whereIn('id', $paid)->rows();

$max = Order::query()->aggregate('MAX', 'qty');
$row = Order::query()->whereOp('qty', '=', $max)->row();

$exists = OrderTag::query()->select('order_id')
    ->whereRaw('order_tag.order_id = order.id')
    ->where('label', 'paid');
$rows = Order::query()->whereExists($exists)->rows();

$top = Order::query()->selectAs('user_id', 'uid')->selectAs('qty', 'amount');
$rows = Order::query()->select('uid')->from($top, 'ranked')
    ->whereOp('amount', '>', 5)->rows();
```

标量和 IN 子查询必须显式只选择一列；EXISTS 无此限制。子查询、派生表与 UNION 不允许携带行锁；子查询必须与父查询来自同一个 `EntityGateway`，防止 SQL 和参数在错误连接上执行。

### 5.5 UNION / UNION ALL

```php
$first = Order::query()->selectAs('sku', 'name')
    ->where('user_id', 7)->orderBy('id')->limit(1);
$second = Order::query()->selectAs('sku', 'name')
    ->where('user_id', 8)->orderBy('id', 'DESC')->limit(1);

$rows = $first->unionAll($second)
    ->orderBy('name', 'DESC')   // 整个 UNION 的顶层排序
    ->limit(10)                 // 整个 UNION 的顶部分页
    ->rows();
```

各分支必须返回相同数量且同名的输出列，请使用 `select()/selectAs()` 或带别名的 `aggregate()`。`union()` 前的局部分页属于首分支；分支自己的 `orderBy()/limit()/offset()` 由 Builder 按 SQLite/MySQL/PostgreSQL 方言包裹。嵌套 UNION 可作为派生表或单列子查询；复杂 raw UNION 请使用 `queryRaw()`。

### 5.6 Raw SQL：CTE、窗口函数和专有表达式

```php
$rows = Order::queryRaw(
    'WITH RECURSIVE seq(n) AS (
        SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < :last
     ) SELECT n FROM seq',
    ['last' => 10],
);

$rows = Order::query()
    ->selectRaw('qty * :factor AS score', ['factor' => 2])
    ->whereRaw('qty > :minimum', ['minimum' => 3])
    ->orderByRaw('id + :offset', ['offset' => 0])
    ->rows();
```

Raw 是**受信任的开发者 SQL**，不是用户输入拼接接口：

- 只接受命名占位符，参数名和 SQL 实际使用必须完全一致；
- 缺参、多参、`?` 占位符、数组/对象参数均抛异常；
- 字符串、双引号、反引号、行注释、块注释中的伪参数会被跳过；
- PostgreSQL `value::type` 类型转换不会误识别为参数；
- PostgreSQL `$$...$$` / `$tag$...$tag$` dollar-quoted 文本中的 `:name`、`?` 不识别为参数；`PdoConnection` 会在交给 `pdo_pgsql` 前将其转换为等价的转义字符串字面量，避免 PDO 驱动误读伪参数，返回文本保持原样；
- 同一参数重复出现时，每次构建独立分配唯一的 `qN`；
- `Order::raw($sql, $params)` 创建可复用 Expression；不可再重复传参数；
- `queryRaw/queryOneRaw` 返回数组；`executeRaw` 返回受影响行数，不自动 hydrate。

Raw 写入模型数据后需要显式 `Order::flushCache()`；它绕过模型身份和生命周期钩子。

## 6. 条件 UPDATE / DELETE、UPSERT 与行锁

### 6.1 条件批量写入

```php
$affected = Order::query()->where('user_id', 7)->update(['qty' => 10]);
$affected = Order::query()->whereIn('id', [1, 2])->delete();
```

必须有 WHERE。空 IN 仍保留为恒假条件，不会退化为全表写；空 SET、主键修改、JOIN/GROUP/HAVING/UNION/投影/排序/分页/锁均拒绝。成功执行后清理该模型缓存，命中 0 行不是异常。

PostgreSQL/SQLite 使用 `DELETE ... RETURNING` 读取实际删除主键；MySQL 先对当前 Session 已交付实体的身份做受限预查，再执行 DELETE。两者都按模型 Caster 规范化主键，只把实际删除的对象标为未入库；对象即使已被 LRU 或批量写清出缓存，仍能通过弱引用身份表精确失效，不会误标同表其他实体。条件 UPDATE/UPSERT 不会更新调用方已经持有的旧实体对象，必要时请 `refresh()` 或重新查询。批量写不触发生命周期钩子。

MySQL 预查与外部并发写之间不提供跨 Session/进程的一致性保证，`isNew()` 状态也不是分布式锁。需要严格并发一致性时，请使用事务、数据库约束和行锁。

### 6.2 跨方言 UPSERT

```php
Order::upsert($values);                    // 默认主键冲突，更新其余输入字段
Order::upsert($values, ['qty']);           // 只更新 qty，并自动维护 updated_at
Order::upsert($values, []);                // 冲突时忽略
Order::upsert($values, ['qty'], ['key']);  // 显式冲突键
```

- PostgreSQL/SQLite：`ON CONFLICT (...) DO UPDATE/DO NOTHING`；
- MySQL：`ON DUPLICATE KEY UPDATE`；
- 默认冲突键为模型主键，冲突键必须包含在输入中；
- 默认更新列不包含冲突键、生成列和 `created_at`；
- 自动填充缺失的 int 型 `created_at/updated_at`；
- 显式 `$updateColumns=['qty']` 仍自动附加 `updated_at`；显式提供的时间戳值会被尊重；
- 未提供的非空字段提前拒绝，不会自动猜测 PHP 属性默认值或数据库 `DEFAULT`，请显式传值；
- 字段值复用模型 Caster；返回受影响行数，不承诺回填 ID，不触发实体钩子。

**MySQL 不能指定 conflict target，会响应任意唯一键冲突**。`$conflictColumns` 在 MySQL 只负责输入完整性校验，不会把 SQL 限制为指定索引。代理生成键只有显式作为冲突键时才允许写入。

### 6.3 行锁

```php
Order::transaction(function (): void {
    $order = Order::query()->where('id', 1)->forUpdate()->first();
    // 在事务内修改并保存
});

$shared = Order::query()->where('id', 1)->forShare()->first();
```

PostgreSQL/MySQL 支持 `FOR UPDATE/FOR SHARE`，Builder 按各自语法放置锁与分页子句。SQLite 不支持，执行时明确抛异常，不能静默忽略。通常应在事务内使用；子查询、派生表、UNION 和条件写不允许携带行锁。

## 7. 批量插入

```php
// 数组方式
User::insertAll([
    ['name' => 'A', 'age' => 20],
    ['name' => 'B', 'age' => 25],
]);

// Entity 对象方式（会触发钩子）
$a = new User(); $a->name = 'A'; $a->age = 20;
$b = new User(); $b->name = 'B'; $b->age = 25;
User::insertAll([$a, $b]);
// $a、$b 的 created_at 被填充，状态变为 persisted
```

## 8. 事务

```php
User::transaction(function () {
    $a = new User();
    $a->name = '付款方';
    $a->save();

    $b = new User();
    $b->name = '收款方';
    $b->save();
});

// 嵌套事务只在外层提交/回滚
User::transaction(function () {
    // ...
    User::transaction(function () {
        // 内层不开启真事务
    });
});
```

## 9. 生命周期钩子

在 Entity 子类中覆盖钩子方法，实现业务逻辑：

```php
final class Order extends Entity
{
    // ... 字段定义 ...

    public function beforeInsert(): void
    {
        $this->order_no = date('YmdHis') . random_int(1000, 9999);
    }

    public function beforeSave(): void
    {
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('金额必须大于 0');
        }
    }

    public function afterDelete(): void
    {
        // 清理关联数据、发送通知等
    }
}
```

可用钩子（全部 `public function`）：

| 钩子 | 触发时机 |
|------|---------|
| `beforeSave()` | insert 或 update 之前 |
| `afterSave()` | insert 或 update 之后 |
| `beforeInsert()` | 仅 insert 之前 |
| `afterInsert()` | 仅 insert 之后 |
| `beforeUpdate()` | 仅 update 之前 |
| `afterUpdate()` | 仅 update 之后 |
| `beforeDelete()` | delete 之前 |
| `afterDelete()` | delete 之后 |

## 10. 行缓存

**进程内自动管理**。同一个 class + 主键的 `find()` 会命中缓存返回同一个对象实例。

```php
$a = Asset::find(['user_id' => 1, 'key' => 'x']);
$b = Asset::find(['user_id' => 1, 'key' => 'x']);
// $a === $b（同一个实例）
```

- 查询结果也会写入缓存（`Builder::get()` 返回的实体）
- `delete()` 后自动清退缓存
- 事务回滚只清理由本事务写入的缓存 key
- 缓存在进程内有效，不跨进程

## 11. 脏跟踪

`save()` 做更新时只生成变了字段的 SET 子句。比较的是 `markPersisted()` 时刻的快照和当前值。

```php
$u = User::find(['id' => 1]); // 此时快照：age=28, name='张三'
$u->age = 29;
$u->save();
// SQL: UPDATE user SET age = :set_age WHERE id = :pk_id
// 不会写 name —— 因为它没变
```

## 12. 主键规则

| 规则 | 说明 |
|------|------|
| 代理键 | `#[PrimaryKey(generated: true)]`，恰好 1 个，插入后回填 |
| 自然键 | `#[PrimaryKey(order: N)]`，可复合，调用方必须提供 |
| 不混用 | 一个模型不能同时有 generated 和 order |
| 键值边界 | `0`、`""`、`false` 都是合法键值；`null` 不可作为主键 |
| 身份完整 | 任何按主键操作缺少任意一列都会拒绝，不发 SQL |
| 不可空 | 主键属性不能是可空类型（`?int`） |

## 13. 独立模型生成器

`pixiaoka/orm` 自带数据库结构读取和模型生成能力，不依赖 webman。生成器只连接调用方明确给出的数据库，并且只向 `--dir` 指定的目录写入模型。

```bash
# SQLite
vendor/bin/pixiaoka-orm generate \
    --dsn='sqlite:/absolute/path/app.sqlite' \
    --dir='/absolute/path/app/model/orm' \
    --namespace='App\\Model\\Orm'

# MySQL；PostgreSQL 将 dsn 换成 pgsql:host=...;port=5432;dbname=...
vendor/bin/pixiaoka-orm generate \
    --dsn='mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4' \
    --user=app --password=secret \
    --dir='/absolute/path/app/model/orm' \
    --namespace='App\\Model\\Orm' \
    --prefix=pc_ --force pc_order pc_order_item
```

- `--dsn`、`--dir`、`--namespace` 必填，`--user` / `--password` 按驱动需要传入；也可用 `ORM_DB_USER`、`ORM_DB_PASSWORD` 环境变量避免在进程参数中暴露密码。
- 带值选项同时支持 `--name=value` 与 `--name value`；缺值、重复选项、未知选项会明确报错并返回非零状态。
- 不传表名生成全部用户表；传入一个或多个表名则只处理这些表。
- `--prefix` 只剥离类名前缀，`table()` 仍返回完整真实表名。
- 默认不覆盖已有文件；`--force` 才允许覆盖。
- 支持自增单列主键、复合自然主键、字段可空性、标量默认值和常见数据库类型映射。
- 无主键表会被拒绝；批量生成先渲染校验全部表，再创建目录和写文件，避免留下部分输出。
- `CURRENT_TIMESTAMP` 等数据库动态表达式、`NULL` 和对象类型默认值不会生成非法 PHP 属性初始化代码；由插入流程或数据库负责赋值。
- 无默认值的非空标量使用合法零值，可空属性保留未初始化以区分未赋值和显式 `null`。
- 默认值按方言处理：MySQL 元数据为已解码文本，SQLite/PostgreSQL 为 SQL 字面量；PostgreSQL `E'...'` 单独解析转义，不折叠普通字符串反斜杠。
- 仅 SQLite 无精度 `datetime/timestamp` 和 PostgreSQL 无精度 `timestamp without time zone` 映射 `DateTimeImmutable + #[Temporal]`；`DATE`、`TIME`、时区、小数秒及 MySQL `datetime/timestamp` 保守使用 `string`。
- 表和列名采用受限标识符策略，防止数据库标识符或生成代码被注入；不支持的名称应显式检查、改名，不能通过拼接 SQL 绕过。
- 生成目录必须位于明确的业务目录中，不能把根目录或当前工作目录作为输出位置。

例如 `pc_order` 在 `--prefix=pc_` 下生成 `Order.php`：

```php
<?php

declare(strict_types=1);

namespace App\Model\Orm;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;

final class Order extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $order_no = '';

    public static function table(): string
    {
        return 'pc_order';
    }
}
```

公开 PHP API 可直接用于自己的生成工具：

```php
use Pixiaoka\Orm\ModelGenerator;
use Pixiaoka\Orm\Schema\SchemaInspector;
use Pixiaoka\Orm\Schema\TypeMapper;

$generator = new ModelGenerator($pdo, new TypeMapper());
$result = $generator->generate(
    directory: '/absolute/path/generated-models',
    namespace: 'App\\Model',
    prefix: 'pc_',
    force: false,
    tables: ['pc_order'], // [] 表示全部用户表
);
// 两个键都是表名 => 目标文件路径：
// ['generated' => ['pc_order' => '/.../Order.php'], 'skipped' => []]

$tableSchema = (new SchemaInspector($pdo))->table('pc_order');
$code = $generator->render($tableSchema, 'App\\Model'); // 只返回代码，不写文件
```

`ModelGenerator::__construct(PDO $pdo, ?TypeMapper $types = null)`；`generate()` 参数如上，返回 `array{generated: array<string, string>, skipped: array<string, string>}`；`render(TableSchema $table, string $namespace, ?string $class = null): string` 可单独渲染已读取的表元数据。

webman 的 `orm:gen` 只负责读取框架配置并调用同一库内 API，不包含另一套 Schema 读取或代码渲染实现。可运行 `php webman orm:gen [tables...]`，使用 `--connection`、`--dir`、`--namespace`、`--prefix`、`--force`；app 外目录必须显式指定 `--namespace`。

默认值边界详见 [README](README.md#generate-models-from-a-database)：PHP 8.3 不生成对象属性初始化；PostgreSQL 的 Unicode 转义按码点生成 UTF-8，原始高位字节按服务器编码转换。非 UTF-8 混合转义、SQL_ASCII、未知编码和非法序列均不猜测数据库默认值，而采用安全的 PHP 兜底值。

## 14. 测试与真实数据库验收

```bash
# 库内完整测试：191 tests / 669 assertions（2 项显式 DSN 实连测试默认跳过）
vendor/bin/phpunit

# SQLite / MySQL / PostgreSQL 实连；进程环境变量覆盖 .env
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql tests/orm_live_verify.php
```

MySQL/PostgreSQL 推荐使用独立 Docker 测试实例，例如 `mysql:8.4`、`postgres:16-alpine`。将 `DB_MYSQL_HOST/PORT/NAME/USER/PASSWORD`、`DB_PGSQL_HOST/PORT/NAME/USER/PASSWORD` 指向实例映射端口即可，完整启动示例见 [README](README.md#docker-复验)。`orm_live_verify.php` 使用随机表名，只清理本次创建的两张表；SQLite 兜底通过不能算真实 MySQL/PostgreSQL 通过。

生成器另有 `tests/SchemaLiveTest.php`，只有显式设置 `PIXIAOKA_ORM_LIVE_MYSQL_DSN`、`PIXIAOKA_ORM_LIVE_PGSQL_DSN` 及对应 `_USER`、`_PASSWORD` 才连接外部数据库；默认跳过，不隐式读取本机服务或 `.env`。该测试使用原生预处理，覆盖主键、默认值、时间类型、生成文件 lint 和元数据，完整示例见 [README](README.md#模型生成器实连测试)。

本次默认套件为 191 tests / 669 assertions；显式配置专用 MySQL/PostgreSQL 后为 191 tests / 717 assertions，零跳过。此前复杂查询内核已通过 SQLite 55 项、Docker MySQL 54 项、Docker PostgreSQL 57 项实连检查。

## 15. 项目集成示例

当前项目使用 PostgreSQL（见 `config/database.php`），一个典型的模型迁移：

```php
// app/model/Product.php —— 旧方式（继承 BaseModel）
// ↓ 改为 Orm Entity ↓

namespace app\model;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;

final class Product extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public int $category_id;

    #[Column]
    public string $price;  // 用 BcDecimal 可改为: public BcDecimal $price;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'pc_product';  // 注意 config/database.php 中 prefix 为 'pc_'
    }
}
```

webman 中间件中初始化（伪代码）：

```php
namespace app\middleware;

use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class OrmMiddleware implements MiddlewareInterface
{
    private \PDO $pdo;

    public function __construct()
    {
        // webman 常驻进程，构造一次即可
        $config = config('database')['pgsql'];
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['db']}";
        $this->pdo = new \PDO($dsn, $config['username'], $config['password']);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function process(Request $request, callable $handler): Response
    {
        Session::use(new EntityGateway(
            connection: new PdoConnection($this->pdo),
        ));

        try {
            return $handler($request);
        } finally {
            Session::discard();
        }
    }
}
```

控制器中使用：

```php
namespace app\controller;

use app\model\Product;
use Webman\Http\Response;

class ProductController
{
    public function detail(int $id): Response
    {
        $product = Product::find(['id' => $id]);
        if (!$product) {
            return json(['code' => 404, 'msg' => '商品不存在']);
        }
        return json(['code' => 0, 'data' => $product->toArray()]);
    }

    public function list(): Response
    {
        $products = Product::query()
            ->where('category_id', 1)
            ->orderBy('created_at', 'DESC')
            ->limit(20)
            ->get();

        return json(['code' => 0, 'data' => array_map(fn($p) => $p->toArray(), $products)]);
    }
}
```