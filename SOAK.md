# 真实数据库长期浸泡测试

`tests/orm_soak.php` 是开发验证设施，不是 ORM 生产运行时组件。它用多个独立 PHP 进程对真实 MySQL/PostgreSQL 并发执行 Active Record 操作，验证事务、乐观锁、关联查询、连接轮换和长期资源趋势。

## 能证明什么

每次运行必须显式提供测试 DSN，工具不会默认连接本机数据库，也不会用 SQLite 替代真实双库结果。

- 转账事务：同一事务中两个带 `#[Version]` 的账户更新，并写入唯一操作效果。
- 主动/异常回滚：操作意图独立保存；账户更新和效果写入必须整体回滚，并检查实体字段、版本与 original 快照恢复。
- 乐观锁竞争：实际并发更新和陈旧版本写入，冲突分类记录，不能伪装成成功提交。
- 关联读取：`whereHas()`、`whereDoesntHave()`、`whereRelation()`、`withCount()` 与同一数据库一致性快照中的原始 SQL 对照。
- 连接轮换：周期性新建 PDO/Gateway，保留进程内缓存边界；不是 Swoole/Swow 或连接池验收。
- 独立审计：独立 PDO 在一致性快照中检查资金守恒、每个账户余额、版本、操作效果与 journal。
- 资源趋势：分别记录 `memory_get_usage(false/true)` 和峰值；检查预热后增长阈值，不要求内存永不增长。

正常完成的硬条件：

1. 达到计划时长，所有 worker 正常退出，非预期异常、worker 非零退出和心跳超时为零。
2. 每个数据库都有实际的提交、两类回滚、乐观锁冲突、关系校验、审计和连接轮换记录。
3. 最终账户数、余额、版本、操作意图和效果逐条一致；资金总额严格不变。
4. 每个 worker 至少三个内存采样，预热后的增长不超过配置阈值。
5. 最后审计通过且报告可读。报告会记录计划时长和实际时长；几十秒短跑不能称为长期通过。

## 运行

```bash
export PIXIAOKA_ORM_SOAK_MYSQL_DSN='mysql:host=mysql;dbname=orm_test;charset=utf8mb4'
export PIXIAOKA_ORM_SOAK_MYSQL_USER=test
export PIXIAOKA_ORM_SOAK_MYSQL_PASSWORD=secret
export PIXIAOKA_ORM_SOAK_PGSQL_DSN='pgsql:host=postgres;dbname=orm_test'
export PIXIAOKA_ORM_SOAK_PGSQL_USER=test
export PIXIAOKA_ORM_SOAK_PGSQL_PASSWORD=secret

# 60 秒短跑，仅用于验证工具及近期回归。
php tests/orm_soak.php --duration=60 --workers=2 --sample=5 --audit-interval=20

# 示例：30 分钟，每库 4 个独立进程。
php tests/orm_soak.php --duration=1800 --workers=4 --sample=10 --audit-interval=60

# 过夜示例：8 小时；实际结论以 summary.json 的实际时长和审计为准。
php tests/orm_soak.php --duration=28800 --workers=4 --sample=10 --audit-interval=60
```

可用参数：

| 参数 | 默认值 | 含义 |
| --- | ---: | --- |
| `--duration` | 1800 | 计划负载时长（秒），至少 10 |
| `--workers` | 4 | 每个数据库的独立 PHP 进程数 |
| `--drivers` | mysql,pgsql | 真实数据库，禁止空集合；故障注入应指定单库 |
| `--sample` | 10 | worker 心跳和内存采样周期（秒） |
| `--audit-interval` | 60 | 独立数据库审计周期（秒） |
| `--rotate` | 25 | 每多少次操作轮换 PDO/Gateway |
| `--accounts` | 12 | 转账账户数，另有两个专用乐观锁账户 |
| `--delay-ms` | 10 | 操作间隔，控制数据库负载 |
| `--memory-growth` | 67108864 | 预热后内存增长阈值（字节），默认 64 MiB |
| `--heartbeat-timeout` | 90 | 无日志活动多久判定 worker 卡死 |
| `--grace` | 30 | 停止后等待 worker 退出的宽限期 |
| `--seed` | 20261120 | 负载随机种子；不同 worker 有独立派生种子 |
| `--output` | build/soak | 报告根目录 |
| `--fault` | none | `ledger` 或 `worker_crash`，仅用于证明测试器能失败 |

运行使用固定操作权重：45% 转账提交、15% 主动回滚、10% 异常回滚、10% 强制乐观锁冲突、20% 关联对照。自然并发冲突和数据库锁冲突另行统计。锁等待/死锁会分类记录，不是 ORM 静默成功；提交结果未知、断连、非预期 SQL/PHP 异常仍判失败。本版本没有把数据库重启、网络中断和提交结果未知注入标为通过。

## 故障注入

```bash
# 故意破坏余额守恒：必须 ok=false，并在审计中出现 conservation/balance 等失败。
php tests/orm_soak.php --drivers=mysql --duration=20 --workers=1 --fault=ledger

# 在提交已确认后杀死 worker：必须 ok=false、worker_exit=70；
# 最终 journal 仍应包含该事务效果，不能把它误判为回滚。
php tests/orm_soak.php --drivers=mysql --duration=20 --workers=1 --fault=worker_crash
```

故障注入的 `expected_failure=true` 只表示测试器按预期发现了故障，绝不表示 ORM 通过正常运行。

## 报告与安全

每次运行目录包含：

- `summary.json`：计划/实际时长、退出原因、各 worker 计数、内存趋势、审计和失败列表。
- `events.jsonl`：主控生命周期和独立审计。
- `worker-*.jsonl`：各 worker 独立追加的操作、心跳和异常事件。
- `*.stdout.log` / `*.stderr.log`：子进程原始输出。

含 DSN/密码的临时配置文件权限尽量设为仅当前用户可读写，并在收尾删除，不纳入长期报告。表名使用 `orm_soak_<随机十六进制>_` 前缀，启动时拒绝覆盖同名表，只 DROP 本次登记创建的三张表。主控突然被杀时无法保证数据库清理；可根据报告中的精确前缀人工检查，不要批量删除其他表。

生产 DSN 即使能连接也不应使用。请使用独立的 MySQL/PostgreSQL 测试实例和专用低权限账号。测试进程需要 `proc_open`，不支持在禁用进程创建的 PHP-FPM 请求里运行。

## 边界

此工具验证真实进程并发和 PDO 连接轮换，不证明真实 Swoole/Swow 扩展、某个业务连接池、多进程 RowCache 一致性、所有 SQL 方言或任意业务负载的生产稳定性。内存阈值是回归信号，不是严格的堆泄漏证明。长期结论必须结合实际运行目录、持续时长、负载规模和审计记录解读。
