# ORM Core Usage Guide

简体中文: [USAGE.zh-CN.md](./USAGE.zh-CN.md) | Home: [README.md](./README.md)

Namespace: `Pixiaoka\Orm\`. The standalone Composer package lives in `src/`. This is an Active Record ORM core independent of webman, using attributes to define columns and keys and converting types at the gateway boundary.

## 1. Initialization

The ORM does not depend on webman, own PDO, or require a connection pool. With ordinary PHP-FPM, register a Session at the start of a request and call `Session::discard()` in `finally`. Long-running workers and concurrent Fibers/coroutines should use strict mode and explicit scopes:

```php
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

$pdo = new \PDO('mysql:host=...;dbname=...', 'user', 'pass');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

Session::use(new EntityGateway(new PdoConnection($pdo)));
try {
    // Application operations.
} finally {
    Session::discard();
}

// Each Fiber/coroutine uses its own connection; the application or an optional pool supplies it.
Session::strict();
Session::scope(new EntityGateway(new PdoConnection($pdo)), function (): void {
    // The previous Session is restored on both normal return and exceptions.
});

// Alternatively, omit the callback and manage the scope manually; close it in the same Context.
$scope = Session::scope(new EntityGateway(new PdoConnection($pdo)));
try {
    // Application operations.
} finally {
    $scope->close();
}
```

A scope does not close PDO. The application creates, reconnects, health-checks, and returns connections. `Session::discard()` rolls back the entire managed transaction chain left in the current context and unbinds it. Closing a `Session::scope()` performs the same cleanup before restoring the previous Session; the binding is restored and the exception is propagated even if rollback fails. Do not continue committing after clearing a Session inside a transaction callback.

An `EntityGateway` belongs to one Context throughout its active read/write lifetime, from successful binding until `discard()` or scope release, not just during transactions. Another Fiber/coroutine attempting to bind it receives an early `LogicException`, preventing `lastInsertId()` interference or interleaved asynchronous driver packets. Repeated binding in the same Context is reference-counted, and nested scopes restore outer ownership.

### Statement cache

`PdoConnection` keeps up to 64 reusable `PDOStatement` objects in an O(1) LRU cache keyed by the original SQL text. A cache hit skips `PDO::prepare()` and PostgreSQL dollar-quote normalization; each execution still binds the current values using their actual scalar types.

```php
$pdo = new \PDO($dsn, $user, $password, [
    \PDO::ATTR_EMULATE_PREPARES => false,
]);

$connection = new PdoConnection($pdo);                         // Default capacity: 64
$bounded = new PdoConnection($pdo, statementCacheSize: 128);   // Custom LRU capacity
$uncached = new PdoConnection($pdo, statementCacheSize: 0);    // Disable statement caching

$connection->clearStatementCache(); // Release cached statement handles explicitly
```

A negative capacity throws `InvalidArgumentException`. SQL longer than 4096 bytes and SAVEPOINT control statements bypass the cache, preventing one-off bulk statements from evicting reusable queries. Query and write paths close cursors in `finally`, including partially read results. Binding or execution failures evict the affected statement; `forceRollback()` also clears the cache. DDL changes can invalidate server-side plans, so propagate the original exception and retry the operation only when its business semantics permit it.

The cache belongs to one `PdoConnection`, not to PDO globally or across independent Gateway instances. It reduces client-side preparation on all supported drivers and preserves server-side prepared statements on pdo_pgsql. With the tested pdo_mysql mysqlnd driver, closing a cursor may still cause another server PREPARE when the same statement object executes again; statement reuse does not guarantee elimination of MySQL server PREPARE round trips. Measure the target driver and workload rather than treating the cache as a universal performance guarantee.

### Named sessions and multiple data sources

```php
Session::use($writerGateway);                 // Default name: default
Session::use($readerGateway, 'readonly');
try {
    $reader = Session::get('readonly')->gateway;
    // Static model APIs select default; an explicit Builder selects another data source.
    $rows = (new \Pixiaoka\Orm\Query\Builder(User::class, $reader))
        ->where('active', true)
        ->get();

    // Temporarily switch default to reader; writer is restored on return or exception.
    $count = Session::scope($readerGateway, fn (): int => User::query()->count());

    Session::discard('readonly');             // Clear only readonly
} finally {
    Session::discard(null);                    // Clear every named session in this Context
}
```

The `$name` argument of `use()`, `get()`, and `scope()` defaults to `'default'`. Omitting the argument to `discard()` clears only `default`; pass explicit `null` to clear all. Named sessions maintain separate transaction and rollback state, and an entity cannot implicitly switch data sources. Multiple sessions need independent PDO/Gateway instances; this API does not make one physical connection safe for concurrent use.

### ManagedConnection and connection pools

`PdoConnection` implements the optional `ManagedConnection` interface. In addition to the basic SQL contract, it provides:

- `ping(): bool`: performs a lightweight liveness check and returns `false` on disconnection or failure without leaking driver exceptions to the pool;
- `forceRollback(): bool`: rolls back the entire physical transaction chain and resets nesting depth to zero on success;
- nested transactions: native PDO transactions at the outermost level and SAVEPOINTs at deeper levels.

A pool may call `ping()` when lending or returning a connection, but must first clean up the ORM context normally through `Session::discard()`. `ping()` does not establish the result of an uncommitted transaction, and `forceRollback()` must not hide an unknown commit/rollback result. After an unknown result, discard the connection, Gateway, and associated entities. A decorator implementing only the legacy `Connection` interface need not add these methods, but it has no physical SAVEPOINT capability; see [Transactions](#9-transactions) for nested behavior.

`Session` associates Fiber or runtime coroutine objects through `WeakMap`, rather than reusable numeric IDs; transaction contexts are likewise isolated by object identity. `Session::strict()` prevents an unregistered Fiber/coroutine from implicitly falling back to the root Session. It is disabled by default for backward compatibility. `Session::scope()` restores the previous Session on normal return or exception. Without a callback, the returned `SessionScope` must be closed manually with `close()` and cannot be closed from another context.

**webman is only an optional integration.** Application middleware may create the scope for each request; do not implicitly share a root PDO with all Fibers. Nested contexts must explicitly select connections and do not inherit a parent transaction. PHP-FPM needs no pool for one connection per request; long-running workers and concurrent Fibers/coroutines rely on the application or an optional adapter to lend and return connections. SQLite does not permit multiple uncommitted write transactions in parallel. Fiber tests with independent PDO instances validate lifecycle, not concurrent SQLite writes. Validate Swoole/Swow with their real extensions; Fiber tests are not a substitute.

## 2. Defining Models

### Surrogate key (auto-increment ID)

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

### Composite natural key

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

### Invalid: mixing generated and natural keys

```php
// One model cannot combine generated: true and order: N primary keys.
```

### Supported field types

| PHP type | Database | Example property |
|----------|----------|------------------|
| `int` | INTEGER | `public int $count;` |
| `float` | REAL / FLOAT | `public float $amount;` |
| `string` | VARCHAR / TEXT | `public string $name;` |
| `bool` | 0/1 | `public bool $active;` |
| `array` | JSON | `public array $tags;` |
| `Pixiaoka\Orm\Cast\BcDecimal` | TEXT (high-precision decimal) | `public BcDecimal $price;` |
| `BackedEnum` | Backing int/string value | `public SampleStatus $status;` |
| `Castable` implementation | Custom scalar representation | `public Money $price;` |
| `DateTimeImmutable` | `Y-m-d H:i:s` UTC | `#[Temporal] public DateTimeImmutable $at;` |
| Nullable `?` forms | NULL | `public ?string $desc;` |

Arrays are encoded and decoded as JSON. Invalid JSON or an unknown enum value throws `CastException`. Only int/string backed enums are supported; pure enums are not. Implement `Castable` for a custom value object:

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
            throw new \InvalidArgumentException('Invalid monetary amount');
        }
        return new self((int) $value);
    }
}

// This property belongs inside an Entity model:
#[Column]
public Money $price; // Integer cents in the database, Money in PHP
```

`toDatabase()` may return int/float/string/null; non-nullable fields remain subject to their nullability constraint. `fromDatabase()` receives a database scalar, and read/write conversion failures are wrapped in `CastException`. Implementations should be immutable or otherwise maintain consistency; do not represent persistent values through transient object state.

### Decimal

On PHP 8.3, use the built-in `Pixiaoka\Orm\Cast\BcDecimal`, which preserves precision with strings. On PHP 8.4+, you may wrap `BcMath\Number` in an implementation of `Pixiaoka\Orm\Cast\Decimal`:

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

Like `Castable`, Decimal values are converted at the gateway boundary. Fields are compared by normalized database representation, so a new equal instance does not trigger a meaningless UPDATE.

### Semantic dirty tracking for value objects

Entity updates and relation refreshes compare object fields by normalized database representation. New but equal `Money`, `DateTimeImmutable`, or Decimal objects are not marked dirty, and therefore do not cause an extra UPDATE or increment `#[Version]`. Times with different time zones but the same instant are compared using their actual storage format. Castable values bound to a Builder are normalized by the same Caster.

### DateTimeImmutable storage and display formats

Storage and display formats are independent. These properties belong inside an Entity model:

```php
use Pixiaoka\Orm\Annotation\Temporal;

// Default: second-precision UTC text such as "2026-11-13 21:17:45"
#[Column]
#[Temporal]
public DateTimeImmutable $happened_at;

// UTC date text / native DATE; reads as midnight UTC on that date
#[Column]
#[Temporal(Temporal::DATE)]
public DateTimeImmutable $happened_on;

// UTC microsecond text; writes six digits and accepts PostgreSQL's 0–6 digits
#[Column]
#[Temporal(Temporal::TEXT_US)]
public DateTimeImmutable $precise_at;

// Unix integer seconds / milliseconds without floating-point conversion
#[Column]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $created_at;

#[Column]
#[Temporal(Temporal::UNIX_MS)]
public DateTimeImmutable $created_ms;

// Only affects toArray(): UTC storage and the property remain unchanged
#[Column]
#[Temporal(Temporal::TEXT_US, displayFormat: 'Y-m-d H:i:s.u P', displayTimezone: 'Asia/Shanghai')]
public DateTimeImmutable $published_at;
```

| Storage constant | Database value | Read result |
|---|---|---|
| `Temporal::TEXT` | `Y-m-d H:i:s` UTC | UTC `DateTimeImmutable` |
| `Temporal::DATE` | `Y-m-d` UTC | Midnight UTC on that date |
| `Temporal::TEXT_US` | `Y-m-d H:i:s.u` UTC | UTC time with microseconds |
| `Temporal::UNIX` | Unix integer seconds | UTC `DateTimeImmutable` |
| `Temporal::UNIX_MS` | Unix integer milliseconds | UTC time with milliseconds |

Use six-digit-capable columns for `TEXT_US`: `DATETIME(6)` on MySQL, `TIMESTAMP(6)` on PostgreSQL, and `TEXT` on SQLite. Use `DATE` on MySQL/PostgreSQL and `TEXT` on SQLite for `DATE`; both Unix modes use `BIGINT` on MySQL/PostgreSQL and `INTEGER` on SQLite. SchemaDiffer distinguishes dates from date-times and checks fractional-second precision on MySQL/PostgreSQL. Before changing an existing second-precision field to `TEXT_US` or `UNIX_MS`, migrate the stored values rather than changing only the attribute.

All times are normalized to UTC before writing. `DATE` truncates to the **UTC calendar date**, not the original timezone's local date. Convert a business-local date to midnight UTC in application code first, or use a plain string field when the instant-based rule is not appropriate. Milliseconds use integer arithmetic, including negative timestamps. Invalid dates, more than six fractional digits, storage text containing a timezone, and non-integer Unix values throw `CastException` instead of being silently corrected.

`displayFormat` uses PHP date formatting. `displayTimezone` defaults to UTC and cannot be specified without a format. Display configuration does not affect INSERT/UPDATE/UPSERT, WHERE bindings, relation keys, dirty tracking, primary-key caching, or `refresh()`; it changes only the corresponding `toArray()` value. Without a display format, `toArray()` retains the stored representation.

Automatic `#[CreatedAt]` / `#[UpdatedAt]` fields support the same storage modes. Single-entity and entity-batch automatic values use the declared microsecond/millisecond precision. Array batches intentionally retain integer-second defaults so every row shares one batch timestamp. Existing second-precision models retain their behavior unless a new mode is explicitly selected. The optional display fields in metadata caches are backward-compatible with old exported caches.

### Configurable automatic timestamps

Traditional `int $created_at` / `int $updated_at` properties remain compatible without additional attributes. Use `#[CreatedAt]` and `#[UpdatedAt]` for custom field names; both `int` and `DateTimeImmutable` are supported:

```php
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Annotation\Temporal;

#[Column]
#[CreatedAt]
public DateTimeImmutable $created_on; // Default UTC Y-m-d H:i:s

#[Column(name: 'modify_time')]
#[UpdatedAt]
public int $modify_time; // Unix seconds

#[Column]
#[CreatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $created_unix; // Creation time can also use Unix seconds

#[Column]
#[UpdatedAt]
#[Temporal(Temporal::UNIX)]
public DateTimeImmutable $updated_unix; // Update time supports Unix seconds too
```

Both timestamps are populated on insert, and only the update timestamp is refreshed on update. An explicitly modified update timestamp is not overwritten. A creation timestamp is not overwritten by ordinary updates or UPSERT conflict updates. Inserts, batch inserts, and UPSERT all support automatic population. Each role may be declared once only; the roles cannot share a field or be placed on a primary key. Other PHP types are unsupported. Models without these fields need no configuration.

The model generator adds timestamp-role attributes to conventional `created_at` / `updated_at` fields only when they map to `int` or a losslessly readable `DateTimeImmutable`. Add the attributes manually for other column names.

### Optimistic locking: #[Version]

```php
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Exception\OptimisticLockException;

#[Column]
#[Version]
public int $revision;

try {
    $order->save(); // UPDATE includes the full key and old version; SQL executes revision = revision + 1
} catch (OptimisticLockException $e) {
    // Read model / operation / expectedVersion; no automatic refresh, overwrite, or retry occurs.
    $order->refresh();
}
```

A version field must also declare `#[Column]`, have non-nullable `int` type, be unique within the model, and not serve as a primary key, generated column, or automatic timestamp. Inserts default to version `1`; an explicit `0` is retained, while negative values and `PHP_INT_MAX` are rejected. Deletes use the same version condition. If the affected-row count is not `1`, an exception is thrown without advancing the in-memory version, snapshot, or cache. With no business changes, no UPDATE is issued merely to increment the version. Directly modifying the version property cannot bypass the check.

Models with `#[Version]` do not support Builder conditional UPDATE/DELETE or UPSERT, including conflict-ignore, because these operations have no per-entity old-version snapshot. `executeRaw()` neither adds version conditions nor handles conflicts; the caller controls SQL, affected rows, and subsequent `refresh()` / `flushCache()`. The generator adds `#[Version]` only to a non-nullable, non-primary-key `int` column named exactly `version`; declare other version columns explicitly.

### Soft deletion: #[SoftDelete]

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Temporal;

#[Column]
#[SoftDelete]
#[Temporal]
public ?DateTimeImmutable $deleted_at = null;
```

This declaration belongs inside an Entity model. The field must also have `#[Column]`, be a nullable `DateTimeImmutable`, and not serve as a primary key, generated column, version, or automatic timestamp. A model may declare at most one. `NULL` means not deleted. Times use second-precision UTC, and in-memory values match a database refresh after both single-entity and batch soft deletion.

```php
$visible = Article::query()->get();                    // Default: deleted_at IS NULL
$all = Article::query()->withTrashed()->get();          // Include trash
$deleted = Article::query()->onlyTrashed()->get();      // Trash only

$article->delete();                                    // Soft delete
$article->restore();                                   // Restore entity
$article->forceDelete();                               // Physically delete entity

$n = Article::query()->where('tenant_id', 7)->delete();
$n = Article::query()->onlyTrashed()->where('tenant_id', 7)->restore();
$n = Article::query()->withTrashed()->where('id', 7)->forceDelete();
```

On a soft-deleting model, Builder `delete()` writes a timestamp, `restore()` restores matching deleted rows, and `forceDelete()` physically deletes. By default, `delete()` / `forceDelete()` exclude already soft-deleted rows; use `withTrashed()` or `onlyTrashed()` to select an explicit scope. The soft-delete scope is itself a write boundary, so these operations do not require another WHERE as ordinary model DELETE does. `restore()` also targets only trash. In particular, `withTrashed()->forceDelete()` without other conditions can physically empty the table. `restore()` / `forceDelete()` are rejected on models without soft deletion. Batch writes do not invoke entity hooks; refresh already-held objects when necessary.

Ordinary queries, relation loading, relation filters, and counts all apply the target model's soft-delete scope. A relation constraint may explicitly call `withTrashed()` to include the target trash. Soft deletion is target-model metadata, not an additional global switch for relation loading.

## 3. Read-only Relations and Eager Loading

### Declaring relations

Relation attributes and query classes have the same short names in different namespaces; use aliases to avoid collisions:

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

A relation method must be public, non-static, take no parameters, and declare the exact concrete relation-query return type. `localKey` belongs to the current model and `foreignKey` to the target model; an omitted side uses its primary key. Property names, real column names, and composite keys are supported. Keys correspond by array position and cannot be duplicated or type-mismatched.

### Explicit queries and eager loading

```php
// Query a relation separately without implicitly changing the source entity's loadedRelation state.
$orders = $customer->orders()->get();
$latestPaid = $customer->orders()->query(
    Order::query()->where('status', 'paid')->orderBy('id', 'DESC'),
)->first(); // LIMIT 1

// One main query plus one or more batched SELECTs per relation at each level.
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

For `hasMany()`, `get()` returns an entity list; for `hasOne()` and `belongsTo()`, it returns an entity or `null`. All three `first()` methods return an entity or `null`. `loadedRelation()` throws when the relation has not been loaded rather than executing SQL implicitly. An uninitialized relation key rejects the query. If any relation key is `null`, the result is `null` or an empty array without relation SQL. An empty main result also skips eager loading. Composite keys use parameterized OR-of-AND tuple conditions in batches of at most 1000 relation parameters, preventing incorrect combinations across parent records.

Explicit relation queries preserve user ordering and append an ascending target-primary-key tiebreaker when the full target key is not already ordered. The same rule makes `hasOne` with multiple candidates and `belongsTo` with a non-unique target key deterministic; `first()` still returns only the first row. Eager loading appends target relation keys followed by the target primary key, retaining user ordering first; relation keys overlapping the full primary key are not repeated. Duplicate or mixed relation attributes on one method throw `MetadataException` when relation functionality is first used; ordinary CRUD does not scan relation attributes. Eager-load constraints allow only WHERE/OR and ordering. Global pagination, projections, JOIN, GROUP/HAVING, UNION, derived tables, DISTINCT, table aliases, row locks, and nested `with/load` inside a constraint are rejected. `with()` applies only to entity `get()` / `first()` and is never silently ignored by `rows()`, `value()`, `count()`, or conditional UPDATE/DELETE. Ordinary `Builder::first()` does not add primary-key ordering.

### Object, transaction, and connection boundaries

- Relations are excluded from `toArray()`, dirty tracking, and automatic save/delete. `save()` preserves relations; `refresh()` / `delete()` clears them.
- Ordinary `query()` returns independent copies. Only one relation graph deduplicates by primary key; a nested reverse relation may point to the parent object from that main query.
- Relations and cache are published only after the whole graph succeeds. Failure restores fields, original values, relations, and cache; a physical transaction rollback also restores them.
- Refreshing a cached target preserves unsaved fields. An old relation depending on a changed persistent relation key is precisely invalidated; a pending new relation replaces it after success, while unaffected relations remain. Use explicit `refresh()` to discard local changes rather than relying on relation loading.
- Eager loading uses multiple SELECT statements, not an atomic database snapshot. Use a transaction with an appropriate isolation level when consistency requires it.
- An entity weakly records the Gateway that first read or persisted it. After `discard()`, rebinding the same Gateway permits further saves, refreshes, and relation loads; switching to another Gateway rejects implicit reads/writes, so query explicitly on the new connection. Entity database operations still require an explicitly selected Session. Once the original Gateway is destroyed, database operations and `toArray()` fail explicitly rather than binding a new connection automatically. `loadedRelation()` only reads loaded memory and needs no Session.
- Weak ownership does not release the whole object graph immediately: the Gateway's `RowCache` strongly holds entities in its LRU, and loaded relations hold related entities. For full isolation on each pool checkout, create a new Gateway. Before return, discard that Gateway, clear its cache, and release external entity/graph references; unsetting one Entity variable is insufficient. After the original Gateway is destroyed, explicitly query old entities again on a new connection.
- `belongsToMany`, automatic cascading writes, and property lazy loading are not included. Relation filtering and counting follow below.

### Relation filtering and counting

```php
use Pixiaoka\Orm\Query\Builder;

$customers = Customer::query()
    ->whereHas('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$withoutPaid = Customer::query()
    ->whereDoesntHave('orders', fn (Builder $q) => $q->where('status', 'paid'))
    ->get();

$customers = Customer::query()
    ->whereRelation('orders', 'status', 'paid')           // Three arguments: column = value
    ->get();
$customers = Customer::query()
    ->whereRelation('orders', 'amount', '>=', 100)       // Four arguments: column operator value
    ->get();

$rows = Customer::query()
    ->withCount('orders') // orders_count
    ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
    ->orderBy('id')
    ->rows();

$rows = Customer::query()
    ->withCount(['orders', 'orders.customer']) // orders_count and orders_customer_count
    ->withCount('orders', fn (Builder $q) => $q->where('status', 'paid'), 'paid_orders')
    ->rows();
```

`whereHas()` and `whereDoesntHave()` generate correlated `EXISTS` / `NOT EXISTS`; they neither read related entities nor set `loadedRelation()`. Negation applies to the whole constrained set: a customer with both paid and new orders does not match "has no paid orders". Dot paths correlate each level, and the closure constrains only the last level. For example, `orders.customer` counts/filters only Orders with a corresponding Customer; NULL and orphan foreign keys do not count. Composite keys pair by declaration position, and self-relations use a separate internal alias at each level.

`whereRelation()` is the single-field shortcut for `whereHas()`. Three arguments always mean equality, even if the value looks like `IN`, `=`, or another operator. Four arguments support ordinary structured comparisons, NULL, array IN/NOT IN, and BETWEEN, with values converted by the target model Caster. The immediate parent query may use one base table with an alias, ordinary WHERE/OR, ordering, and pagination. Parent JOIN/GROUP/HAVING/UNION/derived-table/DISTINCT queries are unsupported. `_orm_relation_N` is an internal relation alias; conflicts with a parent alias or a real table involved in a relation are explicitly rejected to avoid silently incorrect counts.

`withCount()` adds a raw output column through a correlated `COUNT(*)` scalar subquery. It does not hydrate entities, modify entity fields, relation state, or cache, or cause N+1 queries. The default alias replaces dots in the path with underscores and appends `_count`. The same path may be counted under different aliases, and an array declares multiple paths and final-level constraints. Output-name conflicts with default model columns, explicit projections, or other count aliases are rejected when building SQL. Read counts with `rows()`, `row()`, or `value('orders_count')`; the PDO raw type is not guaranteed to be PHP int.

Relation filters and count constraints allow only structured WHERE/OR and nested relation filters. Property names and real column names are supported, and constraint columns are automatically qualified for the current relation. Raw conditions/expressions, arbitrary ordinary Builder subqueries, explicit table prefixes, projections, withCount, JOIN, GROUP/HAVING, UNION, derived tables, DISTINCT, aliases, ordering, limit/offset, row locks, and with are unsupported. A closure must return `null` or the same Builder passed to it. Do not execute get/first/rows/row/value/count/build/preview/update/delete or other terminal operations in a closure or its OR groups. Invalid constraints are rejected before any SQL.

`whereHas()/whereDoesntHave()/whereRelation()` work with entity queries, raw rows, parent COUNT, and conditional UPDATE/DELETE, subject to existing restrictions such as optimistic locking. They may be combined with independent `with()`; filters do not become eager-load constraints. In the current version, `withCount()` is mutually exclusive with relation filters and cannot be used with `get()/first()/count()`, conditional UPDATE/DELETE, or `with()`. Ordinary WHERE/OR may be called before or after a count, but a count cannot be combined with relation filters inside an OR group. If the target model uses soft deletion, relation filters and counts include only non-deleted targets by default; a relation constraint may explicitly call `withTrashed()`. Quantity comparisons through `has()`, relation writes, and many-to-many relations are not included.

## 4. Active Record

### save() — intelligent insert/update

`save()` inserts an object created with `new` and updates one returned by `find`. **It does not decide based on the primary-key value.**

```php
// Insert
$u = new User();
$u->name = 'Alice';
$u->age = 28;
$u->save();
// $u->id has been populated

// Query then update
$u = User::find(['id' => 1]);
$u->age = 29;
$u->save();  // UPDATE — changed fields only

// Reinsert after deletion
$u = User::find(['id' => 1]);
$u->delete();
$u->save();  // INSERT again
```

### insert() — forced insertion

```php
$u = new User();
$u->name = 'Bob';
$u->insert(); // Available only while isNew=true

$u->insert(); // IdentityException: "User is already persisted and cannot be inserted again"
```

### delete() — deletion

```php
$u = User::find(['id' => 5]);
$u->delete(); // Returns void; throws if affected rows !== 1

$u->delete(); // IdentityException: "User is not persisted and cannot be deleted"
```

### find() — lookup by primary key

```php
// Surrogate key
$u = User::find(['id' => 42]);

// Composite key — every column is required
$item = OrderItem::find(['order_id' => 10, 'sku' => 'ABC']);

// A missing column is rejected before SQL
OrderItem::find(['order_id' => 10]); // IdentityException
```

### toArray() — export

```php
$u = User::find(['id' => 1]);
print_r($u->toArray());
// ['id' => 1, 'name' => 'Alice', 'age' => 29, 'created_at' => 1700000000, 'updated_at' => 1700000000]
```

### refresh() — reread from the database

```php
$u = User::find(['id' => 1]);
// ... another process changed the row ...
$u->refresh(); // SELECT again and overwrite all fields
```

## 5. Query Builder

### Basic queries

```php
use app\model\User;

// Conditional query
$users = User::query()
    ->where('age', 28)
    ->whereOp('name', 'LIKE', 'A%')
    ->get();

// Single row
$user = User::query()
    ->where('name', 'Alice')
    ->first();

// Count
$count = User::query()
    ->where('age', 28)
    ->count();

// Clone and reuse: query structures do not affect one another.
$adults = User::query()->whereOp('age', '>=', 18);
$firstPage = (clone $adults)->orderBy('id')->limit(20);
$admins = (clone $adults)->where('role', 'admin');
```

`clone` recursively isolates ORM query state including WHERE/OR, subqueries, JOIN, UNION, derived tables, ordering, and pagination. `first()`, `row()`, `value()`, and complex `count()` also clone to avoid contaminating the original query. Business objects in bindings, such as `DateTimeImmutable`, enums, and Decimal, are not cloned; avoid modifying shared mutable value objects. Internal `QueryState` and SQL/relation compilers are not public extension points; the fluent Builder is the stable API.

### SQL debugging preview: preview()

```php
$query = User::query()
    ->where('name', "O'Reilly")
    ->whereOp('age', '>=', 18)
    ->orderBy('id')
    ->limit(20);

// Every preview terminal returns a string. No database I/O, cache access, or entity hooks occur.
echo $query->preview()->get();
echo $query->preview()->first();   // LIMIT 1 is applied only to a preview clone
echo $query->preview()->count();

// Raw-row and scalar previews retain their corresponding Builder validation.
echo $query->preview()->rows();
echo $query->preview()->row();
echo User::query()->select('name')->where('id', 7)->preview()->value();

// Conditional writes produce diagnostic text too; normal write validation still applies.
echo User::query()->where('id', 7)->preview()->update(['name' => 'next']);
echo User::query()->where('id', 7)->preview()->increment('age', 1, ['name' => 'next']);
echo User::query()->where('id', 7)->preview()->decrement('age');
echo User::query()->where('id', 7)->preview()->delete();

// Soft-deleting models: UPDATE for delete/restore and DELETE for forceDelete.
echo Article::query()->where('id', 7)->preview()->delete();
echo Article::query()->where('id', 7)->preview()->restore();
echo Article::query()->onlyTrashed()->where('id', 7)->preview()->forceDelete();
```

`preview()` owns a `clone $this`, so `first()` and `restore()` cannot change the original Builder's `limit`, `softDeleteMode`, or cache scope. With `with()`, `get()` and `first()` return only the main SELECT and do not invent eager-loading subqueries. The other read previews retain their real terminal checks. Conditional-write previews remain forbidden for `#[Version]` models; models without soft deletion reject `restore()` / `forceDelete()` previews; relation-constraint closures reject preview terminals as well.

Interpolation uses a lexical state machine. It replaces only actual named placeholders and skips paired quotes, comments, PostgreSQL dollar-quoted strings, and `::type` casts. Booleans follow the current driver, while non-finite floats and missing parameters are rejected. NUL, newlines, tabs, and other control characters are rendered as readable `\0`, `\n`, and `\t` escapes; MySQL string literals also escape backslashes.

**For debugging and inspection only. Never execute the returned string as a substitute for parameterized Builder execution. The output contains plaintext parameter values; redact it before writing logs, reporting errors, or sharing screenshots.**

### Available condition methods

```php
->where('column', $value)                     // column = :value; null becomes IS NULL
->whereOp('column', '>', 10)                  // column > 10
->whereOp('column', '!=', null)               // column IS NOT NULL
->whereOp('column', 'BETWEEN', [1, 5])        // Two parameters
->whereNull('column')                         // column IS NULL
->whereNotNull('column')                      // column IS NOT NULL
->whereIn('column', [1, 2, 3])               // column IN (1,2,3)
->whereNotIn('column', [])                    // Empty IN is false; empty NOT IN is true
```

### OR conditions

```php
// (age < 18 OR age > 60) AND city = 'Shanghai'
$users = User::query()
    ->orGroup(fn($q) => $q->whereOp('age', '<', 18)->whereOp('age', '>', 60))
    ->where('city', 'Shanghai')
    ->get();
```

### Ordering and pagination

```php
$users = User::query()
    ->whereOp('age', '>=', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->offset(0)
    ->get();
```

## 6. Advanced Queries

### 6.1 Result types

`get()` / `first()` return only complete entities. Custom projections, JOIN, aggregates, GROUP/HAVING, UNION, and derived tables must use:

- `rows()`: a list of arrays;
- `row()`: one array or `null`;
- `value()`: the scalar from the first or a named field.

The ORM does not hydrate incomplete rows into entities or put partial entities into the row cache. Raw arrays are not automatically converted to `Decimal`, `DateTimeImmutable`, or other PHP objects.

### 6.2 Projections, JOIN, and table aliases

```php
use Pixiaoka\Orm\Query\JoinClause;

$rows = Order::query()->alias('o')
    ->selectAs('o.sku', 'order_sku')
    ->selectAs('t.label', 'tag_label')
    ->leftJoin('order_tag', 't', fn (JoinClause $j) => $j
        ->on('o.id', '=', 't.order_id')       // Column-to-column; identifiers are validated and quoted
        ->where('t.label', '=', 'paid'))      // Bound parameter in ON
    ->where('o.user_id', 7)
    ->rows();
```

`join()` / `innerJoin()` / `leftJoin()` / `rightJoin()` use structured ON conditions. A special join without ON must explicitly use `joinRaw($sql, $params)`. Without a select, JOIN returns complete base-table columns by default; using `selectAs()` with `rows()` is still recommended.

WHERE/IN/BETWEEN/SET values for base-model fields reuse the model Caster and accept property names or real column names. An `alias.column` field does not infer another table's model; pass a database scalar or use a raw condition.

### 6.3 Aggregates, DISTINCT, GROUP BY / HAVING

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

Only COUNT/SUM/AVG/MIN/MAX aggregate functions are accepted. Structured HAVING accepts only identifiers or those aggregate expressions; use parameterized `havingRaw()` for complex conditions.

### 6.4 Subqueries and derived tables

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

Scalar and IN subqueries must explicitly select exactly one column; EXISTS has no such restriction. Subqueries, derived tables, and UNION cannot carry row locks. A subquery must use the same `EntityGateway` as its parent, preventing SQL and parameters from executing on the wrong connection.

### 6.5 UNION / UNION ALL

```php
$first = Order::query()->selectAs('sku', 'name')
    ->where('user_id', 7)->orderBy('id')->limit(1);
$second = Order::query()->selectAs('sku', 'name')
    ->where('user_id', 8)->orderBy('id', 'DESC')->limit(1);

$rows = $first->unionAll($second)
    ->orderBy('name', 'DESC')   // Top-level UNION ordering
    ->limit(10)                 // Top-level UNION pagination
    ->rows();
```

Every branch must return the same number and names of output columns. Use `select()/selectAs()` or aliased `aggregate()`. Local pagination before `union()` belongs to the first branch. The Builder wraps each branch's own `orderBy()/limit()/offset()` according to SQLite/MySQL/PostgreSQL syntax. A nested UNION may be used as a derived table or single-column subquery; use `queryRaw()` for complex raw UNION statements.

### 6.6 Raw SQL: CTEs, window functions, and vendor-specific expressions

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

Raw SQL is **trusted developer SQL**, not an interface for concatenating user input:

- Only named placeholders are accepted, and parameter names must exactly match actual SQL usage;
- Missing or extra parameters, `?` placeholders, and array/object parameters throw exceptions;
- Pseudo-parameters inside strings, double quotes, backticks, line comments, and block comments are skipped;
- PostgreSQL `value::type` casts are not misread as parameters;
- `:name` and `?` inside PostgreSQL `$$...$$` / `$tag$...$tag$` dollar-quoted text are not parameters. Before passing SQL to `pdo_pgsql`, `PdoConnection` converts such text to an equivalent escaped string literal, avoiding PDO misinterpretation while preserving returned text;
- Repeated parameters receive independent unique `qN` names on each build;
- `Order::raw($sql, $params)` creates a reusable Expression; parameters cannot be supplied again;
- `queryRaw/queryOneRaw` return arrays; `executeRaw` returns affected rows and never hydrates automatically.

After raw model writes, explicitly call `Order::flushCache()`. Raw SQL bypasses model identity and lifecycle hooks.

## 7. Conditional UPDATE / DELETE, UPSERT, and Row Locks

### 7.1 Conditional batch writes

```php
$affected = Order::query()->where('user_id', 7)->update(['qty' => 10]);
$affected = Order::query()->whereIn('id', [1, 2])->delete();
```

A WHERE clause is required. An empty IN remains a false condition and never becomes a full-table write. Empty SET, primary-key modification, JOIN/GROUP/HAVING/UNION, projections, ordering, pagination, and locks are rejected. The model cache is cleared after success; affecting zero rows is not an exception.

PostgreSQL/SQLite use `DELETE ... RETURNING` to read actually deleted keys. MySQL first performs a constrained pre-query for identities of entities already delivered by the current Session, then executes DELETE. Both normalize keys with the model Caster and mark only actually deleted objects as unpersisted. Weak-reference identity tracking precisely invalidates objects even after eviction from the LRU or batch writes, without affecting other entities from the same table. Conditional UPDATE/UPSERT does not update old entity objects already held by the caller; `refresh()` or query again when needed. Batch writes do not invoke lifecycle hooks.

The MySQL pre-query does not guarantee consistency against external concurrent writes across Sessions/processes, and `isNew()` is not a distributed lock. Use transactions, database constraints, and row locks for strict concurrency.

### 7.2 Atomic field increments and decrements

```php
$affected = Order::query()->where('id', 1)->increment('qty');              // qty = qty + 1
$affected = Order::query()->where('id', 1)->decrement('qty', 2);          // qty = qty - 2
$affected = Order::query()->where('id', 1)->increment('qty', 3, [
    'updated_at' => time(),                                                // Updated in the same UPDATE
]);
```

`increment()` and `decrement()` execute one parameterized SQL UPDATE and return affected rows. `$column` accepts a model property or real column name and must resolve to a non-primary-key `int` or `float` field. `$amount` must be a finite number greater than zero; int fields accept only int steps, preventing silent truncation. `$extra` uses the same field resolution, primary-key protection, and Caster conversion as `update()` and cannot include the target column through either its property or real column name.

These methods have the same WHERE and writable-query restrictions as conditional `update()`, retain the default soft-delete scope, and support `withTrashed()`. Models using `#[Version]` are rejected because a bulk delta cannot preserve per-entity version semantics. After execution, the model row cache is invalidated; reread or call `refresh()` when an updated entity is needed. Previously held entity instances are not changed in place, and lifecycle hooks are not invoked.

### 7.3 Cross-dialect UPSERT

```php
Order::upsert($values);                    // Default primary-key conflict; update other input fields
Order::upsert($values, ['qty']);           // Update only qty and maintain updated_at automatically
Order::upsert($values, []);                // Ignore conflicts
Order::upsert($values, ['qty'], ['key']);  // Explicit conflict key
```

- PostgreSQL/SQLite: `ON CONFLICT (...) DO UPDATE/DO NOTHING`;
- MySQL: `ON DUPLICATE KEY UPDATE`;
- The conflict key defaults to the model primary key and must be present in the input;
- Default update columns exclude conflict keys, generated columns, and `created_at`;
- Missing int `created_at/updated_at` values are populated automatically;
- Explicit `$updateColumns=['qty']` still adds `updated_at`; explicit timestamp values are respected;
- Missing non-nullable fields are rejected early. PHP property defaults and database `DEFAULT` are not guessed; provide values explicitly;
- Values use the model Caster. The return value is affected rows; generated IDs are not guaranteed to be populated, and entity hooks are not invoked.

**MySQL cannot specify a conflict target and responds to any unique-key conflict.** `$conflictColumns` only validates input completeness on MySQL; it does not limit SQL to a particular index. A surrogate generated key may be written only when explicitly used as the conflict key.

### 7.4 Row locks

```php
Order::transaction(function (): void {
    $order = Order::query()->where('id', 1)->forUpdate()->first();
    // Modify and save inside the transaction
});

$shared = Order::query()->where('id', 1)->forShare()->first();
```

PostgreSQL/MySQL support `FOR UPDATE/FOR SHARE`, and the Builder places locks and pagination according to each dialect. SQLite rejects them explicitly at execution instead of silently ignoring them. Locks should normally be used inside transactions. Subqueries, derived tables, UNION, and conditional writes cannot carry row locks.

## 8. Batch Insert

```php
// Array rows
User::insertAll([
    ['name' => 'A', 'age' => 20],
    ['name' => 'B', 'age' => 25],
]);

// Entity rows (hooks are invoked)
$a = new User(); $a->name = 'A'; $a->age = 20;
$b = new User(); $b->name = 'B'; $b->age = 25;
User::insertAll([$a, $b]);
// $a and $b receive created_at and become persisted
```

Arrays and entities may be mixed. SQL preserves input row order, with at most 500 rows per chunk and a maximum of 65535 bound parameters. Before the first INSERT, the whole batch undergoes all predictable checks: array fields and type conversion, entity model/new state/Gateway ownership, automatic time and version defaults, before-hooks, final values after hooks, and version validation. A bad row in a later chunk therefore does not allow earlier chunks to be written first, and a failed batch leaves no Gateway ownership on an unbound Entity.

`beforeSave()` / `beforeInsert()` may populate required fields and run once per Entity. Only after all SQL succeeds are generated keys populated, entities marked persisted, `afterSave()` / `afterInsert()` invoked, and cache published. Prevalidation failure does not pretend to undo before-hooks, automatic field values, or external side effects. Database constraint violations, SQL/connection failures, generated-key population failures, and after-hook failures likewise do not promise automatic atomic undo. Use an explicit transaction when the whole batch must be atomic. A successful transaction rollback restores persistent fields, uninitialized state, generated keys, versions, and cache identity to their state before before-hooks and automatic population; non-column business state and external side effects remain the application's responsibility.

Multi-row mixed or Entity-only batches with generated keys require `INSERT ... RETURNING`. MySQL rejects these calls before hooks or SQL; call `save()` row by row. Pure array rows need no generated-key population and can still be batch-inserted on MySQL.

## 9. Transactions

```php
User::transaction(function () {
    $a = new User();
    $a->name = 'Payer';
    $a->save();

    $b = new User();
    $b->name = 'Payee';
    $b->save();
});

// PdoConnection: nested levels use real SAVEPOINTs.
User::transaction(function () {
    // Outer native transaction.
    try {
        User::transaction(function () {
            // SAVEPOINT sp_2; an exceptional rollback undoes only this level.
            throw new DomainException('Undo inner writes');
        });
    } catch (DomainException) {
        // Once inner rollback succeeds, the outer transaction may continue and commit.
    }
});
```

`PdoConnection` implements `ManagedConnection`: depth 1 uses native PDO begin/commit/rollback; depth 2+ executes `SAVEPOINT sp_N`, `RELEASE SAVEPOINT sp_N`, or `ROLLBACK TO SAVEPOINT sp_N` followed by release. Releasing an inner savepoint is not an independent commit; an outer rollback still undoes all work. Do not mix unmanaged `$pdo->beginTransaction()` with this adapter; such mixing is rejected.

Each SAVEPOINT level has independent entity, cache, and identity snapshots. Inner rollback restores objects first read, changed, or inserted at that level, allowing the outer transaction to continue; outermost rollback restores the whole-chain baseline. A decorator implementing only legacy `Connection` need not implement `ManagedConnection`. Its nested `transaction()` reuses the outer callback without independent savepoint rollback. This compatibility path does not describe `PdoConnection` behavior.

`Session::discard()` calls whole-chain `forceRollback()` on a managed connection, not just the current SAVEPOINT. Even when a scope exits early inside a nested transaction, a connection with an unconfirmed result must not be returned to a pool.

Outermost rollback restores persistent fields, uninitialized state, `isNew()`, original snapshots, versions, and cache identity for surviving entities delivered by the current Gateway, including entities first read inside the transaction. Multiple objects with the same key return to their common pre-transaction baseline. Cache restoration prioritizes the pre-transaction canonical object rather than a later query copy. After rollback, old objects can be saved and newly inserted objects can be reinserted.

Duplicate query copies of a newly inserted row also become unpersisted after rollback, so an already-read generated key cannot bypass insertion protection. When an old row is deleted and reinserted: if the database reuses its old key, both the reinserted object and query copies return to the old-row baseline; if a new key is allocated, they become unpersisted as transaction-new rows. Non-`#[Column]` business properties and external hook side effects are not restored.

Memory is restored and the business exception propagated only after the database confirms successful rollback. A `false` return or exception from begin, failed commit, or failed rollback means an unknown outcome and throws `TransactionException`; never assume the physical transaction did not begin or commit. The exception exposes `operation` (`begin` / `commit` / `rollback`), `businessException`, and `previous`. A commit failure is not replaced by a later rollback failure. Once commit has been attempted, memory is not restored even if rollback succeeds.

After an unknown transaction outcome, do not query, write, transact, or reuse the original `Connection` through another Gateway or `Session::use()`. Discard the connection, Gateway, and associated entities. `Session::discard()` remains available to unbind the failed Session. A connection-ownership conflict before driver begin is a deterministic `LogicException` and does not poison the connection.

If a same-named session in the current context has an unfinished transaction, implicit rebinding by `Session::use()` / `Session::scope()` throws `LogicException`, even with the same Gateway. It does not roll back, clean transaction state, or release connection ownership; the original Session remains unchanged. Explicitly call `Session::discard()` and handle the rollback result first. Rebinding is not silent transaction cleanup.

## 10. Lifecycle Hooks

Override hook methods in an Entity subclass to implement business logic:

```php
final class Order extends Entity
{
    // ... field definitions ...

    public function beforeInsert(): void
    {
        $this->order_no = date('YmdHis') . random_int(1000, 9999);
    }

    public function beforeSave(): void
    {
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }
    }

    public function afterDelete(): void
    {
        // Clean related data, send notifications, and so on
    }
}
```

Available hooks (all `public function`):

| Hook | Timing |
|------|--------|
| `beforeSave()` | Before insert or update |
| `afterSave()` | After insert or update |
| `beforeInsert()` | Before insert only |
| `afterInsert()` | After insert only |
| `beforeUpdate()` | Before update only |
| `afterUpdate()` | After update only |
| `beforeDelete()` | Before delete |
| `afterDelete()` | After delete |

## 11. Row Cache

**Managed automatically in process.** Repeated `find()` calls for the same exact model class + primary key return the same object instance. Different models never share cache identity even if their key values match.

```php
$a = Asset::find(['user_id' => 1, 'key' => 'x']);
$b = Asset::find(['user_id' => 1, 'key' => 'x']);
// $a === $b (the same instance)
```

- Query results also populate the cache (entities returned by `Builder::get()`)
- A PHP ordered associative array provides a true O(1) LRU; read hits refresh recency, and default capacity is 1024 rows
- Conditional writes and deletes invalidate by exact model class, avoiding accidental changes to other models' live entities
- `delete()` automatically evicts the row. Each transaction level stores an independent snapshot; rollback restores that level's LRU and canonical object, then fills missing identities
- Cache is process-local, not cross-process; it is neither a cross-process consistency mechanism nor a distributed lock

## 12. Dirty Tracking

On update, `save()` generates SET clauses only for changed fields. It compares current values with the snapshot captured by `markPersisted()`. Scalars, null, and identical object references use a strict-equality fast path. Distinct object instances are normalized by the Caster to their database representation before comparison, avoiding loose object comparison affected by transient state.

```php
$u = User::find(['id' => 1]); // Snapshot: age=28, name='Alice'
$u->age = 29;
$u->save();
// SQL: UPDATE user SET age = :set_age WHERE id = :pk_id
// name is not written because it did not change

$invoice->amount = new Money($invoice->amount->cents);
$invoice->save(); // Equal new Money instance: no UPDATE and no version increment
```

Automatic `updated_at` handling and relation eager-load refreshes use the same semantics. With no field changes, no UPDATE is issued merely to increment `#[Version]`. Internal non-persistent properties of a value object should not affect the decision.

## 13. Primary-key Rules

| Rule | Description |
|------|-------------|
| Surrogate key | `#[PrimaryKey(generated: true)]`, exactly one, populated after insert |
| Natural key | `#[PrimaryKey(order: N)]`, optionally composite; caller supplies values |
| No mixing | One model cannot combine generated and order keys |
| Value boundaries | `0`, `""`, and `false` are valid key values; `null` is not |
| Complete identity | Any key-based operation missing a column is rejected before SQL |
| Non-nullable | A primary-key property cannot have nullable type such as `?int` |

## 14. Standalone Model Generator

`pixiaoka/orm` includes database schema inspection and model generation without webman. The generator connects only to the explicitly supplied database and writes models only under `--dir`.

```bash
# SQLite
vendor/bin/pixiaoka-orm generate \
    --dsn='sqlite:/absolute/path/app.sqlite' \
    --dir='/absolute/path/app/model/orm' \
    --namespace='App\\Model\\Orm'

# MySQL; for PostgreSQL, replace dsn with pgsql:host=...;port=5432;dbname=...
vendor/bin/pixiaoka-orm generate \
    --dsn='mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4' \
    --user=app --password=secret \
    --dir='/absolute/path/app/model/orm' \
    --namespace='App\\Model\\Orm' \
    --prefix=pc_ --force pc_order pc_order_item
```

- `--dsn`, `--dir`, and `--namespace` are required. Supply `--user` / `--password` as required by the driver, or use `ORM_DB_USER` and `ORM_DB_PASSWORD` to avoid exposing a password in process arguments.
- Valued options support both `--name=value` and `--name value`. Missing values, duplicate options, and unknown options produce clear errors and a nonzero exit status.
- With no table names, all user tables are generated; one or more table names restrict generation to those tables.
- `--prefix` strips only the class-name prefix; `table()` still returns the complete real table name.
- Existing files are preserved by default; only `--force` permits overwriting.
- Single-column auto-increment keys, composite natural keys, field nullability, scalar defaults, and common database type mappings are supported.
- Tables without primary keys are rejected. Batch generation renders and validates every table before creating directories and writing files, avoiding partial output.
- Dynamic database expressions such as `CURRENT_TIMESTAMP`, `NULL`, and object defaults never produce invalid PHP property initializers; insertion or the database supplies those values.
- Non-nullable scalars without defaults receive valid zero values, while nullable properties remain uninitialized to distinguish unassigned values from explicit `null`.
- Defaults are dialect-aware: MySQL metadata is decoded text, while SQLite/PostgreSQL contain SQL literals. PostgreSQL `E'...'` escapes are parsed separately without collapsing ordinary string backslashes.
- Only SQLite precision-free `datetime/timestamp` and PostgreSQL precision-free `timestamp without time zone` map to `DateTimeImmutable + #[Temporal]`. `DATE`, `TIME`, time zones, fractional seconds, and MySQL `datetime/timestamp` conservatively map to `string`.
- Table and column names use a restricted identifier policy to prevent injection through database identifiers or generated code. Explicitly inspect or rename unsupported names; never bypass this with concatenated SQL.
- The output directory must be an explicit application directory, not the filesystem root or current working directory.

For example, `pc_order` with `--prefix=pc_` generates `Order.php`:

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

Use the public PHP API directly in your own generation tools:

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
    tables: ['pc_order'], // [] means all user tables
);
// Both keys map table names to target paths:
// ['generated' => ['pc_order' => '/.../Order.php'], 'skipped' => []]

$tableSchema = (new SchemaInspector($pdo))->table('pc_order');
$code = $generator->render($tableSchema, 'App\\Model'); // Return code only; write nothing
```

`ModelGenerator::__construct(PDO $pdo, ?TypeMapper $types = null)`. `generate()` uses the arguments above and returns `array{generated: array<string, string>, skipped: array<string, string>}`. `render(TableSchema $table, string $namespace, ?string $class = null): string` can independently render previously inspected table metadata.

webman's `orm:gen` only reads framework configuration and invokes the same library API; it has no separate schema-inspection or code-rendering implementation. Run `php webman orm:gen [tables...]` with `--connection`, `--dir`, `--namespace`, `--prefix`, and `--force`. An output directory outside app requires explicit `--namespace`.

The generator rejects unsafe database identifiers, invalid namespaces, ambiguous output locations such as the root/current directory, and unwritable target files. Generated models extend `Pixiaoka\Orm\Entity` and use `#[Column]` and `#[PrimaryKey]`. Single-column auto-increment keys use `generated: true`, while composite keys preserve database declaration order.

Default-value boundaries: PHP 8.3 does not permit objects as property defaults, so Decimal, DateTime, and dynamic database expressions are never rendered as invalid initializers. Defaults that cannot be represented losslessly as static PHP are omitted. PostgreSQL Unicode escapes produce UTF-8 by code point; raw high bytes are validated and converted according to server encoding. With non-UTF-8 encodings, mixed escape/raw bytes, SQL_ASCII, unknown encodings, and invalid sequences are never guessed; a safe PHP fallback is used instead.

## 15. Metadata Cache

`MetadataReader` caches field metadata in process and can export/import plain PHP arrays. PHP-FPM deployments can reuse them through OPcache to reduce reflection cost. The cache contains no non-serializable objects such as `ReflectionProperty`.

```php
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;

// Build/deploy stage: warm the models actually used.
$reader = new MetadataReader();
foreach ([User::class, Article::class] as $model) {
    $reader->read($model);
}
$cache = $reader->export();
file_put_contents(
    '/path/to/orm-metadata.php',
    '<?php return ' . var_export($cache, true) . ';',
);

// Request bootstrap: the same Reader may be injected into multiple Gateways.
$metadata = new MetadataReader(require '/path/to/orm-metadata.php');
$gateway = new EntityGateway(new PdoConnection($pdo), $metadata);
```

The export format is `['version' => 1, 'models' => [model class => metadata array]]`. You may also call `$reader->import($cache)` on an existing Reader. Version, structural, or model-definition mismatches detected on first read throw `MetadataException`; regenerate the cache instead of manually assembling internal metadata. Relation metadata remains lazily read and validated. Model deployment and cache updates must stay on the same version; the cache is not an automatic cross-environment invalidation system.

## 16. Index Declarations and Schema Diffs

A model class may declare repeatable `#[Index]` attributes using property names or real column names. Array order determines index-column order:

```php
use Pixiaoka\Orm\Annotation\Index;

#[Index(['tenant_id', 'email'], name: 'uniq_tenant_email', unique: true)]
#[Index(['created_at'], name: 'idx_article_created_at')]
final class Article extends Entity
{
    // Field and primary-key definitions
}
```

Declarations participate only in metadata and schema comparison; normal queries never create indexes automatically. Inspect the live database schema to generate a diff report:

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

Supported drivers are `mysql`, `pgsql`, and `sqlite`. Comparisons cover added/removed fields, semantic types, nullability, and secondary indexes. Report structure:

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

There is no universal database-type inference for custom `Castable` classes. Supply a complete three-dialect mapping to the constructor. Built-in `Decimal` implementations default to decimal/text mappings and may also be overridden explicitly:

```php
$differ = new SchemaDiffer([
    Money::class => [
        'mysql' => 'bigint',
        'pgsql' => 'bigint',
        'sqlite' => 'integer',
    ],
]);
```

Changing a field type or nullability on SQLite requires manual table rebuilding, and the report marks those entries non-executable. If any unsupported entry exists, `previewSql()` throws rather than returning a seemingly complete but incomplete migration plan. Field removal, index rebuilding, constraints, data, and privileges must be reviewed by the application's migration process. **The Differ only generates reports and preview SQL. It never executes DDL and is not a replacement for a formal migration system.**

## 17. Observability

```php
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Observability\SessionEvent;

$unsubscribe = $gateway->subscribe(function (Event $event): void {
    if ($event instanceof SqlEvent) {
        // SQL, operation, model, durationMs, successful, parameterNames,
        // affectedRows/resultRows, exceptionClass, and context.
    }
    if ($event instanceof TransactionEvent) {
        // begin/commit/rollback; each managed SAVEPOINT level may also emit events.
        // Not every event represents an independent physical database commit.
    }
    if ($event instanceof SessionEvent) {
        // bind/discard/restore; successful indicates the cleanup result.
    }
});
```

You may also pass an object implementing `EventListener::onEvent(Event $event): void`. The returned closure idempotently unsubscribes. Listener exceptions do not change SQL or transaction outcomes, and nothing is logged by default. Nested events are discarded when the same Dispatcher is reentered. With no listeners, event construction and execution timing are skipped.

An observability callback must not reenter the same connection for ORM operations or change/clear the current business Session through `Session::use()`, `Session::scope()`, or `Session::discard()`. Nested events from an independent connection retain the outer protection and do not lift these restrictions early.

A completely independent Gateway/Connection may write directly to an observability database. Those writes neither join the business transaction nor guarantee atomic commit with it. Direct PDO/Connection calls are outside ORM reentry protection. `rollback successful=true` only means the rollback cleanup API succeeded; it does not prove that a previously failed commit had no effect.

`ContextInfo::$type` is root/fiber/coroutine, and `$id` is the process-local context object's `spl_object_id()`, not a cross-request or distributed trace ID. Events contain no parameter values, result rows, entities, connections, original exception objects, messages, or stacks. SQL text can still contain handwritten literal secrets and is not fully redacted. Coverage includes SQL, transactions, and Session operations passing through Gateway; direct application PDO/Connection and SchemaInspector calls are excluded. The optional `Clock` constructor argument supports deterministic timing tests.

## 18. Testing and Real-database Verification

```bash
# Previously verified baseline: 395 tests / 11109 assertions (4 explicit-DSN live tests skipped by default)
vendor/bin/phpunit

# SQLite / MySQL / PostgreSQL live verification; process environment overrides .env
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql tests/orm_live_verify.php
```

Use disposable Docker instances for MySQL/PostgreSQL. Never point these tests at production. The following ports are local examples; adjust them to your mappings:

```bash
docker run --name pixiaoka-orm-mysql-test \
  -e MYSQL_ROOT_PASSWORD=local-only-password -e MYSQL_DATABASE=orm_test \
  -p 127.0.0.1:32768:3306 -d mysql:8.4

docker run --name pixiaoka-orm-pgsql-test \
  -e POSTGRES_PASSWORD=local-only-password -e POSTGRES_DB=orm_test \
  -p 127.0.0.1:32770:5432 -d postgres:16-alpine

# Run after container health checks pass; these variables override .env
DB_PGSQL_HOST=127.0.0.1 DB_PGSQL_PORT=32770 \
DB_PGSQL_NAME=orm_test DB_PGSQL_USER=postgres DB_PGSQL_PASSWORD=local-only-password \
DB_MYSQL_HOST=127.0.0.1 DB_MYSQL_PORT=32768 \
DB_MYSQL_NAME=orm_test DB_MYSQL_USER=root DB_MYSQL_PASSWORD=local-only-password \
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql tests/orm_live_verify.php
```

`DB_MYSQL_HOST/PORT/NAME/USER/PASSWORD` and `DB_PGSQL_HOST/PORT/NAME/USER/PASSWORD` configure the two databases. If neither real database is reachable, the script falls back to SQLite. `orm_live_verify.php` uses random table names, tracks them by PDO, and removes only tables created by that run. SQLite fallback success is not a real MySQL/PostgreSQL pass. Afterwards, stop and remove these dedicated containers; do not reuse other application containers.

### Live model-generator tests

`tests/SchemaLiveTest.php` connects to external databases only when `PIXIAOKA_ORM_LIVE_MYSQL_DSN`, `PIXIAOKA_ORM_LIVE_PGSQL_DSN`, and their corresponding `_USER` and `_PASSWORD` variables are explicitly set. Otherwise it skips and never implicitly reads local services or `.env`.

```bash
PIXIAOKA_ORM_LIVE_MYSQL_DSN='mysql:host=127.0.0.1;port=32768;dbname=orm_test;charset=utf8mb4' \
PIXIAOKA_ORM_LIVE_MYSQL_USER=root PIXIAOKA_ORM_LIVE_MYSQL_PASSWORD=local-only-password \
PIXIAOKA_ORM_LIVE_PGSQL_DSN='pgsql:host=127.0.0.1;port=32770;dbname=orm_test' \
PIXIAOKA_ORM_LIVE_PGSQL_USER=postgres PIXIAOKA_ORM_LIVE_PGSQL_PASSWORD=local-only-password \
php -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=pdo_pgsql vendor/bin/phpunit
```

The test disables PDO emulated prepares and verifies auto-increment/composite keys, static and dynamic defaults, temporal types, generated-file `php -l`, and ORM metadata. It creates and cleans only random test tables.

The previously verified complete default SQLite suite in Docker PHP 8.3 was **395 tests / 11109 assertions / 4 skipped**, with zero PHPStan production-code errors. All four skips were existing MySQL/PostgreSQL live tests without explicit external DSNs; SQLite results are not presented as a real dual-database pass. Historical live results are retained as prior verification records, not represented as having been rerun for this documentation maintenance. CI continues to check multiple PHP versions, Composer, lint, and both database services. Real Swoole/Swow extension verification has not been performed.

## 19. Project Integration Example

This section illustrates integration into a separate application. The example assumes a PostgreSQL connection named `pgsql` in that application's `config/database.php`; it is not configuration shipped by this standalone library. A typical model migration is:

```php
// app/model/Product.php — old approach (extends BaseModel)
// Migrate to an ORM Entity:

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
    public string $price;  // For BcDecimal, use: public BcDecimal $price;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'pc_product';  // This example assumes prefix 'pc_' in config/database.php
    }
}
```

Initialization in webman middleware (illustrative pseudocode):

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
        // Construct once for a long-running webman worker
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

Usage in a controller:

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
            return json(['code' => 404, 'msg' => 'Product not found']);
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
