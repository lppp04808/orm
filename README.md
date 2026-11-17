# pixiaoka/orm

Annotation-driven Active Record ORM for PHP 8.3+. Zero framework dependency, PDO based.

## Requirements

- PHP >= 8.3
- `ext-pdo`（任意驱动：sqlite / pgsql / mysql）
- 测试需要 `ext-pdo_sqlite`

## Install

```bash
composer require pixiaoka/orm
```

## Quick Start

```php
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

$pdo = new PDO('pgsql:host=...;port=5432;dbname=...', 'user', 'pass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 普通 PHP-FPM：请求开始登记，请求结束清理。
Session::use(new EntityGateway(
    connection: new PdoConnection($pdo),
));
try {
    // 业务操作
} finally {
    Session::discard();
}

// 常驻进程、Fiber/协程：显式作用域，异常时也自动恢复或清理。
Session::strict();
$result = Session::scope(new EntityGateway(new PdoConnection($pdo)), function () {
    // 当前上下文独占此连接；连接可由应用或可选连接池提供。
    return 'done';
});
```

### Define a model

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;

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
```

### Generate models from a database

模型生成能力由库自身提供，不依赖 webman、Symfony Console 或框架辅助函数。安装后可通过 Composer 暴露的 `pixiaoka-orm` 命令读取 SQLite、MySQL 或 PostgreSQL 的表结构：

```bash
vendor/bin/pixiaoka-orm generate \
    --dsn='sqlite:/absolute/path/database.sqlite' \
    --dir='/absolute/path/generated-models' \
    --namespace='App\\Model'

# MySQL / PostgreSQL 示例；密码也可通过 ORM_DB_PASSWORD 环境变量传入
vendor/bin/pixiaoka-orm generate \
    --dsn='mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4' \
    --user=app --password=secret \
    --dir='/absolute/path/generated-models' --namespace='App\\Model' \
    --prefix=pc_ --force user user_item
```

`--dsn`、`--dir` 和 `--namespace` 必填；不指定表名时生成全部用户表。带值选项同时支持 `--dsn=value` 和 `--dsn value`，缺值、重复或未知选项会明确失败。`--user`、`--password` 也可分别通过 `ORM_DB_USER`、`ORM_DB_PASSWORD` 提供。`--prefix` 只用于去掉类名前缀，不改变真实表名；已存在的文件默认跳过，只有显式 `--force` 才覆盖。生成器会拒绝不安全的数据库标识符、非法命名空间、根目录/当前目录等不明确输出位置，以及无法写入的目标文件。

生成模型继承 `Pixiaoka\Orm\Entity`，使用 `#[Column]`、`#[PrimaryKey]`；自增单列主键使用 `generated: true`，复合主键保留数据库声明的列顺序。无主键表会被拒绝，因为无法生成可用的 Active Record；批量生成会先读取并渲染校验全部表，再创建目录和写文件。PHP 8.3 不允许对象作为属性默认值，因此 Decimal、DateTime 和数据库动态表达式不会被生成为非法初始化代码。

需要集成到其他应用时，可直接使用公开 API：

```php
use Pixiaoka\Orm\ModelGenerator;
use Pixiaoka\Orm\Schema\SchemaInspector;

$generator = new ModelGenerator($pdo);
$result = $generator->generate(
    directory: '/absolute/path/generated-models',
    namespace: 'App\\Model',
    prefix: 'pc_',
    force: false,
    tables: ['pc_user'], // 空数组表示全部用户表
);
// $result = ['generated' => ['pc_user' => '/.../User.php'], 'skipped' => []]

$tableSchema = (new SchemaInspector($pdo))->table('pc_user');
$code = $generator->render($tableSchema, 'App\\Model'); // 仅返回代码，不写文件
```

时间映射以当前 ORM 的无损读取能力为边界：SQLite 无精度 `datetime/timestamp`、PostgreSQL 无精度 `timestamp without time zone` 映射为 `DateTimeImmutable + #[Temporal]`；`DATE`、`TIME`、带时区、带小数秒的时间以及 MySQL `datetime/timestamp` 保守映射为 `string`。默认值按驱动方言处理：MySQL 元数据是已解码文本，SQLite/PostgreSQL 保留 SQL 字面量；PostgreSQL `E'...'` 使用独立转义解析。Unicode 转义按码点生成 UTF-8，原始高位字节按服务器编码校验和转换；非 UTF-8 编码下混合两类字节、SQL_ASCII、未知编码或非法序列不会猜测默认值，而是使用安全的 PHP 属性兜底值。无法无损静态表达的默认值不会写入 PHP 属性。

### 可配置的创建时间与更新时间

时间字段不再只能叫 `created_at`、`updated_at`，也不要求是 `int`。用 `#[CreatedAt]` 和 `#[UpdatedAt]` 标记实际的持久化字段：

```php
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\UpdatedAt;

#[Column]
#[CreatedAt]
public DateTimeImmutable $created_on;

#[Column(name: 'modify_time')]
#[UpdatedAt]
public int $modify_time;

#[Column]
#[CreatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $created_unix;
```

`int` 自动写入 Unix 秒；`DateTimeImmutable` 默认写 UTC `Y-m-d H:i:s`，结合 `#[Temporal(Temporal::UNIX)]` 写 Unix 秒。创建时间仅在插入时填充；更新时间在 `save()` 更新时刷新，调用方显式修改过则不覆盖。插入、批量插入和 UPSERT 都遵守显式提供的时间值，UPSERT 默认不在冲突更新时覆盖创建时间。每个角色只能声明一次、不能共用同一字段，且不能标在主键上。传统 `int $created_at` / `int $updated_at` 不写新注解仍兼容；没有这些字段的表无需配置。

### Active Record

```php
// 插入：save() 看的是“这行是不是新的”，不是主键有没有值
$a = new Asset();
$a->user_id = 0;          // 0 / '' / false 都是合法键值
$a->key = '';
$a->val = 3;
$a->save();

// 按主键查（复合主键必须给全，少一列直接拒绝、不发 SQL）
$found = Asset::find(['user_id' => 0, 'key' => '']);

// 更新：只写脏字段（changed vs 快照）
$found->val = 9;
$found->save();

// 删除后 save 会重新插入
$found->delete();
```

### Query builder

```php
$users = Asset::query()
    ->where('user_id', 7)
    ->whereOp('qty', 'BETWEEN', [1, 5])        // 运算符白名单校验，非法直接抛异常
    ->orGroup(fn ($g) => $g->where('key', 'a')->where('key', 'b'))
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(20)
    ->get();

$first = Asset::query()->where('key', 'a')->first();   // 强制 LIMIT 1
$count = Asset::query()->where('user_id', 7)->count(); // 不受顶层 limit/offset 影响
```

### 复杂查询与数组结果

```php
use Pixiaoka\Orm\Query\JoinClause;

// JOIN + 自定义投影：rows() 返回原始数组，不构造缺列实体
$rows = Order::query()->alias('o')
    ->selectAs('o.sku', 'order_sku')
    ->selectAs('t.label', 'tag_label')
    ->leftJoin('order_tag', 't', fn (JoinClause $j) => $j
        ->on('o.id', '=', 't.order_id')
        ->where('t.label', '=', 'paid'))
    ->where('o.user_id', 7)
    ->rows();

// 聚合、分组和 HAVING；参数始终绑定
$stats = Order::query()
    ->select('user_id')
    ->aggregate('COUNT', '*', 'orders')
    ->aggregate('SUM', 'qty', 'total_qty')
    ->groupBy('user_id')
    ->having('SUM(qty)', '>=', 10)
    ->rows();

// 标量、IN、EXISTS 子查询；标量/IN 子查询必须显式只选一列
$paid = OrderTag::query()->select('order_id')->where('label', 'paid');
$orders = Order::query()->whereIn('id', $paid)->rows();

$top = Order::query()->selectAs('user_id', 'uid')->selectAs('qty', 'amount');
$rows = Order::query()->select('uid')->from($top, 'derived')
    ->whereOp('amount', '>', 5)->rows();

// union() 前的排序/分页属于首分支，之后的属于整个 UNION 顶层
$first = Order::query()->selectAs('sku', 'name')->orderBy('id')->limit(1);
$second = Order::query()->selectAs('sku', 'name')->where('user_id', 8);
$rows = $first->unionAll($second)->orderBy('name')->rows();
```

`get()/first()` 只用于返回完整实体的基表查询。JOIN、指定列、DISTINCT、聚合、GROUP/HAVING、UNION、派生表使用 `rows()`、`row()` 或 `value()`，返回数组或标量。复杂查询的 `count()` 统计完整结果集；各 UNION 分支自己的分页保留，顶层分页不计入。

### 条件写入、UPSERT 与行锁

```php
$affected = Order::query()->where('user_id', 7)->update(['qty' => 10]);
$affected = Order::query()->whereIn('id', [1, 2])->delete();

Order::upsert($values);                    // 默认主键冲突，更新其余输入列（created_at 除外）
Order::upsert($values, ['qty']);           // 冲突时只更新 qty，并自动维护 updated_at
Order::upsert($values, []);                // 冲突时忽略
Order::upsert($values, ['qty'], ['key']);  // 显式冲突键（MySQL 限制见下）

$order = Order::query()->where('id', 1)->forUpdate()->first(); // 应在事务中使用
```

条件 UPDATE/DELETE 必须有 WHERE，禁止修改主键以及 JOIN/GROUP/UNION/投影/排序/分页/锁等组合，返回受影响行数。成功后清理该模型的行缓存。条件 DELETE 通过 PostgreSQL/SQLite 的 `DELETE ... RETURNING` 获取实际删除主键；MySQL 先对当前 Session 已交付实体的身份做受限预查，再执行 DELETE。两种路径都按模型 Caster 规范化主键，只标删实际命中的存活对象（包括已被 LRU/批量写清退的对象）。MySQL 预查与外部并发写之间不提供跨 Session/进程的一致性保证，进程内 `isNew()` 状态也不是分布式锁；严格并发场景应使用事务、数据库约束和锁。

UPSERT 使用模型 Caster，自动填充缺失的 `created_at/updated_at`，默认冲突更新不覆盖创建时间。显式 `$updateColumns=['qty']` 仍会自动附加 `updated_at`（提供值则尊重显式值），`[]` 才表示冲突时完全忽略。不接收实体、不触发生命周期钩子、不承诺回填生成 ID。未提供的非空字段会提前拒绝；不会猜测 PHP 属性默认值或数据库 `DEFAULT`，这些字段须显式传值。PostgreSQL/SQLite 使用 `ON CONFLICT`，MySQL 使用 `ON DUPLICATE KEY UPDATE`。**MySQL 无法指定 conflict target，会响应表上的任意唯一键冲突**，所以显式 `$conflictColumns` 在 MySQL 中仅用于输入完整性校验。

PostgreSQL/MySQL 支持 `forUpdate()/forShare()`；SQLite 执行时明确拒绝，应使用事务。锁通常需要配合事务，不能保证数据库忽略锁语法时的并发正确性。

### 参数化 raw SQL

```php
$rows = Order::queryRaw(
    'WITH RECURSIVE seq(n) AS (
        SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < :last
     ) SELECT n FROM seq',
    ['last' => 10],
);

$rows = Order::query()->selectRaw('qty * :factor AS score', ['factor' => 2])
    ->whereRaw('qty > :minimum', ['minimum' => 3])->rows();
```

Raw 是受信任的**开发者 SQL** 逃生舱，适用于 CTE、递归 CTE、窗口函数和数据库专有表达式，不能拼接用户输入。仅接受命名占位符；缺参、多参、`?` 占位符、数组/对象参数均拒绝。字符串、标识符、注释中的 `:name`、PostgreSQL `::` cast，以及 `$$...$$` / `$tag$...$tag$` dollar-quoted 文本不会被当成参数。针对 `pdo_pgsql` 会误读 dollar-quoted 文本内伪参数的问题，`PdoConnection` 在预处理前将其转换为等价的转义字符串字面量，保留原始内容和其他真实绑定参数。完整 raw 查询始终返回数组/受影响行数，不自动 hydrate；raw 写入模型后应调用 `Model::flushCache()`。

### Batch insert / Transaction

```php
// 数组行
Asset::insertAll([
    ['user_id' => 1, 'key' => 'a', 'val' => 1],
    ['user_id' => 2, 'key' => 'b', 'val' => 2],
]);

// 事务：嵌套只在外层提交/回滚；协程上下文（Fiber/Swoole/Swow）事务状态互相隔离
Asset::transaction(function (): void {
    // ...
});
```

## Core concepts

| 概念 | 规则 |
|------|------|
| 字段来源 | 只有带 `#[Column]` 的类型化属性才是列；`#[Column(name: '...')]` 可改列名 |
| 主键 | `#[PrimaryKey]`；复合自然键用 `order: N`；代理键 `generated: true`（整组仅一个，不得与自然键混用） |
| 键值边界 | `0`、`''`、`false` 是合法键值；`null` 永远不是；身份不完整时拒绝执行、不发 SQL |
| 类型边界 | 转换只发生在网关进出（`Caster::toPhp/toDb`），PHP 原生类型在对象内部强制 |
| 支持类型 | `int` / `float` / `string` / `bool` / `array`(JSON) / `Decimal` 实现 / `BackedEnum` / `DateTimeImmutable`，及可空形式 |
| 时间存储 | `DateTimeImmutable` 默认 UTC `Y-m-d H:i:s` 文本；`#[Temporal(Temporal::UNIX)]` 存 Unix 秒 |
| 时间戳 | `int` 类型的 `created_at` / `updated_at` 自动填充；已提供的值不被覆盖 |
| 生成键回填 | pgsql/sqlite 走 `INSERT ... RETURNING`；mysql 单行走 `lastInsertId`，多实体批量回填抛 `IdentityException`（请逐行 insert） |
| 脏跟踪 | `find` 读出时快照；`save()` 更新时只生成变化字段的 SET 子句 |
| 行缓存 | 进程内 LRU（默认 1024 行）；同身份 `find` 返回同一实例；事务回滚只清事务内写入的 key |
| 钩子 | `beforeSave/afterSave/beforeInsert/afterInsert/beforeUpdate/afterUpdate/beforeDelete/afterDelete` |
| 方言 | 标识符引用按驱动分派：sqlite/pgsql 双引号，mysql 反引号；支持 `schema.table` |
| 复杂查询 | 结构化 JOIN / 投影 / 聚合 / GROUP / HAVING / 子查询 / 派生表 / UNION；CTE 与窗口函数走 raw |
| 参数绑定 | 每次 build 独立从 `q0` 编号；PDO 按 PHP 标量类型显式绑定，浮点数以十进制文本绑定 |

## Decimal

PHP 8.3 直接用内置的 `Pixiaoka\Orm\Cast\BcDecimal`（纯字符串、不丢精度）。
PHP 8.4 可以把 `BcMath\Number` 包一层实现 `Pixiaoka\Orm\Cast\Decimal` 接口：

```php
final class BcNumber implements Pixiaoka\Orm\Cast\Decimal
{
    public function __construct(private readonly BcMath\Number $n) {}
    public static function fromString(string|int $value): static { return new static(new BcMath\Number($value)); }
    public function __toString(): string { return (string) $this->n; }
}
```

## Coroutine safety

`Session` 使用 `WeakMap` 关联 Fiber 或运行时协程对象，不依赖可能被复用的数字 ID；事务上下文同样按对象身份隔离。`Session::strict()` 禁止未登记的 Fiber/协程隐式回退到根 Session，默认关闭以保持向后兼容。`Session::scope()` 在正常返回或异常时恢复先前 Session；也可省略回调并手动调用返回对象的 `close()`。

`Session::discard()` 会回滚当前网关在该上下文遗留的外层事务，然后解除上下文绑定；`Session::scope()` 关闭时先执行同样的清理，再恢复此前的 Session，即使回滚失败也会恢复绑定并透传异常。事务回调内清理 Session 后不得继续提交。同一个 `EntityGateway` 被两个 Fiber/协程同时用于事务会提前抛出 `LogicException`，避免把共享 PDO 误认为独立连接。

**核心库不绑定 webman、不创建或关闭 PDO，也不强制连接池。** PHP-FPM 每请求单连接无需池化；常驻进程和并发 Fiber/协程由应用或可选适配器负责租借、健康检查、重连及归还连接。嵌套 Fiber/协程必须明确调用 `Session::use()` 或 `Session::scope()` 选择自己的连接，不隐式继承父上下文事务。`SessionScope` 不允许跨上下文关闭。

SQLite 不允许多个未提交写事务并行；独立 PDO 的 Fiber 测试验证生命周期，不代表 SQLite 支持多个同时写事务。Swoole/Swow 的运行时对象适配仍需相应扩展环境验收，Fiber 测试不替代这些运行时的真实并发验证。

## Testing

```bash
composer install
composer analyse            # PHPStan 生产代码静态分析
composer test               # 211 tests / 730 assertions；2 项外部 DSN 测试默认跳过

# 实连脚本：进程环境变量覆盖 .env；两个真实库均不可达时自动 SQLite 兜底
php -d extension=pdo_sqlite -d extension=pdo_pgsql -d extension=pdo_mysql tests/orm_live_verify.php
```

本次验收结果：默认 SQLite 套件 211 tests / 730 assertions（含生成器、生命周期与真实 CLI smoke，2 项显式 DSN 测试跳过）；配置专用 MySQL 8.4、PostgreSQL 16（alpine）后完整套件 211 tests / 778 assertions，零跳过；独立实连脚本 MySQL 54 项、PostgreSQL 57 项，均通过。PHPStan 生产代码分析零告警；CI 配置 PHP 8.3/8.4/8.5 与双库服务测试。

### Docker 复验

请使用独立的临时数据库，不要指向生产库。以下端口是本机示例，可按实际映射修改：

```bash
docker run --name pixiaoka-orm-mysql-test \
  -e MYSQL_ROOT_PASSWORD=local-only-password -e MYSQL_DATABASE=orm_test \
  -p 127.0.0.1:32768:3306 -d mysql:8.4

docker run --name pixiaoka-orm-pgsql-test \
  -e POSTGRES_PASSWORD=local-only-password -e POSTGRES_DB=orm_test \
  -p 127.0.0.1:32770:5432 -d postgres:16-alpine

# 待容器健康检查通过后执行；以下环境变量优先于 .env
DB_PGSQL_HOST=127.0.0.1 DB_PGSQL_PORT=32770 \
DB_PGSQL_NAME=orm_test DB_PGSQL_USER=postgres DB_PGSQL_PASSWORD=local-only-password \
DB_MYSQL_HOST=127.0.0.1 DB_MYSQL_PORT=32768 \
DB_MYSQL_NAME=orm_test DB_MYSQL_USER=root DB_MYSQL_PASSWORD=local-only-password \
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql tests/orm_live_verify.php
```

脚本为每次运行创建带随机后缀的两张表，只删除本次登记的表。执行完成后请自行停止并删除上述专用容器；不要复用其他业务容器。SQLite 结果不能代替 MySQL/PostgreSQL 的真实执行结论。

### 模型生成器实连测试

`tests/SchemaLiveTest.php` 默认跳过外部数据库，不读取 `.env`、不隐式连接本机服务，也不会用 SQLite 冒充外部实连通过。启用 PDO 驱动并显式提供以下变量后执行：

```bash
PIXIAOKA_ORM_LIVE_MYSQL_DSN='mysql:host=127.0.0.1;port=32768;dbname=orm_test;charset=utf8mb4' \
PIXIAOKA_ORM_LIVE_MYSQL_USER=root PIXIAOKA_ORM_LIVE_MYSQL_PASSWORD=local-only-password \
PIXIAOKA_ORM_LIVE_PGSQL_DSN='pgsql:host=127.0.0.1;port=32770;dbname=orm_test' \
PIXIAOKA_ORM_LIVE_PGSQL_USER=postgres PIXIAOKA_ORM_LIVE_PGSQL_PASSWORD=local-only-password \
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql vendor/bin/phpunit
```

该测试关闭 PDO 预处理模拟，验证自增/复合主键、静态与动态默认值、时间类型、生成文件 `php -l` 和 ORM 元数据；只创建并清理随机测试表。

## License

MIT — see [LICENSE](LICENSE).
