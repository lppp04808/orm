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

### 乐观锁

用 `#[Version]` 显式标记一个非空 `int` 持久化字段。它必须同时有 `#[Column]`，每个模型最多一个，且不能兼任主键、生成列或自动时间字段。首次插入默认写 `1`，显式 `0` 保留；负数与 `PHP_INT_MAX` 拒绝，以保证后续原子递增。

```php
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Exception\OptimisticLockException;

final class Document extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $body;

    #[Column]
    #[Version]
    public int $revision;

    public static function table(): string { return 'document'; }
}

$document->body = 'new text';
try {
    $document->save();
    // SQL 原子执行 revision = revision + 1，WHERE 含完整主键与持久化快照旧版本。
} catch (OptimisticLockException $e) {
    // $e->model / operation / expectedVersion 可用于业务处理。
    $document->refresh(); // 是否刷新、合并及重试由应用决定；ORM 不自动覆盖冲突。
}
```

`delete()` 同样使用完整主键和旧版本。影响行数不为 `1` 时抛冲突异常，不推进内存版本、快照或缓存；无业务字段变化且无需写自动时间时，不为了递增版本执行 UPDATE。不能直接手改版本属性绕过检查。

带 `#[Version]` 的模型拒绝 Builder 条件 UPDATE/DELETE 及 UPSERT（含冲突忽略），因为没有逐实体旧版本快照，无法安全同步已交付对象。`executeRaw()` 是高级逃生舱：不会自动追加版本条件、递增版本或处理冲突，调用方负责 SQL 及后续 `refresh()` / `flushCache()`。

模型生成器只对非空、非主键且映射为 `int`、列名恰为 `version` 的字段自动附加 `#[Version]`。其他版本列名请显式声明，避免把普通业务字段隐式变成锁字段。

最外层事务回滚会恢复通过该 Gateway 读取、插入、更新或删除且仍存活的实体：字段、未初始化状态、`isNew()`、原始快照、版本和缓存对象身份。同主键的多个对象共享事务开始前的基线，恢复缓存时保留事务开始前的 canonical 对象，后查询的副本不会覆盖它。事务内新建实体恢复插入前状态，可重新插入；它的重复查询副本也会失效，不能凭已读取的生成主键绕过插入保护。

删除旧行后重插时，按数据库实际分配的身份恢复：复用旧主键的重插对象及重复查询副本恢复旧行基线；分配新主键的对象和副本按事务新增行失效。这里不恢复未纳入字段元数据的业务属性，也不能撤销钩子的外部副作用。

只有数据库确认回滚成功，才恢复内存并透传业务异常。begin 返回 `false` 或抛异常、commit 失败、rollback 失败，均按结果未知处理并抛 `TransactionException`，不能假设物理事务没有开始或提交。`$e->operation` 为 `开始`、`提交` 或 `回滚`；`businessException` 保存被基础设施异常覆盖的业务异常，`previous` 保留驱动异常。commit 异常不会被后续 rollback 异常覆盖，commit 已尝试后即使 rollback 成功也不恢复实体。

结果未知时禁止复用该 `Connection`，包括通过另一个 Gateway、原生 SQL 或 `Session::use()` 再次使用；应丢弃连接、Gateway 和关联实体。`Session::discard()` 仍可解绑失败 Session。驱动 begin 之前的连接所有权冲突属于确定的 `LogicException`，不会毒化连接。

### 只读关系与显式预加载

在 public 无参数实例方法上用 `#[HasOne]`、`#[HasMany]` 或 `#[BelongsTo]` 声明关系，返回类型使用对应的 `Pixiaoka\Orm\Relation` 类。`localKey` 是当前模型键，`foreignKey` 是目标模型键；均可使用属性名或真实列名。省略时使用该侧主键，复合键按数组顺序一一对应，两侧的 PHP 类型及时间存储格式必须一致。

```php
use Pixiaoka\Orm\Annotation\BelongsTo;
use Pixiaoka\Orm\Annotation\HasMany;
use Pixiaoka\Orm\Relation\BelongsTo as BelongsToQuery;
use Pixiaoka\Orm\Relation\HasMany as HasManyQuery;

final class Customer extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $name;

    public static function table(): string { return 'customer'; }

    #[HasMany(target: Order::class, foreignKey: ['customer_id'])]
    public function orders(): HasManyQuery { return $this->makeRelation(); }
}

final class Order extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public ?int $customer_id = null;

    public static function table(): string { return 'order'; }

    #[BelongsTo(target: Customer::class, localKey: ['customer_id'])]
    public function customer(): BelongsToQuery { return $this->makeRelation(); }
}

// 显式关系查询；不会自动设置 customer 实体的已加载关系状态。
$orders = $customer->orders()->get();
$paid = $customer->orders()->query(
    Order::query()->where('status', 'paid')->orderBy('amount', 'DESC'),
)->get();
$first = $customer->orders()->first(); // LIMIT 1；未指定排序时按目标主键升序选择

// 每层每个关系使用独立批量 SELECT，不用 JOIN 水合实体。
$customers = Customer::query()
    ->with('profile', 'orders')
    ->get();

$customers = Customer::query()->with([
    'orders' => fn ($query) => $query->where('status', 'paid'),
    'orders.customer',
])->get();

// 已读实体可显式加载或重新加载关系。
$customer->load('orders');
$customer->relationLoaded('orders');      // 区分未加载、null 和空数组
$orders = $customer->loadedRelation('orders'); // 未加载时抛异常，不隐式查库
```

关系约束只允许 WHERE/OR 和排序。显式关系查询保留用户排序优先级，并在尚未按目标主键排序时追加主键升序决胜条件；`hasOne` 多候选记录与 `belongsTo` 非唯一目标键由同一规则稳定选择，`first()` 仍只取第一行。预加载依次补齐目标关联键和目标主键，用户排序仍在前；与完整主键重叠的关联键不会重复追加排序。不按“每个父模型前 N 条”解释全局 LIMIT。预加载拒绝全局 `limit/offset`、投影、JOIN、GROUP/HAVING、UNION、派生表、DISTINCT、别名、行锁，以及约束闭包内再次 `with/load`。普通 `Builder::first()` 没有关系语义，不自动补主键排序。

关联键未初始化会拒绝查询；任一键为 `null` 时返回 `null` 或空数组，不执行关系 SQL。空主结果也不执行预加载。复合键采用参数化 OR-of-AND 元组条件，按最多 1000 个关系参数分批，不会把不同租户错误组合。

关系值不进入 `toArray()`，不参与字段 dirty tracking，也不会自动保存或删除。`save()` 保留已加载关系，`refresh()` 和 `delete()` 清空它们。当前只提供 `hasOne`、`hasMany`、`belongsTo`；不包含多对多、自动级联写入或隐式属性懒加载。关联过滤和计数见下一节，详细使用约束见 [USAGE](USAGE.md#关联过滤与计数)。

预加载不是数据库原子快照；需要一致快照时应在数据库事务和相应隔离级别内读取。普通 `query()` 仍返回独立对象副本，不是全局 identity map；单次关系图按目标主键去重，嵌套反向关系可复用本次主查询对象。关系图成功后才统一发布缓存和关系状态，失败会恢复字段、原值、关系和缓存身份；事务回滚同样恢复关系状态。刷新已缓存目标时保留本地未保存字段，只更新未修改字段及持久化原值；依赖已变化持久化关联键的旧关系会精确失效，本图待安装的新关系在成功后替换它，未受影响的关系继续保留。

实体首次进入 ORM 持久化/读取路径后，以弱引用记录其 `EntityGateway`。`discard()` 后重新登记同一个 Gateway，仍可继续 `save()`、`refresh()`、`delete()`、`load()` 和显式关系查询；切换到另一个 Gateway 则拒绝隐式跨连接，请在新连接上重新查询。实体数据库操作仍必须显式选择 Session；原 Gateway 已销毁后，数据库操作和 `toArray()` 明确报错，不会自动绑定新连接。静态模型查询和静态原生 SQL 由当前 Session 选择连接；`loadedRelation()` 只读已加载内存、不查询，无需 Session。

弱归属不等于整个对象图立即释放：Gateway 的 `RowCache` 强持有 LRU 内的实体，已加载关系也持有相关实体。连接池每次借出需要完全隔离时，应创建新的 Gateway；归还前丢弃该 Gateway、清理其缓存及外部实体/关系图引用，不能只释放一个 Entity 变量。

### 关联过滤与计数

```php
use Pixiaoka\Orm\Query\Builder;

$paidCustomers = Customer::query()
    ->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$withoutPaid = Customer::query()
    ->whereDoesntHave('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$largeOrderCustomers = Customer::query()
    ->whereRelation('orders', 'amount', '>=', 100)
    ->get();

$rows = Customer::query()
    ->withCount('orders')
    ->withCount(
        'orders',
        fn (Builder $q) => $q->where('status', 'paid'),
        'paid_orders',
    )
    ->orderBy('id')
    ->rows();
```

`whereHas()` 生成相关 `EXISTS`，`whereDoesntHave()` 对整个受约束关联集合取反。例如存在非 paid 订单、但同时存在 paid 订单的客户，不会出现在上面的 `$withoutPaid` 中。点分路径逐层关联，闭包只约束末级；单键、复合键、自关联及属性名/真实列名均使用已声明的关系元数据。约束支持结构化 WHERE、OR 及嵌套关联条件，不支持 Raw、任意 Builder 子查询、投影、JOIN、GROUP、UNION、排序、分页、锁或预加载，也不能在闭包内执行查询或写入。

`whereRelation($path, $column, $value)` 是等值过滤；四参数形式 `$path, $column, $operator, $value` 支持 NULL、IN、BETWEEN 等普通运算符语义。`withCount()` 追加相关 `COUNT(*)` 标量列，默认别名为 `orders_count`、嵌套路径为 `orders_customer_count`；同一路径可用不同别名分别计数，数组可一次声明多个路径及末级约束。结果必须经 `rows()`、`row()` 或 `value()` 读取，不写实体属性、关系状态或缓存。首版计数与关联过滤互斥，也不能水合实体、执行父 COUNT/条件写或与 `with()` 一起消费；普通 WHERE/OR 仍可组合。

关联功能直接父查询目前限模型单基表，可使用父表 alias、普通 WHERE/OR、排序和分页；普通 OR 与 `withCount()` 的调用顺序不限，但计数不能与 OR 分组内的关联过滤组合。`_orm_relation_N` 是内部关联别名：父表 alias 或关系涉及的真实表名与已分配内部别名冲突时会明确拒绝，不会静默生成错误相关条件。JOIN/GROUP/HAVING/UNION/派生表/DISTINCT 父查询明确拒绝。关联过滤可用于 `get()/first()/rows()/count()` 及不带乐观锁模型的条件 UPDATE/DELETE，但不会给独立 `with()` 隐式继承过滤条件。

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
```

`insertAll()` 可混合数组和 Entity，SQL 严格保留原始行序，并按最多 500 行及绑定参数上限分块。首条 INSERT 前会完成整批字段存在性、类型转换、版本值、实体新旧状态、模型和 Gateway 归属检查，以及 Entity 前置钩子与最终值物化；因此第 501 行等后续坏输入也不会让前一块先行写入。钩子可以补齐必填字段，每个 Entity 的前置钩子只执行一次。

预检失败不会给尚未绑定的 Entity 留下 Gateway 归属，也不会标记 persisted、执行后置钩子或发布缓存；已经执行的前置钩子、自动时间/版本填值及其外部副作用不由预检失败隐式撤销。所有 SQL 成功后按原始行索引回填生成键并执行后置钩子。数据库约束冲突、SQL/连接错误、生成键回填或后置钩子失败不承诺自动原子撤销；需要整批原子性时请使用显式事务。事务回滚会恢复实体在前置钩子及自动填值之前的持久化字段、未初始化状态、生成键、版本和缓存身份，但不撤销非字段业务属性或外部副作用。

MySQL 不支持 `INSERT ... RETURNING`，因此多行批次中只要含带生成键的 Entity，就会在运行钩子及 SQL 前拒绝，避免不可靠的批量 ID 回填；纯数组行仍可批量插入。

```php
// 事务：嵌套只在外层提交/回滚；协程上下文（Fiber/Swoole/Swow）事务状态互相隔离
Asset::transaction(function (): void {
    // ...
});
```

## 可观测性

每个 `EntityGateway` 独立订阅，无全局静态 Logger，不强制 Monolog、OpenTelemetry 或连接池。`subscribe()` 接收 `EventListener` 或 `callable(Event): void`，返回幂等取消订阅闭包。

```php
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;

$unsubscribe = $gateway->subscribe(function (Event $event): void {
    if ($event instanceof SqlEvent) {
        $metrics->observe($event->operation, $event->durationMs, $event->successful);
        // 可用：SQL 原文、模型类名、参数名、影响/结果行数、异常类名、上下文类型。
    }
    if ($event instanceof TransactionEvent && !$event->successful) {
        $logger->warning('ORM transaction failed', ['phase' => $event->phase]);
    }
});
```

- `SqlEvent`：SQL、操作类型、模型或 `null`、单调毫秒耗时、成功状态、参数名、影响/结果行数、异常类名、上下文信息。
- `TransactionEvent`：物理事务 `begin` / `commit` / `rollback`、耗时、成功状态及异常类名；嵌套事务不伪造物理事件。提交失败后尝试回滚时，提交失败和回滚结果分别记录。`rollback successful=true` 只表示 ORM 调用的回滚清理 API 成功，不证明先前失败的 commit 没有生效。
- `SessionEvent`：`bind` / `discard` / `restore`，包括清理是否成功。
- `ContextInfo`：root/fiber/coroutine 类型与进程内 `spl_object_id()`。该 ID 不是分布式追踪 ID，也不是跨请求、永不复用的持久身份。

事件不包含 SQL 参数值、查询结果行、实体、PDO/Connection/Gateway、原 Throwable、异常消息或堆栈。SQL 原文本身可能含调用方手写的字面量秘密，因此不要直接把 SQL 原文当已完全脱敏数据输出。

监听器彼此隔离，异常不改变 SQL 或已提交事务结果；默认不向 stdout/stderr 输出。同一 Dispatcher 重入时丢弃嵌套事件，避免采集递归。观测期间禁止重入同一连接执行 ORM 操作，也禁止通过 `Session::use()`、`Session::scope()` 或 `Session::discard()` 改变当前上下文的 Session 生命周期；独立连接的嵌套事件不会提前解除外层保护。

监听器可以用完全独立的 Gateway/Connection 直接写观测库，但不能借此切换或清理当前业务 Session。独立写入不加入业务事务，也不保证与业务事务原子提交。应用直接使用 PDO/Connection 或 `SchemaInspector` 的内部 PDO 查询不属于 ORM 重入保护和事件覆盖范围。

观测覆盖 Gateway 查询、实体写、批量插入、条件写和 raw SQL。默认无监听器时跳过事件构造与执行计时。可注入 `Clock` 供确定性测试使用。

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
| 行缓存 | 进程内 LRU（默认 1024 行）；同身份 `find` 返回同一实例；事务回滚恢复开始时 LRU 与 canonical 身份，再补齐缺失对象 |
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

`Session::use()` / `Session::scope()` 不会隐式结束旧 Session 的事务：当前上下文有未结束事务时直接抛 `LogicException`，即使传入同一个 Gateway 也一样；不回滚、不释放连接所有权，原 Session 保持不变。调用方必须先显式 `Session::discard()` 并处理回滚结果。`Session::discard()` 会回滚当前网关在该上下文遗留的外层事务，然后解除上下文绑定；`Session::scope()` 关闭时先执行同样的清理，再恢复此前的 Session，即使回滚失败也会恢复绑定并透传异常。事务回调内清理 Session 后不得继续提交。同一个 `EntityGateway` 被两个 Fiber/协程同时用于事务会提前抛出 `LogicException`，避免把共享 PDO 误认为独立连接。

**核心库不绑定 webman、不创建或关闭 PDO，也不强制连接池。** PHP-FPM 每请求单连接无需池化；常驻进程和并发 Fiber/协程由应用或可选适配器负责租借、健康检查、重连及归还连接。嵌套 Fiber/协程必须明确调用 `Session::use()` 或 `Session::scope()` 选择自己的连接，不隐式继承父上下文事务。`SessionScope` 不允许跨上下文关闭。

SQLite 不允许多个未提交写事务并行；独立 PDO 的 Fiber 测试验证生命周期，不代表 SQLite 支持多个同时写事务。Swoole/Swow 的运行时对象适配仍需相应扩展环境验收，Fiber 测试不替代这些运行时的真实并发验证。

## Testing

```bash
composer install
composer analyse            # PHPStan 生产代码静态分析
composer test               # PHP 8.3：331 tests / 10750 assertions；2 项外部 DSN 测试默认跳过

# 实连脚本：进程环境变量覆盖 .env；两个真实库均不可达时自动 SQLite 兜底
php -d extension=pdo_sqlite -d extension=pdo_pgsql -d extension=pdo_mysql tests/orm_live_verify.php
```

本次关联过滤与计数阶段的本机 PHP 8.3 默认 SQLite 套件为 331 tests / 10750 assertions，2 项显式 DSN 测试跳过；独立 SQLite 实连通过 130 项、零失败，并验证 1001 个不同复合关系键分三批查询及测试表元数据清理。显式双库 DSN 下完整套件为 331 tests / 10798 assertions、无跳过；MySQL 8.4 实连通过 102 项、PostgreSQL 16 通过 105 项，均零失败。Docker PHP 8.3.36、8.4.27、8.5.12 均完成同一默认套件。PHPStan 生产代码分析零告警。CI 继续核验 PHP 8.3/8.4/8.5、Composer、lint 与双库服务测试；Swoole/Swow 真实扩展验收未执行，Fiber 测试不代替这些运行时验证。

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

脚本为每次运行创建带随机后缀的独立测试表，按 PDO 登记并只删除该连接创建的表。执行完成后请自行停止并删除上述专用容器；不要复用其他业务容器。SQLite 结果不能代替 MySQL/PostgreSQL 的真实执行结论。

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
