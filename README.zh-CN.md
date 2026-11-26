# pixiaoka/orm

[![CI](https://github.com/lppp04808/orm/actions/workflows/ci.yml/badge.svg)](https://github.com/lppp04808/orm/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/pixiaoka/orm.svg)](https://packagist.org/packages/pixiaoka/orm)
[![PHP Version](https://img.shields.io/packagist/php-v/pixiaoka/orm.svg)](https://packagist.org/packages/pixiaoka/orm)
[![License](https://img.shields.io/packagist/l/pixiaoka/orm.svg)](./LICENSE)

[English](./README.md) | **简体中文**

## 项目概览

面向 PHP 8.3+ 的注解驱动 Active Record ORM，基于 PDO，零第三方运行时包依赖。

- **严格持久化类型：** 原生 PHP 属性、JSON、回退枚举、Decimal 与自定义 `Castable` 值对象。
- **灵活身份规则：** 生成主键与有序复合自然主键，并校验身份完整性。
- **高效安全写入：** 语义脏跟踪、乐观锁、软删除、UPSERT 和批量插入。
- **可靠事务：** 嵌套 SAVEPOINT，回滚恢复字段、原始快照与缓存对象身份。
- **可预测查询：** 只读关系、显式预加载、关联过滤、复杂 SQL 和原始数组结果。
- **适配常驻运行时：** Fiber/协程感知的命名会话、O(1) LRU 行缓存与语句缓存、元数据缓存及可观测性。

核心库不依赖框架、不隐式创建 PDO，也不强制使用连接池。
内置结构读取、模型生成与 Schema 差异预览，覆盖日常开发工作流。
完整手册详细说明运行边界、并发规则和各数据库方言的差异。

## 环境要求

- PHP **8.3 或更高版本**。
- `ext-pdo` 以及所用数据库对应的 PDO 驱动。
- SQLite、MySQL 或 PostgreSQL，用于相应方言功能。
- 运行默认本地测试套件需要 `ext-pdo_sqlite`。
- 使用 Composer 安装包和开发工具。

在应用中安装：

```bash
composer require pixiaoka/orm
```

本包没有第三方运行时 Composer 依赖。
它提供 `Pixiaoka\Orm\` 命名空间和 `pixiaoka-orm` 模型生成命令。

## 30 秒快速开始

下面使用内存 SQLite 演示；实际应用请自行创建并维护表结构。
安装包后，把示例保存为 Composer 项目根目录下的 `quick-start.php`，执行 `php quick-start.php`。

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
    $user->save();                              // 创建

    $found = User::find(['id' => $user->id]);  // 按主键读取
    $found->name = 'Alice Updated';
    $found->save();                            // 只更新脏字段

    $active = User::query()->where('active', true)->get(); // 条件查询
    User::transaction(function () use ($found): void {
        $found->delete();                      // 在事务中删除
    });
});
```

`Session::scope()` 在正常返回或异常时都会恢复此前的会话。
并发 Fiber 或协程必须使用各自独立的 PDO/Gateway 实例。

## 文档导航

按需选择主题；以下链接均打开中文完整手册中的具体章节。

| 主题 | 手册章节 |
|---|---|
| 安装初始化、会话、Fiber 与连接池 | [初始化](./USAGE.zh-CN.md#1-初始化) |
| PDOStatement 缓存配置与生命周期 | [语句缓存](./USAGE.zh-CN.md#语句缓存) |
| 模型、类型转换、时间、版本与软删除 | [定义模型](./USAGE.zh-CN.md#2-定义模型) |
| 关系、预加载、关联过滤与计数 | [只读关系与预加载](./USAGE.zh-CN.md#3-只读关系与预加载) |
| 创建、读取、更新、删除与刷新 | [Active Record](./USAGE.zh-CN.md#4-active-record) |
| 条件、排序、分页与查询克隆 | [查询构建器](./USAGE.zh-CN.md#5-查询构建器) |
| JOIN、聚合、子查询、UNION 与原生 SQL | [复杂查询](./USAGE.zh-CN.md#6-复杂查询) |
| 条件写、UPSERT 与行锁 | [条件写入](./USAGE.zh-CN.md#7-条件-update--deleteupsert-与行锁) |
| 批量插入与仅有生成主键的模型 | [批量插入](./USAGE.zh-CN.md#8-批量插入) |
| 嵌套事务与回滚保证 | [事务](./USAGE.zh-CN.md#9-事务) |
| 已持久化实体身份与复合主键 | [主键规则](./USAGE.zh-CN.md#13-主键规则) |
| 数据库结构读取与模型生成 | [独立模型生成器](./USAGE.zh-CN.md#14-独立模型生成器) |
| 索引声明与绝不自动执行的 Schema 差异 | [Schema 差异对比](./USAGE.zh-CN.md#16-索引声明与-schema-差异对比) |
| SQL、事务与会话事件 | [可观测性](./USAGE.zh-CN.md#17-可观测性) |
| 本地与真实数据库验收 | [测试与验收](./USAGE.zh-CN.md#18-测试与真实数据库验收) |

英文阅读请访问 [英文首页](./README.md) 或 [英文完整手册](./USAGE.md)。

## PDO、ORM 与语句缓存基准

本机 Docker 顺序测量快照：PHP 8.3.13、原生预处理、500 行数据、1 轮预热和 5 轮测量。SQLite 使用内存库；MySQL 8.4.13 和 PostgreSQL 16.16 使用独立本地容器。数值为毫秒中位数，不代表生产环境容量保证。

原生 PDO 与启用 64 条语句缓存的完整 ORM 路径：

| 场景 | SQLite 3.45.3 PDO / ORM | MySQL PDO / ORM | PostgreSQL PDO / ORM |
|---|---:|---:|---:|
| 500 行数组读取 | 0.136 / 0.180 | 0.367 / 0.504 | 0.515 / 0.565 |
| 500 个实体读取 | — / 2.439 | — / 2.620 | — / 2.684 |
| 500 次主键读取 | 1.880 / 6.334 | 75.368 / 47.457 | 101.454 / 51.947 |
| 200 次原子自增 | 0.591 / 1.866 | 37.193 / 22.720 | 46.725 / 20.439 |

PDO 主键路径读取数组。ORM 基准在每次 `Entity::find()` 前清空行缓存，测量缓存未命中时的实体水合路径。ORM 自增使用 `Builder::increment()`，PDO 对照执行等价的参数化 SQL。不同后端的绝对耗时不能直接解释为 ORM 本身的算法开销。

同一低层 SQL 的连接层对照，每轮使用新的连接封装：

| 场景 | 后端 | 原生 PDO | 缓存 0 | 缓存 64 |
|---|---|---:|---:|---:|
| 500 次主键数组读取 | SQLite | 1.880 ms | 1.893 ms | 0.574 ms |
| 200 次更新 | SQLite | 0.591 ms | 0.646 ms | 0.255 ms |
| 500 行数组读取 | SQLite | 0.136 ms | 0.129 ms | 0.130 ms |
| 500 次主键数组读取 | MySQL | 75.368 ms | 66.075 ms | 31.825 ms |
| 200 次更新 | MySQL | 37.193 ms | 37.159 ms | 21.118 ms |
| 500 行数组读取 | MySQL | 0.367 ms | 0.397 ms | 0.351 ms |
| 500 次主键数组读取 | PostgreSQL | 101.454 ms | 101.853 ms | 36.194 ms |
| 200 次更新 | PostgreSQL | 46.725 ms | 43.159 ms | 16.884 ms |
| 500 行数组读取 | PostgreSQL | 0.515 ms | 0.454 ms | 0.419 ms |

可运行 `php bench/statement_cache.php sqlite|mysql|pgsql` 复测；外部数据库通过 `BENCH_DSN`、`BENCH_USER`、`BENCH_PASSWORD` 配置。PostgreSQL 在重复执行期间保留了一个服务端预备语句。实测 pdo_mysql 的 mysqlnd 驱动即使复用同一语句对象，在游标清理后仍会发起服务端 PREPARE；这些耗时不能证明消除了 MySQL 服务端 PREPARE 往返。配置与生命周期见[语句缓存手册](./USAGE.zh-CN.md#语句缓存)。

## 本地测试

先安装开发依赖，再执行检查：

```bash
composer install
composer test
composer analyse
```

`composer test` 运行 PHPUnit；`composer analyse` 对生产代码运行 PHPStan。
MySQL/PostgreSQL 配置见 [真实数据库验收](./USAGE.zh-CN.md#18-测试与真实数据库验收)。

## License

基于 [MIT License](./LICENSE) 发布。
