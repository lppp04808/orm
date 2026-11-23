# ORM 内核使用指南

English: [USAGE.md](./USAGE.md) | 首页：[README.zh-CN.md](./README.zh-CN.md)

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

// 也可省略回调，手动管理作用域；必须在同一个 Context 中关闭。
$scope = Session::scope(new EntityGateway(new PdoConnection($pdo)));
try {
    // 业务操作
} finally {
    $scope->close();
}
```

作用域不关闭 PDO，应用负责创建、重连、健康检查及归还。`Session::discard()` 会回滚当前上下文遗留的整条受管事务链并解除绑定；`Session::scope()` 关闭时先执行同样的清理，再恢复此前的 Session，即使回滚失败也会恢复绑定并透传异常。事务回调内清理 Session 后不能继续提交。

同一个 `EntityGateway` 从绑定成功到 `discard()` / 作用域释放为止，整个活跃读写生命周期都只能属于一个 Context，不限于事务期间。另一个 Fiber/协程尝试绑定会提前抛出 `LogicException`，避免覆盖 `lastInsertId()` 或交错异步驱动协议包。同一 Context 的重复绑定采用引用计数，嵌套作用域会恢复外层归属。

### 命名会话与多数据源

```php
Session::use($writerGateway);                 // 默认名 default
Session::use($readerGateway, 'readonly');
try {
    $reader = Session::get('readonly')->gateway;
    // 模型静态 API 默认选择 default；显式 Builder 可选择其他数据源。
    $rows = (new \Pixiaoka\Orm\Query\Builder(User::class, $reader))
        ->where('active', true)
        ->get();

    // default 临时切到 reader，正常返回或异常都恢复 writer。
    $count = Session::scope($readerGateway, fn (): int => User::query()->count());

    Session::discard('readonly');             // 只清理 readonly
} finally {
    Session::discard(null);                    // 清理当前 Context 的全部命名会话
}
```

`use()`、`get()` 与 `scope()` 的 `$name` 默认都是 `'default'`；`discard()` 省略参数只清理 `default`，只有显式 `null` 才清理全部。命名会话分别维护事务与回滚状态，跨数据源的实体不能隐式换绑。多个会话需要各自独立的 PDO/Gateway，不能借此共享一个并发物理连接。

### ManagedConnection 与连接池

`PdoConnection` 实现可选的 `ManagedConnection`，除基础 SQL 契约外还提供：

- `ping(): bool`：执行轻量存活探测，断线或失败返回 `false`，不向连接池泄漏驱动异常；
- `forceRollback(): bool`：回滚整条物理事务链，成功后把嵌套深度重置为零；
- 嵌套事务：最外层用原生 PDO 事务，后续层级用 SAVEPOINT。

连接池可在借出或归还时调用 `ping()`，但归还前必须先通过 `Session::discard()` 正常清理 ORM 上下文。`ping()` 不证明未提交事务的结果，`forceRollback()` 也不能被用来掩盖 commit/rollback 结果未知；结果未知时仍应废弃连接、Gateway 和关联实体。只实现旧 `Connection` 的装饰器无需添加这两个方法，但它没有物理 SAVEPOINT 能力，嵌套事务行为见[事务](#9-事务)。

`Session` 使用 `WeakMap` 关联 Fiber 或运行时协程对象，不依赖可能被复用的数字 ID；事务上下文同样按对象身份隔离。`Session::strict()` 禁止未登记的 Fiber/协程隐式回退到根 Session，默认关闭以保持向后兼容。`Session::scope()` 在正常返回或异常时恢复先前 Session；省略回调后返回的 `SessionScope` 必须手动 `close()`，且不允许跨上下文关闭。

**webman 只是可选适配方式**，可在应用中间件中为每个请求调用上述作用域；不要把根 PDO 隐式共享给所有 Fiber。嵌套上下文必须显式选择连接，不隐式继承父上下文事务。PHP-FPM 每请求单连接无需池化；常驻进程和并发 Fiber/协程由应用或可选适配器负责连接租借与归还。SQLite 不允许多个未提交写事务并行；独立 PDO 的 Fiber 测试验证生命周期，不代表 SQLite 支持多个同时写事务。Swoole/Swow 仍应在真实扩展环境验证，不能仅以 Fiber 测试替代。

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
| `BackedEnum` | 枚举的 int/string 值 | `public SampleStatus $status;` |
| `Castable` 实现 | 自定义标量表示 | `public Money $price;` |
| `DateTimeImmutable` | `Y-m-d H:i:s` UTC | `#[Temporal] public DateTimeImmutable $at;` |
| 可空 `?` 形式 | NULL | `public ?string $desc;` |

数组按 JSON 编解码，非法 JSON 或未知枚举值会抛 `CastException`。`BackedEnum` 只支持 int/string backed enum，不接受纯 enum。自定义值对象实现 `Castable`：

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Cast\Castable;

final readonly class Money implements Castable
{
    public function __construct(public int $cents) {}

    public function toDatabase(): int
    {
        return $this->cents;
    }

    public static function fromDatabase(int|float|string $value): static
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('金额格式错误');
        }
        return new self((int) $value);
    }
}

// 以下属性位于 Entity 模型类内部：
#[Column]
public Money $price; // 数据库保存整数分，PHP 保存 Money
```

`toDatabase()` 可返回 int/float/string/null；非空字段仍按字段可空性约束。`fromDatabase()` 接收数据库标量，读写失败由 Caster 统一包装为 `CastException`。实现类应不可变或自行保证一致性，不要依赖对象中的瞬态属性表达持久化值。

### Decimal

PHP 8.3 可直接使用内置的 `Pixiaoka\Orm\Cast\BcDecimal`（纯字符串、不丢精度）。PHP 8.4 及以上也可以将 `BcMath\Number` 包装为 `Pixiaoka\Orm\Cast\Decimal` 接口实现：

```php
final class BcNumber implements Pixiaoka\Orm\Cast\Decimal
{
    public function __construct(private readonly BcMath\Number $n) {}

    public static function fromString(string|int $value): static
    {
        return new static(new BcMath\Number($value));
    }

    public function __toString(): string
    {
        return (string) $this->n;
    }
}
```

Decimal 与 `Castable` 一样在网关边界转换；字段按规范化数据库表示比较，等值新实例不会触发无意义 UPDATE。

### 值对象的语义脏跟踪

实体更新与关系刷新对对象字段按规范化数据库表示进行语义比较：等值的新 `Money`、`DateTimeImmutable`、Decimal 对象不会被误判为脏字段，也不会因此产生多余 UPDATE 或递增 `#[Version]`。不同时区但同一时刻的时间按其实际存储格式比较。绑定给 Builder 的 Castable 也经同一 Caster 规范化。

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
#[CreatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $created_unix; // 创建时间也可存 Unix 秒

#[Column]
#[UpdatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $updated_unix; // 更新时间同样支持 Unix 秒
```

插入时填充两者，更新时只刷新更新时间。调用方显式修改过更新时间则不覆盖；创建时间不会在普通更新或 UPSERT 冲突更新中被覆盖。插入、批量插入和 UPSERT 都支持自动填充。每个角色只能声明一次、不能共用同一字段、不能标在主键上；不支持其他 PHP 类型。没有这些字段的模型无需配置。

模型生成器会为传统的 `created_at` / `updated_at` 自动附加时间角色注解，但仅当它们映射为 `int` 或可无损读取的 `DateTimeImmutable` 时；其他列名需自行添加上述注解。

### 乐观锁：#[Version]

```php
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Exception\OptimisticLockException;

#[Column]
#[Version]
public int $revision;

try {
    $order->save(); // UPDATE 同时带完整主键与旧版本条件，数据库执行 revision = revision + 1
} catch (OptimisticLockException $e) {
    // 可读取 model / operation / expectedVersion；不会自动刷新、覆盖或重试。
    $order->refresh();
}
```

版本字段必须同时声明 `#[Column]`，类型是非空 `int`，每模型仅一个，不能兼任主键、生成列或自动时间字段。插入默认版本 `1`，显式 `0` 保留；负数与 `PHP_INT_MAX` 拒绝。删除同样带版本条件。影响行数不是 `1` 时抛异常，内存版本、快照和缓存不提前推进；无业务变化时不执行无意义的版本递增 UPDATE。不能手改版本属性绕过检查。

带 `#[Version]` 的模型不支持 Builder 条件 UPDATE/DELETE 或 UPSERT（包括冲突忽略），这些操作缺少逐实体旧版本快照。`executeRaw()` 不自动加版本条件或处理冲突，需由调用方控制 SQL、影响行数以及 `refresh()` / `flushCache()`。生成器只对非空、非主键、列名恰为 `version` 的 `int` 字段自动添加 `#[Version]`，其他字段名请显式添加。

### 软删除：#[SoftDelete]

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Temporal;

#[Column]
#[SoftDelete]
#[Temporal]
public ?DateTimeImmutable $deleted_at = null;
```

软删除字段声明位于 Entity 模型类内部，必须同时有 `#[Column]`，类型为可空 `DateTimeImmutable`，且不能兼任主键、生成列、版本字段或自动时间字段；每个模型最多一个。`NULL` 表示未删除，时间统一使用秒级 UTC，单实体与批量软删除后的内存值与数据库刷新值一致。

```php
$visible = Article::query()->get();                    // 默认 deleted_at IS NULL
$all = Article::query()->withTrashed()->get();          // 包含回收站
$deleted = Article::query()->onlyTrashed()->get();      // 仅回收站

$article->delete();                                    // 软删除
$article->restore();                                   // 实体恢复
$article->forceDelete();                               // 实体物理删除

$n = Article::query()->where('tenant_id', 7)->delete();
$n = Article::query()->onlyTrashed()->where('tenant_id', 7)->restore();
$n = Article::query()->withTrashed()->where('id', 7)->forceDelete();
```

Builder 的 `delete()` 在声明软删除的模型上写时间戳，`restore()` 只恢复符合当前条件的已删除行，`forceDelete()` 才物理删除。默认 `delete()` / `forceDelete()` 不包含已软删除行，需要时用 `withTrashed()` 或 `onlyTrashed()` 明确范围。软删除范围本身就是写入边界，所以即使没有其他显式 WHERE，也不会像普通模型 DELETE 一样被拒绝；`restore()` 也只针对回收站。特别地，`withTrashed()->forceDelete()` 且没有其他条件可能物理清空整表，应谨慎使用。`restore()` / `forceDelete()` 对未声明软删除的模型会拒绝执行。批量写不触发实体钩子，必要时刷新已持有对象。

普通查询、关系加载、关系过滤与计数都应用目标模型的软删除范围。关系约束闭包中可显式调用 `withTrashed()` 包含目标回收站；软删除不是关联加载的额外全局功能开关，而是目标模型元数据的一部分。

## 3. 只读关系与预加载

### 声明关系

关系注解和查询类同名但命名空间不同，可使用别名避免冲突：

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
```

关系方法必须是 public、无参数、非 static，并精确声明具体关系查询返回类型。`localKey` 属于当前模型，`foreignKey` 属于目标模型；省略一侧使用该侧主键。支持属性名/真实列名及复合键，键按数组位置对应，不允许重复或类型不匹配。

### 显式查询与预加载

```php
// 单独查询关系，不隐式修改源实体的 loadedRelation 状态。
$orders = $customer->orders()->get();
$latestPaid = $customer->orders()->query(
    Order::query()->where('status', 'paid')->orderBy('id', 'DESC'),
)->first(); // LIMIT 1

// 一次主查询 + 每层每个关系一次/多批独立 SELECT。
$customers = Customer::query()->with('profile', 'orders')->get();
$customers = Customer::query()->with([
    'orders' => fn ($q) => $q->where('status', 'paid'),
    'orders.customer',
])->get();

$customer->load('orders');
if ($customer->relationLoaded('orders')) {
    $orders = $customer->loadedRelation('orders');
}
```

`hasMany()` 的 `get()` 返回实体列表；`hasOne()`、`belongsTo()` 的 `get()` 返回实体或 `null`。三者 `first()` 都返回实体或 `null`。`loadedRelation()` 未加载时抛异常，不会隐式执行 SQL。关联键未初始化会拒绝查询；任一侧关联键为 `null` 时，结果为 `null` 或空数组，且不执行关系 SQL。空主结果也不执行预加载。复合键使用参数化 OR-of-AND 元组条件，按每批最多 1000 个关系参数分批，不会把不同父记录的键错误组合。

显式关系查询保留用户排序，尚未按目标主键排序时追加目标主键升序决胜条件；`hasOne` 多候选记录与 `belongsTo` 非唯一目标键由同一规则稳定选择，`first()` 仍只取第一行。预加载依次补齐目标关联键和目标主键，用户排序仍在前；与完整主键重叠的关联键不会重复追加排序。关系注解同类重复或混合声明均在首次进入关系功能时抛 `MetadataException`；普通 CRUD 不扫描关系注解。预加载约束只允许 WHERE/OR 和排序，拒绝全局分页、投影、JOIN、GROUP/HAVING、UNION、派生表、DISTINCT、表别名、行锁和闭包内再次 `with/load`。`with()` 只能用于实体 `get()` / `first()`，不能被 `rows()`、`value()`、`count()` 或条件 UPDATE/DELETE 静默忽略。普通 `Builder::first()` 不自动补主键排序。

### 对象、事务和连接边界

- 关系不进入 `toArray()`、dirty tracking 或自动保存/删除；`save()` 保留关系，`refresh()` / `delete()` 清空。
- 普通 `query()` 返回独立副本；只有单次关系图按主键去重，嵌套反向关系可指向本次主查询父对象。
- 关系图成功后才统一安装关系并发布缓存，失败恢复字段、原值、关系及缓存；物理事务回滚也恢复这些状态。
- 刷新缓存目标保留未保存字段；依赖已变化持久化关联键的旧关系会精确失效，本图待安装的新关系会在成功后替换它，未受影响的关系继续保留。需要丢弃本地修改时，应显式 `refresh()`，不能依赖关系加载覆盖。
- 预加载是多条 SELECT，不是数据库原子快照；一致性要求高时放入事务并选择适当隔离级别。
- 实体以弱引用记录首次读取/持久化它的 Gateway。`discard()` 后重新登记同一个 Gateway，仍可继续保存、刷新和加载关系；切到另一个 Gateway 则拒绝隐式读写，应显式重查。实体数据库操作仍必须显式选择 Session。原 Gateway 已销毁时，数据库操作和 `toArray()` 会明确报错，不能自动绑定新连接；`loadedRelation()` 只读已加载内存且不查询，无需 Session。
- 弱归属不等于整个对象图都能立即释放：Gateway 的 `RowCache` 会强持有 LRU 内的实体，已加载关系也会持有相关实体。连接池每次借出需要完全隔离时，应创建新的 Gateway；归还前应丢弃该 Gateway、清理其缓存及外部实体/关系图引用，不能只释放一个 Entity 变量。原 Gateway 被销毁后的旧实体只能在新连接上显式重查。
- 当前不包含 `belongsToMany`、自动级联写入或属性懒加载；关联过滤和计数见下一节。

### 关联过滤与计数

```php
use Pixiaoka\Orm\Query\Builder;

$customers = Customer::query()
    ->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$withoutPaid = Customer::query()
    ->whereDoesntHave('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$customers = Customer::query()
    ->whereRelation('orders', 'status', 'paid')           // 三参数：column = value
    ->get();
$customers = Customer::query()
    ->whereRelation('orders', 'amount', '>=', 100)       // 四参数：column operator value
    ->get();

$rows = Customer::query()
    ->withCount('orders') // orders_count
    ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
    ->orderBy('id')
    ->rows();

$rows = Customer::query()
    ->withCount(['orders', 'orders.customer']) // orders_count、orders_customer_count
    ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
    ->rows();
```

`whereHas()` 和 `whereDoesntHave()` 分别生成相关 `EXISTS` / `NOT EXISTS`，不会读取关联实体或设置 `loadedRelation()`。否定条件针对整个受约束集合：同时拥有 paid 和 new 订单的客户，不匹配“不存在 paid 订单”。点分路径逐层相关，闭包只约束末级，例如 `orders.customer` 只统计/筛选有对应 Customer 的 Order，NULL 外键和孤儿外键不计入。复合键按声明位置配对，自关联每层使用独立内部别名。

`whereRelation()` 是 `whereHas()` 的单字段快捷方式；三参数始终按等值处理，即使值看起来像 `IN`、`=` 等运算符。四参数可使用普通结构化比较、NULL、数组 IN/NOT IN 和 BETWEEN，值经过目标模型 Caster。直接父查询可为单基表并带表 alias、普通 WHERE/OR、排序、分页；JOIN/GROUP/HAVING/UNION/派生表/DISTINCT 父查询不支持。`_orm_relation_N` 是 ORM 内部关联别名，父表 alias 或关系涉及的真实表名与已分配内部别名冲突时会明确拒绝，避免同名引用静默错数。

`withCount()` 以相关 `COUNT(*)` 标量子查询添加原始输出列，不水合实体、不修改实体字段、关系状态或缓存，不产生 N+1 查询。默认别名是路径点替换为下划线再加 `_count`；相同路径可用不同别名重复计数，数组可声明多个路径和末级约束。最终输出名与默认模型列、显式投影或其他计数别名冲突会在构建 SQL 时拒绝。计数结果必须通过 `rows()`、`row()` 或 `value('orders_count')` 读取，不承诺 PDO 原始类型一定是 PHP int。

关联过滤和计数约束只允许结构化 WHERE/OR、嵌套关联过滤；支持属性名和真实列名，约束列由当前关系自动限定。不支持 whereRaw/Expression、任意普通 Builder 子查询、显式表前缀、投影、withCount、JOIN、GROUP/HAVING、UNION、派生表、DISTINCT、alias、排序、limit/offset、行锁或 with。闭包必须返回 `null` 或传入的同一个 Builder；不得在闭包或其中的 OR 分组内执行 get/first/rows/row/value/count/build/update/delete 等终结操作。非法约束在任何 SQL 之前拒绝。

`whereHas()/whereDoesntHave()/whereRelation()` 可用于实体查询、原始行、父 COUNT 和条件 UPDATE/DELETE（仍遵守乐观锁等原有写入限制）；可与独立 `with()` 组合，过滤不会变成预加载约束。首版 `withCount()` 与关联过滤互斥，不能用于 `get()/first()/count()`、条件 UPDATE/DELETE 或 `with()`。普通 WHERE/OR 与计数的两种调用顺序均可；计数与 OR 分组内的关联过滤仍互斥。目标模型声明软删除时，关联过滤与计数默认只统计未删除目标，关系约束中可用 `withTrashed()` 显式放宽。这里不包含 `has()` 的数量比较、关联写入或多对多。

## 4. Active Record

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

## 5. 查询构建器

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

// 克隆复用：两个 Builder 的查询结构互不影响。
$adults = User::query()->whereOp('age', '>=', 18);
$firstPage = (clone $adults)->orderBy('id')->limit(20);
$admins = (clone $adults)->where('role', 'admin');
```

`clone` 会递归隔离 WHERE/OR、子查询、JOIN、UNION、派生表、排序和分页等 ORM 查询状态；`first()`、`row()`、`value()` 与复杂 `count()` 也通过克隆避免污染原查询。绑定值中的 `DateTimeImmutable`、枚举、Decimal 等业务对象不克隆，调用方应避免修改共享的可变值对象。内部 `QueryState`、SQL/关系编译器不是公共扩展接口，流式 Builder 才是稳定使用入口。

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

## 6. 复杂查询

### 6.1 结果类型

`get()` / `first()` 只返回完整实体。自定义投影、JOIN、聚合、GROUP/HAVING、UNION、派生表的结果必须走：

- `rows()`：数组列表；
- `row()`：一行数组或 `null`；
- `value()`：第一个字段或指定字段的标量。

ORM 不会把缺列数组强行 hydrate 成实体，也不会把部分实体写进行缓存。原始数组不会自动转为 `Decimal`、`DateTimeImmutable` 等 PHP 对象。

### 6.2 投影、JOIN 和表别名

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

### 6.3 聚合、DISTINCT、GROUP BY / HAVING

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

### 6.4 子查询与派生表

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

### 6.5 UNION / UNION ALL

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

### 6.6 Raw SQL：CTE、窗口函数和专有表达式

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

## 7. 条件 UPDATE / DELETE、UPSERT 与行锁

### 7.1 条件批量写入

```php
$affected = Order::query()->where('user_id', 7)->update(['qty' => 10]);
$affected = Order::query()->whereIn('id', [1, 2])->delete();
```

必须有 WHERE。空 IN 仍保留为恒假条件，不会退化为全表写；空 SET、主键修改、JOIN/GROUP/HAVING/UNION/投影/排序/分页/锁均拒绝。成功执行后清理该模型缓存，命中 0 行不是异常。

PostgreSQL/SQLite 使用 `DELETE ... RETURNING` 读取实际删除主键；MySQL 先对当前 Session 已交付实体的身份做受限预查，再执行 DELETE。两者都按模型 Caster 规范化主键，只把实际删除的对象标为未入库；对象即使已被 LRU 或批量写清出缓存，仍能通过弱引用身份表精确失效，不会误标同表其他实体。条件 UPDATE/UPSERT 不会更新调用方已经持有的旧实体对象，必要时请 `refresh()` 或重新查询。批量写不触发生命周期钩子。

MySQL 预查与外部并发写之间不提供跨 Session/进程的一致性保证，`isNew()` 状态也不是分布式锁。需要严格并发一致性时，请使用事务、数据库约束和行锁。

### 7.2 跨方言 UPSERT

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

### 7.3 行锁

```php
Order::transaction(function (): void {
    $order = Order::query()->where('id', 1)->forUpdate()->first();
    // 在事务内修改并保存
});

$shared = Order::query()->where('id', 1)->forShare()->first();
```

PostgreSQL/MySQL 支持 `FOR UPDATE/FOR SHARE`，Builder 按各自语法放置锁与分页子句。SQLite 不支持，执行时明确抛异常，不能静默忽略。通常应在事务内使用；子查询、派生表、UNION 和条件写不允许携带行锁。

## 8. 批量插入

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

数组与 Entity 可以混批，SQL 保留输入行序，每块最多 500 行，并同时受 65535 个绑定参数上限约束。首条 INSERT 前会完成整批可预知检查：数组字段和类型转换、实体模型/新旧状态/Gateway 归属、自动时间与版本默认值、前置钩子、钩子执行后的最终值和版本校验。后续分块存在坏行时，前面的分块不会先写入；失败批次也不会给未绑定 Entity 留下 Gateway 归属。

`beforeSave()` / `beforeInsert()` 可以补齐必填字段，每个 Entity 只执行一次；SQL 全部成功后才回填生成键、标记 persisted、执行 `afterSave()` / `afterInsert()` 并发布缓存。预检失败不假装撤销已经运行的前置钩子、字段自动填值或外部副作用。数据库约束冲突、SQL/连接失败、生成键回填或后置钩子失败同样不承诺自动原子撤销，需要整批原子性时必须使用显式事务。事务成功回滚则恢复前置钩子及自动填值之前的持久化字段、未初始化状态、生成键、版本和缓存身份；非字段业务属性及外部副作用仍由业务负责。

带生成键的多行混批或纯 Entity 批次要求连接支持 `INSERT ... RETURNING`；MySQL 会在钩子及 SQL 前拒绝这类调用，请逐行 `save()`。纯数组行无需回填，仍可在 MySQL 批量插入。

## 9. 事务

```php
User::transaction(function () {
    $a = new User();
    $a->name = '付款方';
    $a->save();

    $b = new User();
    $b->name = '收款方';
    $b->save();
});

// PdoConnection：嵌套层使用真实 SAVEPOINT。
User::transaction(function () {
    // 外层原生事务。
    try {
        User::transaction(function () {
            // SAVEPOINT sp_2；异常回滚只撤销这一层。
            throw new DomainException('撤销内层写入');
        });
    } catch (DomainException) {
        // 内层回滚已成功时，外层可以继续写入并最终提交。
    }
});
```

`PdoConnection` 实现 `ManagedConnection`：深度 1 执行 PDO 原生 begin/commit/rollback；深度 2 及以后执行 `SAVEPOINT sp_N`、`RELEASE SAVEPOINT sp_N`、`ROLLBACK TO SAVEPOINT sp_N` 后释放保存点。内层释放不是独立提交，最外层回滚仍会撤销全部工作。不要在此适配器上混用未受管的 `$pdo->beginTransaction()`，混用会被拒绝。

每层 SAVEPOINT 有独立的实体、缓存与身份快照：内层回滚恢复该层首次读取、修改或插入的对象，外层事务可继续；最外层回滚恢复整链基线。只实现旧 `Connection` 的装饰器不强制实现 `ManagedConnection`，其嵌套 `transaction()` 仍复用外层回调，不提供独立保存点回滚；这是兼容路径，不应据此推断 `PdoConnection` 的行为。

`Session::discard()` 对受管连接调用整链 `forceRollback()`，不是只回滚当前 SAVEPOINT。即使作用域在嵌套事务中提前退出，也不能把还有未确认结果的连接归还连接池。

最外层事务回滚会恢复当前 Gateway 已交付且仍存活实体的持久化字段、未初始化状态、`isNew()`、原始快照、版本与缓存身份，包括事务内首次从数据库读取的对象。同主键的多个对象恢复事务开始前的共同基线，缓存优先保留事务开始前的 canonical 对象，后查询副本不覆盖它。回滚后旧对象可直接保存，新插入对象可重新插入。

新插入行的重复查询副本在回滚后也会失效，不能利用已读取的生成主键绕过插入保护。删除旧行后重插：若数据库复用旧主键，重插对象和查询副本恢复旧行基线；若分配新主键，则按事务新增行失效。非 `#[Column]` 业务属性和钩子的外部副作用不在恢复范围。

只有数据库确认回滚成功，才恢复内存并透传业务异常。begin 返回 `false` 或抛异常、commit 失败、rollback 失败都按结果未知处理，抛 `TransactionException`；不能假定物理事务没有开始或提交。异常公开 `operation`（`开始` / `提交` / `回滚`）、`businessException` 和 `previous`。commit 失败不会被后续 rollback 失败覆盖；commit 已尝试后即使 rollback 成功也不恢复内存。

事务结果未知后，禁止在原 `Connection` 上继续查询、写入、事务或通过另一个 Gateway/`Session::use()` 复用。必须丢弃连接、Gateway 和关联实体；`Session::discard()` 仍允许解除失败 Session。驱动 begin 之前的连接所有权冲突是确定的 `LogicException`，不会毒化连接。

当前上下文的同名会话有未结束事务时，`Session::use()` / `Session::scope()` 的隐式换绑会直接抛 `LogicException`，即使传入同一个 Gateway 也一样；不执行回滚、不清理事务状态、不释放连接所有权，原 Session 保持不变。必须先显式 `Session::discard()` 并处理回滚结果，不能把换绑当成静默清理事务的方式。

## 10. 生命周期钩子

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

## 11. 行缓存

**进程内自动管理**。同一个精确模型 class + 主键的 `find()` 会命中缓存返回同一个对象实例。不同模型即使主键值相同也不会共享缓存身份。

```php
$a = Asset::find(['user_id' => 1, 'key' => 'x']);
$b = Asset::find(['user_id' => 1, 'key' => 'x']);
// $a === $b（同一个实例）
```

- 查询结果也会写入缓存（`Builder::get()` 返回的实体）
- 使用 PHP 有序关联数组维护真正的 O(1) LRU：读取命中也会刷新热度，默认容量 1024 行
- 条件写与删除按精确模型类失效，避免误标其他模型的存活实体
- `delete()` 后自动清退缓存；事务每层保存独立快照，回滚恢复该层对应的 LRU 与 canonical 对象，再补齐缺失身份
- 缓存在进程内有效，不跨进程；不是跨进程一致性或分布式锁机制

## 12. 脏跟踪

`save()` 做更新时只生成变了字段的 SET 子句。比较的是 `markPersisted()` 时刻的快照和当前值。标量、null 与同一对象引用走严格相等快路径；对象值不同引用时，经 Caster 规范化到数据库表示再比较，不使用受瞬态属性影响的宽松对象比较。

```php
$u = User::find(['id' => 1]); // 此时快照：age=28, name='张三'
$u->age = 29;
$u->save();
// SQL: UPDATE user SET age = :set_age WHERE id = :pk_id
// 不会写 name —— 因为它没变

$invoice->amount = new Money($invoice->amount->cents);
$invoice->save(); // 等值 Money 新实例：无 UPDATE，也不递增版本
```

自动 `updated_at` 与关系预加载刷新使用同一语义。未发生字段变化时，不会仅为递增 `#[Version]` 执行 UPDATE；值对象的内部非持久化属性不应影响判断。

## 13. 主键规则

| 规则 | 说明 |
|------|------|
| 代理键 | `#[PrimaryKey(generated: true)]`，恰好 1 个，插入后回填 |
| 自然键 | `#[PrimaryKey(order: N)]`，可复合，调用方必须提供 |
| 不混用 | 一个模型不能同时有 generated 和 order |
| 键值边界 | `0`、`""`、`false` 都是合法键值；`null` 不可作为主键 |
| 身份完整 | 任何按主键操作缺少任意一列都会拒绝，不发 SQL |
| 不可空 | 主键属性不能是可空类型（`?int`） |

## 14. 独立模型生成器

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

生成器会拒绝不安全的数据库标识符、非法命名空间、根目录/当前目录等不明确输出位置，以及无法写入的目标文件。生成模型继承 `Pixiaoka\Orm\Entity`，使用 `#[Column]`、`#[PrimaryKey]`；自增单列主键使用 `generated: true`，复合主键保留数据库声明的列顺序。

默认值边界：PHP 8.3 不允许对象作为属性默认值，因此 Decimal、DateTime 和数据库动态表达式不会被生成为非法初始化代码；无法无损静态表达的默认值不会写入 PHP 属性。PostgreSQL 的 Unicode 转义按码点生成 UTF-8，原始高位字节按服务器编码校验和转换。非 UTF-8 编码下混合两类字节、SQL_ASCII、未知编码或非法序列均不猜测数据库默认值，而采用安全的 PHP 兜底值。

## 15. 元数据缓存

`MetadataReader` 在进程内缓存字段元数据，也支持导出/导入纯 PHP 数组，供 PHP-FPM 部署时复用并通过 OPcache 降低反射成本。缓存不包含 `ReflectionProperty` 等不可序列化对象。

```php
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;

// 构建/部署阶段：为实际使用的模型预热。
$reader = new MetadataReader();
foreach ([User::class, Article::class] as $model) {
    $reader->read($model);
}
$cache = $reader->export();
file_put_contents(
    '/path/to/orm-metadata.php',
    '<?php return ' . var_export($cache, true) . ';',
);

// 请求启动：可将同一个 Reader 注入多个 Gateway。
$metadata = new MetadataReader(require '/path/to/orm-metadata.php');
$gateway = new EntityGateway(new PdoConnection($pdo), $metadata);
```

导出格式为 `['version' => 1, 'models' => [模型类名 => 元数据数组]]`；也可调用 `$reader->import($cache)` 导入已有 Reader。版本、结构或首次读取时的模型定义不一致会抛 `MetadataException`，此时应重新生成缓存，不能手工拼接内部元数据。关系元数据仍按需读取与校验。模型部署和缓存更新必须保持同一版本，缓存不是跨环境自动失效系统。

## 16. 索引声明与 Schema 差异对比

模型类可重复声明 `#[Index]`，属性名或真实列名均可，顺序就是索引列顺序：

```php
use Pixiaoka\Orm\Annotation\Index;

#[Index(['tenant_id', 'email'], name: 'uniq_tenant_email', unique: true)]
#[Index(['created_at'], name: 'idx_article_created_at')]
final class Article extends Entity
{
    // 字段与主键定义
}
```

声明仅参与元数据与 Schema 差异比较，不会在正常查询时自动创建索引。读取数据库实时结构后可生成差异报告：

```php
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Schema\SchemaDiffer;
use Pixiaoka\Orm\Schema\SchemaInspector;

$meta = (new MetadataReader())->read(Article::class);
$table = (new SchemaInspector($pdo))->table('article');
$differ = new SchemaDiffer();
$report = $differ->compare($meta, $table, 'mysql');
$statements = $differ->previewSql($meta, $table, 'mysql');
```

支持 `mysql`、`pgsql`、`sqlite`，比较字段新增/删除、语义类型、可空性及二级索引。报告结构：

```php
[
    'table' => 'article',
    'driver' => 'mysql',
    'changes' => [[
        'kind' => 'add_column',
        'name' => 'summary',
        'expected' => 'text',
        'actual' => null,
        'sql' => 'ALTER TABLE ...;',
        'executable' => true,
        'note' => null,
    ]],
    'hasChanges' => true,
    'hasUnsupported' => false,
]
```

自定义 `Castable` 没有通用数据库类型猜测，构造时必须给出完整三方言映射；内置 `Decimal` 实现默认使用 decimal/text 映射，也可按需显式覆盖：

```php
$differ = new SchemaDiffer([
    Money::class => [
        'mysql' => 'bigint',
        'pgsql' => 'bigint',
        'sqlite' => 'integer',
    ],
]);
```

SQLite 修改字段类型或可空性需人工重建表，报告将对应项标为不可执行；只要存在不支持项，`previewSql()` 就抛出异常，不返回看似完整但实际残缺的迁移计划。字段删除、索引重建、约束、数据和权限必须由应用迁移流程审核。**Differ 只生成报告和预览 SQL，绝不自动执行 DDL，不替代正式迁移系统。**

## 17. 可观测性

```php
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Observability\SessionEvent;

$unsubscribe = $gateway->subscribe(function (Event $event): void {
    if ($event instanceof SqlEvent) {
        // SQL、operation、model、durationMs、successful、parameterNames、
        // affectedRows/resultRows、exceptionClass、context。
    }
    if ($event instanceof TransactionEvent) {
        // begin/commit/rollback；受管连接的每层 SAVEPOINT 也可能产生事件。
        // 不应把每个事件都解释为一次独立物理数据库提交。
    }
    if ($event instanceof SessionEvent) {
        // bind/discard/restore；successful 表示清理结果。
    }
});
```

也可传入实现 `EventListener::onEvent(Event $event): void` 的对象。返回的闭包可幂等取消订阅。监听器异常不会改变 SQL 或事务结果，默认不输出日志；同一 Dispatcher 重入时丢弃嵌套事件。无监听器时跳过事件构造和执行计时。

观测回调不得重入同一连接执行 ORM 操作，也不得通过 `Session::use()`、`Session::scope()` 或 `Session::discard()` 换绑或清理当前业务 Session。独立连接的嵌套事件保留外层保护，不会提前放开这些限制。

可以用完全独立的 Gateway/Connection 直接写观测库；独立写入不加入业务事务，也不保证与业务事务原子提交。直接调用 PDO/Connection 不属于 ORM 重入保护范围。`rollback successful=true` 仅表示回滚清理 API 成功，不证明之前失败的 commit 没有生效。

`ContextInfo::$type` 为 root/fiber/coroutine，`$id` 是进程内上下文对象 `spl_object_id()`，不是跨请求或分布式追踪 ID。事件不包含参数值、查询结果、实体、连接、原异常对象、消息或堆栈；SQL 原文仍可能含手写字面量秘密，不能视为完全脱敏。只覆盖经过 Gateway 的 SQL、事务与 Session 操作，应用直接调用 PDO/Connection、SchemaInspector 不在范围内。可选 `Clock` 构造参数用于确定性计时测试。

## 18. 测试与真实数据库验收

```bash
# 当前已验证基线：395 tests / 11109 assertions（4 项显式 DSN 实连测试默认跳过）
vendor/bin/phpunit

# SQLite / MySQL / PostgreSQL 实连；进程环境变量覆盖 .env
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql tests/orm_live_verify.php
```

MySQL/PostgreSQL 推荐使用独立 Docker 测试实例。请使用临时数据库，不要指向生产库；以下端口是本机示例，可按实际映射修改：

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

`DB_MYSQL_HOST/PORT/NAME/USER/PASSWORD`、`DB_PGSQL_HOST/PORT/NAME/USER/PASSWORD` 分别配置两个数据库；两个真实库均不可达时脚本使用 SQLite 兜底。`orm_live_verify.php` 使用随机表名，按 PDO 登记并只清理本次创建的表；SQLite 兜底通过不能算真实 MySQL/PostgreSQL 通过。完成后应停止并删除上述专用容器，不要复用其他业务容器。

### 模型生成器实连测试

生成器另有 `tests/SchemaLiveTest.php`，只有显式设置 `PIXIAOKA_ORM_LIVE_MYSQL_DSN`、`PIXIAOKA_ORM_LIVE_PGSQL_DSN` 及对应 `_USER`、`_PASSWORD` 才连接外部数据库；默认跳过，不隐式读取本机服务或 `.env`。

```bash
PIXIAOKA_ORM_LIVE_MYSQL_DSN='mysql:host=127.0.0.1;port=32768;dbname=orm_test;charset=utf8mb4' \
PIXIAOKA_ORM_LIVE_MYSQL_USER=root PIXIAOKA_ORM_LIVE_MYSQL_PASSWORD=local-only-password \
PIXIAOKA_ORM_LIVE_PGSQL_DSN='pgsql:host=127.0.0.1;port=32770;dbname=orm_test' \
PIXIAOKA_ORM_LIVE_PGSQL_USER=postgres PIXIAOKA_ORM_LIVE_PGSQL_PASSWORD=local-only-password \
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql vendor/bin/phpunit
```

该测试关闭 PDO 预处理模拟，验证自增/复合主键、静态与动态默认值、时间类型、生成文件 `php -l` 和 ORM 元数据；只创建并清理随机测试表。

当前已验证的 Docker PHP 8.3 默认 SQLite 完整套件为 **395 tests / 11109 assertions / 4 skipped**，PHPStan 生产代码检查零错误。4 个跳过项均为未配置显式外部 DSN 的既有 MySQL/PostgreSQL 实连测试；本轮不以 SQLite 结果冒充真实双库通过。历史实连结果只作为既有验证记录，不表述为本次文档维护重新执行的结果。CI 继续核验多版本、Composer、lint 和双库服务测试；Swoole/Swow 真实扩展验收未执行。

## 19. 项目集成示例

本节演示如何集成到独立业务项目。示例假设该项目的 `config/database.php` 中存在名为 `pgsql` 的 PostgreSQL 连接；它不是独立核心库附带的配置。一个典型的模型迁移：

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