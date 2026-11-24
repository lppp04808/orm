<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Benchmark;

use PDO;
use RuntimeException;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This benchmark can only run from the CLI.');
}
if (!extension_loaded('pdo')) {
    throw new RuntimeException('The PDO extension is required.');
}

final class StatementCacheItem extends Entity
{
    public static string $table = 'bench_statement_cache';

    #[Column, PrimaryKey]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public float $amount;

    public static function table(): string
    {
        return self::$table;
    }
}

$driver = $argv[1] ?? '';
if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
    fwrite(STDERR, "Usage: php bench/statement_cache.php sqlite|mysql|pgsql\n");
    exit(2);
}
if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('The pdo_' . $driver . ' extension is required.');
}
$dsn = match ($driver) {
    'sqlite' => 'sqlite::memory:',
    default => getenv('BENCH_DSN') ?: throw new \InvalidArgumentException('BENCH_DSN is required.'),
};
$user = $driver === 'sqlite' ? null : (getenv('BENCH_USER') ?: null);
$password = $driver === 'sqlite' ? null : (getenv('BENCH_PASSWORD') ?: null);
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
    throw new RuntimeException('BENCH_DSN driver does not match the requested backend.');
}
$table = 'bench_statement_cache_' . bin2hex(random_bytes(6));
$ownedTable = $driver === 'sqlite' ? null : $table;
register_shutdown_function(static function () use (&$pdo, $driver, $table, &$ownedTable): void {
    if ($ownedTable === null || $driver === 'sqlite') {
        return;
    }
    try {
        $cleanup = new PDO(
            getenv('BENCH_DSN') ?: '',
            getenv('BENCH_USER') ?: null,
            getenv('BENCH_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $cleanup->exec("DROP TABLE IF EXISTS {$ownedTable}");
        $ownedTable = null;
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Unable to remove benchmark table: ' . $exception->getMessage() . "\n");
    }
});
$id = match ($driver) {
    'sqlite' => 'INTEGER PRIMARY KEY',
    'mysql' => 'INT PRIMARY KEY',
    'pgsql' => 'INTEGER PRIMARY KEY',
};
$text = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(16)';
$value = match ($driver) {
    'sqlite' => 'REAL',
    'mysql' => 'DOUBLE',
    'pgsql' => 'DOUBLE PRECISION',
};
$pdo->exec("CREATE TABLE {$table} (id {$id}, name {$text} NOT NULL, amount {$value} NOT NULL)");
$insert = $pdo->prepare("INSERT INTO {$table} (id, name, amount) VALUES (?, ?, 0)");
$pdo->beginTransaction();
for ($index = 1; $index <= 500; $index++) {
    $insert->execute([$index, 'Item ' . $index]);
}
$pdo->commit();
StatementCacheItem::$table = $table;

$cases = [
    '500 PK selects' => [
        'sql' => "SELECT id, name, amount FROM {$table} WHERE id = :id",
        'prepare' => static fn (): array => ['rows' => []],
        'run' => static function (array &$state, ?PdoConnection $connection, PDO $pdo, string $sql): void {
            $rows = [];
            for ($id = 1; $id <= 500; $id++) {
                $rows[] = $connection === null
                    ? pdoFetchOne($pdo, $sql, $id)
                    : $connection->queryOne($sql, ['id' => $id]);
            }
            $state['rows'] = $rows;
            if (count($rows) !== 500) {
                throw new RuntimeException('Unexpected primary-key result count.');
            }
            foreach ($rows as $index => $row) {
                $id = $index + 1;
                if ($row === null || (int) $row['id'] !== $id
                    || $row['name'] !== 'Item ' . $id
                    || (float) $row['amount'] !== 0.0) {
                    throw new RuntimeException('Unexpected primary-key result.');
                }
            }
        },
    ],
    '200 updates' => [
        'sql' => "UPDATE {$table} SET amount = amount + :amount WHERE id = :id",
        'prepare' => static fn (): array => ['rows' => []],
        'run' => static function (array &$state, ?PdoConnection $connection, PDO $pdo, string $sql) use ($table): void {
            $pdo->beginTransaction();
            try {
                $affected = 0;
                for ($id = 1; $id <= 200; $id++) {
                    if ($connection === null) {
                        $statement = $pdo->prepare($sql);
                        try {
                            $statement->execute(['amount' => 1, 'id' => $id]);
                            $affected += $statement->rowCount();
                        } finally {
                            $statement->closeCursor();
                        }
                    } else {
                        $affected += $connection->execute($sql, ['amount' => 1, 'id' => $id]);
                    }
                }
                if ($affected !== 200) {
                    throw new RuntimeException('Unexpected affected-row count.');
                }
                $amount = (float) $pdo->query("SELECT amount FROM {$table} WHERE id = 1")->fetchColumn();
                if ($amount !== 1.0) {
                    throw new RuntimeException('Unexpected updated value.');
                }
            } finally {
                $pdo->rollBack();
            }
        },
    ],
    '500 array reads' => [
        'sql' => "SELECT id, name, amount FROM {$table} ORDER BY id",
        'prepare' => static fn (): array => ['rows' => []],
        'run' => static function (array &$state, ?PdoConnection $connection, PDO $pdo, string $sql): void {
            if ($connection !== null) {
                $state['rows'] = $connection->queryAll($sql, []);
            } else {
                $statement = $pdo->prepare($sql);
                try {
                    $statement->execute();
                    $state['rows'] = $statement->fetchAll(PDO::FETCH_ASSOC);
                } finally {
                    $statement->closeCursor();
                }
            }
            if (count($state['rows']) !== 500) {
                throw new RuntimeException('Unexpected row count.');
            }
        },
    ],
];

/** @return array<string, mixed>|null */
function pdoFetchOne(PDO $pdo, string $sql, int $id): ?array
{
    $statement = $pdo->prepare($sql);
    try {
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    } finally {
        $statement->closeCursor();
    }
}

/** @return list<float> */
function statementCacheTimes(PDO $pdo, ?int $capacity, array $case): array
{
    $times = [];
    for ($round = -1; $round < 5; $round++) {
        $connection = $capacity === null ? null : new PdoConnection($pdo, statementCacheSize: $capacity);
        $state = ($case['prepare'])();
        gc_collect_cycles();
        $start = hrtime(true);
        ($case['run'])($state, $connection, $pdo, $case['sql']);
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $connection?->clearStatementCache();
        unset($connection, $state);
        gc_collect_cycles();
        if ($round >= 0) {
            $times[] = $elapsed;
        }
    }
    sort($times);

    return $times;
}

/** @return array<string, float> */
function ormTimes(PDO $pdo): array
{
    $times = ['500 PK finds' => [], '200 increments' => [], '500 array rows' => [], '500 entities' => []];
    for ($round = -1; $round < 5; $round++) {
        $connection = new PdoConnection($pdo, statementCacheSize: 64);
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        try {
            $started = hrtime(true);
            $found = [];
            for ($id = 1; $id <= 500; $id++) {
                StatementCacheItem::flushCache();
                $item = StatementCacheItem::find(['id' => $id]);
                if (!$item instanceof StatementCacheItem || $item->name !== 'Item ' . $id || $item->amount !== 0.0) {
                    throw new RuntimeException('Unexpected entity result.');
                }
                $found[] = $item;
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            unset($found);
            if ($round >= 0) {
                $times['500 PK finds'][] = $elapsed;
            }

            $pdo->beginTransaction();
            try {
                $started = hrtime(true);
                for ($id = 1; $id <= 200; $id++) {
                    if (StatementCacheItem::query()->where('id', $id)->increment('amount') !== 1) {
                        throw new RuntimeException('Unexpected increment affected-row count.');
                    }
                }
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                if ($round >= 0) {
                    $times['200 increments'][] = $elapsed;
                }
            } finally {
                $pdo->rollBack();
            }

            $started = hrtime(true);
            $rows = StatementCacheItem::query()->orderBy('id')->rows();
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            if (count($rows) !== 500) {
                throw new RuntimeException('Unexpected array-row count.');
            }
            unset($rows);
            if ($round >= 0) {
                $times['500 array rows'][] = $elapsed;
            }

            $started = hrtime(true);
            $items = StatementCacheItem::query()->orderBy('id')->get();
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            if (count($items) !== 500) {
                throw new RuntimeException('Unexpected entity count.');
            }
            unset($items);
            if ($round >= 0) {
                $times['500 entities'][] = $elapsed;
            }
        } finally {
            Session::discard();
            $connection->clearStatementCache();
            unset($gateway, $connection);
            gc_collect_cycles();
        }
    }

    return array_map(static function (array $samples): float {
        sort($samples);

        return $samples[2];
    }, $times);
}

echo "# Statement-cache comparison: {$driver}\n\n";
echo 'PHP: ' . PHP_VERSION . '; database: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n\n";
echo "| Scenario | Native PDO | Cache 0 | Cache 64 |\n";
echo "|---|---:|---:|---:|\n";
try {
    foreach ($cases as $name => $case) {
        $pdoBaseline = statementCacheTimes($pdo, null, $case)[2];
        $disabled = statementCacheTimes($pdo, 0, $case)[2];
        $enabled = statementCacheTimes($pdo, 64, $case)[2];
        printf("| %s | %.3f ms | %.3f ms | %.3f ms |\n", $name, $pdoBaseline, $disabled, $enabled);
    }
    echo "\n## Full ORM paths (statement cache 64)\n\n";
    echo "| Scenario | Median |\n|---|---:|\n";
    foreach (ormTimes($pdo) as $name => $milliseconds) {
        printf("| %s | %.3f ms |\n", $name, $milliseconds);
    }
    echo "\nNative PDO prepares for every execution; Cache 0/64 use PdoConnection. ";
    echo "All paths use one warmup and five measured rounds; full ORM paths include mapping and, where applicable, hydration.\n";
} finally {
    Session::discard();
    $pdo->exec("DROP TABLE {$table}");
}
