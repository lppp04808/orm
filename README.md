# pixiaoka/orm

[![CI](https://github.com/lppp04808/orm/actions/workflows/ci.yml/badge.svg)](https://github.com/lppp04808/orm/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/pixiaoka/orm.svg)](https://packagist.org/packages/pixiaoka/orm)
[![PHP Version](https://img.shields.io/packagist/php-v/pixiaoka/orm.svg)](https://packagist.org/packages/pixiaoka/orm)
[![License](https://img.shields.io/packagist/l/pixiaoka/orm.svg)](./LICENSE)

**English** | [简体中文](./README.zh-CN.md)

## Overview

An attribute-driven Active Record ORM for PHP 8.3+, built on PDO with zero runtime package dependencies.

- **Strict persistence types:** native PHP properties, JSON, backed enums, Decimal, and custom `Castable` values.
- **Flexible identities:** generated keys and ordered composite natural keys with complete-key validation.
- **Efficient writes:** semantic dirty tracking, optimistic locking, soft deletion, UPSERT, and batch inserts.
- **Safe transactions:** nested SAVEPOINTs and rollback restoration for fields, snapshots, and cached identities.
- **Predictable queries:** read-only relations, eager loading, relation filters, advanced SQL, and raw results.
- **Runtime ready:** Fiber/coroutine-aware named sessions, O(1) LRU row/statement caches, metadata cache, and observability.

The library does not require a framework, create PDO connections implicitly, or impose a connection pool.
Schema inspection, model generation, and schema-diff previews are included for development workflows.
Read the full guide for operational boundaries, concurrency rules, and database-specific behavior.

## Requirements

- PHP **8.3 or newer**.
- `ext-pdo` and the PDO driver for your database.
- SQLite, MySQL, or PostgreSQL for the corresponding dialect features.
- `ext-pdo_sqlite` to run the default local test suite.
- Composer to install the package and development tools.

Install the library in your application:

```bash
composer require pixiaoka/orm
```

The package has no third-party runtime Composer dependencies.
It exposes the `Pixiaoka\Orm\` namespace and the `pixiaoka-orm` model-generator command.

## 30-second Quick Start

The following example uses an in-memory SQLite database; create your own schema in real applications.
After installing the package, save this example as `quick-start.php` in your Composer project root and run `php quick-start.php`.

```php
<?php

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

require __DIR__ . '/vendor/autoload.php';

final class User extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;
    #[Column]
    public string $name;
    #[Column]
    public bool $active = true;
    public static function table(): string { return 'user'; }
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, active INTEGER)');
Session::scope(new EntityGateway(new PdoConnection($pdo)), function (): void {
    $user = new User();
    $user->name = 'Alice';
    $user->save();                              // Create

    $found = User::find(['id' => $user->id]);  // Read
    $found->name = 'Alice Updated';
    $found->save();                            // Update dirty fields

    $active = User::query()->where('active', true)->get(); // Query
    User::transaction(function () use ($found): void {
        $found->delete();                      // Delete atomically
    });
});
```

`Session::scope()` restores the previous session on return or exception.
Bind an independent PDO/Gateway inside each Fiber or coroutine. `Session::get()` only looks in the current context.

## Documentation

Start with the topics you need; every link opens a section of the complete English guide.

| Topic | Guide |
|---|---|
| Installation, sessions, fibers, and connection pools | [Initialization](./USAGE.md#1-initialization) |
| PDOStatement cache configuration and lifecycle | [Statement cache](./USAGE.md#statement-cache) |
| Models, casts, timestamps, versions, and soft deletion | [Defining models](./USAGE.md#2-defining-models) |
| Relations, eager loading, filters, and counts | [Read-only relations](./USAGE.md#3-read-only-relations-and-eager-loading) |
| Create, read, update, delete, and refresh | [Active Record](./USAGE.md#4-active-record) |
| Conditions, ordering, pagination, and query cloning | [Query Builder](./USAGE.md#5-query-builder) |
| Joins, aggregates, subqueries, UNION, and raw SQL | [Advanced queries](./USAGE.md#6-advanced-queries) |
| Conditional writes, UPSERT, and row locks | [Conditional writes](./USAGE.md#7-conditional-update--delete-upsert-and-row-locks) |
| Batch inserts and models with only a generated key | [Batch insert](./USAGE.md#8-batch-insert) |
| Nested transactions and rollback guarantees | [Transactions](./USAGE.md#9-transactions) |
| Persisted identity and composite keys | [Primary-key rules](./USAGE.md#13-primary-key-rules) |
| Database inspection and model generation | [Model generator](./USAGE.md#14-standalone-model-generator) |
| Index declarations and non-executing schema diffs | [Schema diffs](./USAGE.md#16-index-declarations-and-schema-diffs) |
| SQL, transaction, and session events | [Observability](./USAGE.md#17-observability) |
| Local and real-database verification | [Testing](./USAGE.md#18-testing-and-real-database-verification) |

For Chinese, read the [Chinese home page](./README.zh-CN.md) or [complete Chinese guide](./USAGE.zh-CN.md).

## PDO, ORM, and statement-cache benchmark

A sequential local Docker snapshot with PHP 8.3.13, native prepares, 500 rows, one warmup, and five measured rounds. SQLite is in-memory; MySQL 8.4.13 and PostgreSQL 16.16 use separate local containers. Values are median milliseconds, not production guarantees.

Native PDO and full ORM paths with statement cache 64:

| Scenario | SQLite 3.45.3 PDO / ORM | MySQL PDO / ORM | PostgreSQL PDO / ORM |
|---|---:|---:|---:|
| 500 array reads | 0.136 / 0.180 | 0.367 / 0.504 | 0.515 / 0.565 |
| 500 entities | — / 2.439 | — / 2.620 | — / 2.684 |
| 500 PK reads | 1.880 / 6.334 | 75.368 / 47.457 | 101.454 / 51.947 |
| 200 atomic increments | 0.591 / 1.866 | 37.193 / 22.720 | 46.725 / 20.439 |

The PDO PK path fetches arrays. The ORM benchmark clears the row cache before each `Entity::find()` to measure cache misses and entity hydration. ORM increments use `Builder::increment()`, while the PDO reference executes equivalent parameterized SQL. Absolute times across different database backends are not comparable as ORM overhead.

The same low-level SQL, with a new connection wrapper per round:

| Scenario | Backend | Native PDO | Cache 0 | Cache 64 |
|---|---|---:|---:|---:|
| 500 PK array reads | SQLite | 1.880 ms | 1.893 ms | 0.574 ms |
| 200 updates | SQLite | 0.591 ms | 0.646 ms | 0.255 ms |
| 500 array reads | SQLite | 0.136 ms | 0.129 ms | 0.130 ms |
| 500 PK array reads | MySQL | 75.368 ms | 66.075 ms | 31.825 ms |
| 200 updates | MySQL | 37.193 ms | 37.159 ms | 21.118 ms |
| 500 array reads | MySQL | 0.367 ms | 0.397 ms | 0.351 ms |
| 500 PK array reads | PostgreSQL | 101.454 ms | 101.853 ms | 36.194 ms |
| 200 updates | PostgreSQL | 46.725 ms | 43.159 ms | 16.884 ms |
| 500 array reads | PostgreSQL | 0.515 ms | 0.454 ms | 0.419 ms |

Reproduce with `php bench/statement_cache.php sqlite|mysql|pgsql`; external databases use `BENCH_DSN`, `BENCH_USER`, and `BENCH_PASSWORD`. PostgreSQL retained one server-side prepared statement across repeated executions. The tested pdo_mysql mysqlnd driver still issued server PREPARE after cursor cleanup even when reusing the same statement object; these timings do not prove eliminated MySQL server PREPARE round trips. See the [statement-cache guide](./USAGE.md#statement-cache) for configuration and lifecycle.

## Local Tests

Install development dependencies before running the checks:

```bash
composer install
composer test
composer analyse
```

`composer test` runs PHPUnit; `composer analyse` runs PHPStan against production code.
See the [real-database verification guide](./USAGE.md#18-testing-and-real-database-verification) for MySQL/PostgreSQL.

## License

Released under the [MIT License](./LICENSE).
