<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Soak;

use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;

final class SoakAccount extends Entity
{
    public static string $tableName = '';

    #[Column, PrimaryKey]
    public int $id;

    #[Column]
    public int $balance;

    #[Column, Version]
    public int $revision;

    #[Column]
    public string $label;

    public static function table(): string
    {
        return self::$tableName;
    }

    #[HasManyAttribute(target: SoakTransferEffect::class, foreignKey: ['from_account'])]
    public function outgoing(): HasMany
    {
        return $this->makeRelation();
    }
}

final class SoakOperationIntent extends Entity
{
    public static string $tableName = '';

    #[Column('op_id'), PrimaryKey]
    public string $operationId;

    #[Column]
    public int $worker_id;

    #[Column]
    public string $kind;

    #[Column]
    public ?int $from_account = null;

    #[Column]
    public ?int $to_account = null;

    #[Column]
    public ?int $amount = null;

    #[Column]
    public int $created_at;

    public static function table(): string
    {
        return self::$tableName;
    }
}

final class SoakTransferEffect extends Entity
{
    public static string $tableName = '';

    #[Column('op_id'), PrimaryKey]
    public string $operationId;

    #[Column]
    public int $from_account;

    #[Column]
    public int $to_account;

    #[Column]
    public int $amount;

    #[Column]
    public int $created_at;

    public static function table(): string
    {
        return self::$tableName;
    }

    #[BelongsToAttribute(target: SoakAccount::class, localKey: ['from_account'])]
    public function source(): BelongsTo
    {
        return $this->makeRelation();
    }

    #[BelongsToAttribute(target: SoakAccount::class, localKey: ['to_account'])]
    public function destination(): BelongsTo
    {
        return $this->makeRelation();
    }
}

final class SoakRollback extends \RuntimeException
{
}

final class SoakTables
{
    /** @var array<string, list<string>> */
    private static array $created = [];

    public static function configure(string $prefix): void
    {
        if (preg_match('/^orm_soak_[a-f0-9]{12}_$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('soak 表前缀必须为工具生成的随机值');
        }
        SoakAccount::$tableName = $prefix . 'account';
        SoakOperationIntent::$tableName = $prefix . 'intent';
        SoakTransferEffect::$tableName = $prefix . 'effect';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return [SoakAccount::table(), SoakOperationIntent::table(), SoakTransferEffect::table()];
    }

    public static function quote(\PDO $pdo, string $table): string
    {
        if (!in_array($table, self::names(), true)) {
            throw new \InvalidArgumentException('不允许操作未登记的 soak 表');
        }
        $quoted = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';

        return $quoted . $table . $quoted;
    }

    public static function create(\PDO $pdo, int $accounts): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('真实 soak 仅支持 MySQL/PostgreSQL');
        }
        $account = self::quote($pdo, SoakAccount::table());
        $intent = self::quote($pdo, SoakOperationIntent::table());
        $effect = self::quote($pdo, SoakTransferEffect::table());
        $string = $driver === 'mysql' ? 'VARCHAR(64)' : 'VARCHAR(64)';
        $id = $driver === 'mysql' ? 'CHAR(40)' : 'VARCHAR(40)';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE {$account} (
            id INT PRIMARY KEY,
            balance BIGINT NOT NULL,
            revision BIGINT NOT NULL,
            label {$string} NOT NULL
        ){$engine}");
        self::$created[$driver][] = SoakAccount::table();
        $pdo->exec("CREATE TABLE {$intent} (
            op_id {$id} PRIMARY KEY,
            worker_id INT NOT NULL,
            kind {$string} NOT NULL,
            from_account INT NULL,
            to_account INT NULL,
            amount INT NULL,
            created_at BIGINT NOT NULL
        ){$engine}");
        self::$created[$driver][] = SoakOperationIntent::table();
        $pdo->exec("CREATE TABLE {$effect} (
            op_id {$id} PRIMARY KEY,
            from_account INT NOT NULL,
            to_account INT NOT NULL,
            amount INT NOT NULL,
            created_at BIGINT NOT NULL,
            FOREIGN KEY (op_id) REFERENCES {$intent}(op_id),
            FOREIGN KEY (from_account) REFERENCES {$account}(id),
            FOREIGN KEY (to_account) REFERENCES {$account}(id)
        ){$engine}");
        self::$created[$driver][] = SoakTransferEffect::table();
        $indexFrom = SoakAccount::$tableName . '_from';
        $indexTo = SoakAccount::$tableName . '_to';
        $quote = $driver === 'mysql' ? '`' : '"';
        $pdo->exec('CREATE INDEX ' . $quote . $indexFrom . $quote . ' ON ' . $effect . ' (from_account)');
        $pdo->exec('CREATE INDEX ' . $quote . $indexTo . $quote . ' ON ' . $effect . ' (to_account)');

        for ($id = 1; $id <= $accounts + 2; $id++) {
            $entity = new SoakAccount();
            $entity->id = $id;
            $entity->balance = 1000;
            $entity->revision = 1;
            $entity->label = $id <= $accounts ? 'transfer' : 'contention';
            $entity->save();
        }
    }

    public static function dropCreated(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        foreach (array_reverse(self::names()) as $table) {
            if (in_array($table, self::$created[$driver] ?? [], true)) {
                $pdo->exec('DROP TABLE IF EXISTS ' . self::quote($pdo, $table));
            }
        }
        self::$created[$driver] = [];
    }

    /**
     * 在同一数据库快照中执行原始 SQL 和 ORM 查询，再统一提交或回滚。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function readOnlySnapshot(\PDO $pdo, callable $callback): mixed
    {
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('只读 soak 快照不能嵌套在已有事务中');
        }
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            // MySQL 必须在 START TRANSACTION 前指定隔离级别，READ ONLY 是启动选项。
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION READ ONLY');
        } elseif ($driver === 'pgsql') {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        } else {
            throw new \InvalidArgumentException('真实 soak 快照仅支持 MySQL/PostgreSQL');
        }
        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 使用一个数据库一致性快照完成原始账本和 ORM 关系查询对照。
     * @return array<string, mixed>
     */
    public static function audit(\PDO $pdo, int $accounts, bool $final): array
    {
        $snapshot = self::readOnlySnapshot($pdo, static function () use ($pdo, $accounts): array {
            $effectTable = self::quote($pdo, SoakTransferEffect::table());
            $accountRows = $pdo->query('SELECT * FROM ' . self::quote($pdo, SoakAccount::table()) . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
            $intentRows = $pdo->query('SELECT * FROM ' . self::quote($pdo, SoakOperationIntent::table()) . ' ORDER BY op_id')->fetchAll(\PDO::FETCH_ASSOC);
            $effectRows = $pdo->query('SELECT * FROM ' . $effectTable . ' ORDER BY op_id')->fetchAll(\PDO::FETCH_ASSOC);
            $existenceRows = $pdo->query('SELECT from_account AS id, '
                . 'EXISTS(SELECT 1 FROM ' . $effectTable . ' AS e WHERE e.from_account = a.from_account) AS has_outgoing, '
                . 'EXISTS(SELECT 1 FROM ' . $effectTable . ' AS e WHERE e.from_account = a.from_account AND e.amount >= 10) AS has_large '
                . 'FROM (SELECT DISTINCT from_account FROM ' . $effectTable . ') AS a')->fetchAll(\PDO::FETCH_ASSOC);
            $rawExists = [];
            $rawConstrained = [];
            foreach ($existenceRows as $row) {
                if ((int) $row['has_outgoing'] === 1) {
                    $rawExists[] = (int) $row['id'];
                }
                if ((int) $row['has_large'] === 1) {
                    $rawConstrained[] = (int) $row['id'];
                }
            }
            $ormCounts = SoakAccount::query()->whereOp('id', '<=', $accounts)->withCount(['outgoing', 'outgoing.source', 'outgoing.destination'])->orderBy('id')->rows();
            $ormHas = SoakAccount::query()->whereOp('id', '<=', $accounts)->whereHas('outgoing')->orderBy('id')->rows();
            $ormNone = SoakAccount::query()->whereOp('id', '<=', $accounts)->whereDoesntHave('outgoing')->orderBy('id')->rows();
            $ormNestedNone = SoakAccount::query()->whereOp('id', '<=', $accounts)->whereDoesntHave('outgoing.source')->orderBy('id')->rows();
            $ormRelation = SoakAccount::query()->whereOp('id', '<=', $accounts)->whereRelation('outgoing', 'amount', '>=', 10)->orderBy('id')->rows();
            $ormConstrainedNone = SoakAccount::query()->whereOp('id', '<=', $accounts)->whereDoesntHave('outgoing', static fn ($query) => $query->whereOp('amount', '>=', 10))->orderBy('id')->rows();
            if (count($ormCounts) !== $accounts) {
                throw new \RuntimeException('关系审计账户行数不完整');
            }

            return [
                'accountRows' => $accountRows,
                'intentRows' => $intentRows,
                'effectRows' => $effectRows,
                'rawExists' => $rawExists,
                'rawConstrained' => $rawConstrained,
                'ormCounts' => $ormCounts,
                'ormHas' => $ormHas,
                'ormNone' => $ormNone,
                'ormNestedNone' => $ormNestedNone,
                'ormRelation' => $ormRelation,
                'ormConstrainedNone' => $ormConstrainedNone,
            ];
        });
        extract($snapshot);

        $intents = [];
        foreach ($intentRows as $row) {
            $intents[(string) $row['op_id']] = $row;
        }
        $effects = [];
        $outgoing = array_fill(1, $accounts, 0);
        $incoming = array_fill(1, $accounts, 0);
        $outAmount = array_fill(1, $accounts, 0);
        $inAmount = array_fill(1, $accounts, 0);
        $violations = [];
        foreach ($effectRows as $row) {
            $id = (string) $row['op_id'];
            if (isset($effects[$id])) {
                $violations[] = 'duplicate_effect:' . $id;
            }
            $effects[$id] = $row;
            $from = (int) $row['from_account'];
            $to = (int) $row['to_account'];
            $amount = (int) $row['amount'];
            $intent = $intents[$id] ?? null;
            if ($intent === null || $intent['kind'] !== 'transfer'
                || (int) $intent['from_account'] !== $from
                || (int) $intent['to_account'] !== $to
                || (int) $intent['amount'] !== $amount) {
                $violations[] = 'effect_intent_mismatch:' . $id;
            }
            if ($from < 1 || $from > $accounts || $to < 1 || $to > $accounts
                || $from === $to || $amount < 1 || $amount > 20) {
                $violations[] = 'invalid_effect:' . $id;
                continue;
            }
            $outgoing[$from]++;
            $incoming[$to]++;
            $outAmount[$from] += $amount;
            $inAmount[$to] += $amount;
        }
        $expectedTotal = $accounts * 1000;
        $actualTotal = 0;
        $regularRows = 0;
        $countRows = [];
        foreach ($ormCounts as $row) {
            $countRows[(int) $row['id']] = $row;
        }
        $hasIds = array_map(static fn (array $row): int => (int) $row['id'], $ormHas);
        $noneIds = array_map(static fn (array $row): int => (int) $row['id'], $ormNone);
        $nestedNoneIds = array_map(static fn (array $row): int => (int) $row['id'], $ormNestedNone);
        $relationIds = array_map(static fn (array $row): int => (int) $row['id'], $ormRelation);
        $constrainedNoneIds = array_map(static fn (array $row): int => (int) $row['id'], $ormConstrainedNone);
        $expectedHas = [];
        $expectedConstrained = [];
        $expectedNone = [];
        foreach ($accountRows as $row) {
            $id = (int) $row['id'];
            if ($id > $accounts) {
                if ((int) $row['revision'] < 1) {
                    $violations[] = 'invalid_contention_version:' . $id;
                }
                continue;
            }
            $regularRows++;
            $balance = (int) $row['balance'];
            $revision = (int) $row['revision'];
            $actualTotal += $balance;
            if ($balance - 1000 !== $inAmount[$id] - $outAmount[$id]) {
                $violations[] = 'account_balance:' . $id;
            }
            if ($revision - 1 !== $incoming[$id] + $outgoing[$id]) {
                $violations[] = 'account_version:' . $id;
            }
            $count = $countRows[$id] ?? null;
            if ($count === null || (int) $count['outgoing_count'] !== $outgoing[$id]
                || (int) $count['outgoing_source_count'] !== $outgoing[$id]
                || (int) $count['outgoing_destination_count'] !== $outgoing[$id]) {
                $violations[] = 'relation_count:' . $id;
            }
            if (in_array($id, $rawExists, true)) {
                $expectedHas[] = $id;
            } else {
                $expectedNone[] = $id;
            }
            if (in_array($id, $rawConstrained, true)) {
                $expectedConstrained[] = $id;
            }
        }
        sort($expectedHas);
        sort($expectedConstrained);
        sort($expectedNone);
        if ($regularRows !== $accounts || count($accountRows) !== $accounts + 2) {
            $violations[] = 'account_cardinality';
        }
        if ($actualTotal !== $expectedTotal) {
            $violations[] = 'conservation';
        }
        if ($hasIds !== $expectedHas || $relationIds !== $expectedConstrained) {
            $violations[] = 'relation_exists';
        }
        sort($constrainedNoneIds);
        $expectedConstrainedNone = array_values(array_diff(range(1, $accounts), $expectedConstrained));
        if ($constrainedNoneIds !== $expectedConstrainedNone) {
            $violations[] = 'relation_constrained_not_exists';
        }
        if ($noneIds !== $expectedNone || $nestedNoneIds !== $expectedNone) {
            $violations[] = 'relation_not_exists';
        }
        if ($final) {
            foreach ($intents as $id => $intent) {
                if (in_array($intent['kind'], ['rollback', 'exception'], true) && isset($effects[$id])) {
                    $violations[] = 'rollback_effect:' . $id;
                }
            }
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'accounts' => $regularRows,
            'balance_total' => $actualTotal,
            'expected_total' => $expectedTotal,
            'intents' => count($intents),
            'effects' => count($effects),
            'relation_checks' => 4,
            'final' => $final,
        ];
    }
}
