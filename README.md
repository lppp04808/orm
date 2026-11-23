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
- **Runtime ready:** Fiber/coroutine-aware named sessions, O(1) LRU row cache, metadata cache, and observability.

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
The model and the complete `Session::scope()` example are intentionally compact.

```php
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

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
Use independent PDO/Gateway instances for concurrent Fibers or coroutines.

## Documentation

Start with the topics you need; every link opens a section of the complete English guide.

| Topic | Guide |
|---|---|
| Installation, sessions, fibers, and connection pools | [Initialization](./USAGE.md#1-initialization) |
| Models, casts, timestamps, versions, and soft deletion | [Defining models](./USAGE.md#2-defining-models) |
| Relations, eager loading, filters, and counts | [Read-only relations](./USAGE.md#3-readonly-relations-and-eager-loading) |
| Create, read, update, delete, and refresh | [Active Record](./USAGE.md#4-active-record) |
| Conditions, ordering, pagination, and query cloning | [Query Builder](./USAGE.md#5-query-builder) |
| Joins, aggregates, subqueries, UNION, and raw SQL | [Advanced queries](./USAGE.md#6-advanced-queries) |
| Conditional writes, UPSERT, and row locks | [Conditional writes](./USAGE.md#7-conditional-update--delete-upsert-and-row-locks) |
| Nested transactions and rollback guarantees | [Transactions](./USAGE.md#9-transactions) |
| Database inspection and model generation | [Model generator](./USAGE.md#14-standalone-model-generator) |
| Index declarations and non-executing schema diffs | [Schema diffs](./USAGE.md#16-index-declarations-and-schema-diffs) |
| SQL, transaction, and session events | [Observability](./USAGE.md#17-observability) |
| Local and real-database verification | [Testing](./USAGE.md#18-testing-and-realdatabase-verification) |
| Long-running stability and resource-boundary exercises | [SOAK.md](./SOAK.md) |

For Chinese, read the [Chinese home page](./README.zh-CN.md) or [complete Chinese guide](./USAGE.zh-CN.md).

## Local Tests

Install development dependencies before running the checks:

```bash
composer install
composer test
composer analyse
```

`composer test` runs PHPUnit; `composer analyse` runs PHPStan against production code.
See the [real-database verification guide](./USAGE.md#18-testing-and-realdatabase-verification) for MySQL/PostgreSQL.

## License

Released under the [MIT License](./LICENSE).
