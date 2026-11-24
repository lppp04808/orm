<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Session;
use RuntimeException;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This benchmark can only run from the CLI.');
}
if (!extension_loaded('pdo')) {
    throw new RuntimeException('The PDO extension is required.');
}

enum BenchAuthorStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

final class BenchAuthor extends Entity
{
    public static string $authorTable = 'bench_author';

    public static string $articleTable = 'bench_article';

    #[Column, PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public BenchAuthorStatus $status;

    /** @var array<string, int|string> */
    #[Column]
    public array $meta;

    #[Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;

    public static function table(): string
    {
        return self::$authorTable;
    }

    #[HasManyAttribute(target: BenchArticle::class, localKey: ['id'], foreignKey: ['authorId'])]
    public function articles(): HasMany
    {
        return $this->makeRelation();
    }
}

final class BenchArticle extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;

    #[Column(name: 'author_id')]
    public int $authorId;

    #[Column]
    public string $title;

    #[Column]
    public int $views;

    #[Column]
    public float $score;

    #[Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;

    public static function table(): string
    {
        return BenchAuthor::$articleTable;
    }
}

final readonly class BenchmarkSample
{
    public function __construct(
        public float $milliseconds,
        public int $memoryDelta,
        public int $peakDelta,
        public int $sqlQueries,
    ) {
    }
}

final class BenchmarkCase
{
    public mixed $payload = null;

    /** @var list<BenchArticle> */
    public array $articles = [];

    /** @var list<array{authorId: int, title: string, views: int, score: float, createdAt: DateTimeImmutable}> */
    public array $rows = [];

    /** @var (\Closure(BenchmarkCase, EntityGateway, PDO): void)|null */
    private ?\Closure $prepare;

    /** @var \Closure(BenchmarkCase, EntityGateway, PDO): int */
    private \Closure $operation;

    /** @var (\Closure(BenchmarkCase, EntityGateway, PDO): void)|null */
    private ?\Closure $after;

    /**
     * @param callable(BenchmarkCase, EntityGateway, PDO): int $operation
     * @param callable(BenchmarkCase, EntityGateway, PDO): void|null $prepare
     * @param callable(BenchmarkCase, EntityGateway, PDO): void|null $after
     */
    public function __construct(
        public readonly string $name,
        public readonly int $work,
        public readonly string $unit,
        callable $operation,
        ?callable $prepare = null,
        ?callable $after = null,
        public readonly bool $write = false,
        public readonly ?string $baseline = null,
    ) {
        $this->operation = $operation(...);
        $this->prepare = $prepare === null ? null : $prepare(...);
        $this->after = $after === null ? null : $after(...);
    }

    public function prepare(EntityGateway $gateway, PDO $pdo): void
    {
        if ($this->prepare !== null) {
            ($this->prepare)($this, $gateway, $pdo);
        }
    }

    public function run(EntityGateway $gateway, PDO $pdo): int
    {
        return ($this->operation)($this, $gateway, $pdo);
    }

    public function after(EntityGateway $gateway, PDO $pdo): void
    {
        if ($this->after !== null) {
            ($this->after)($this, $gateway, $pdo);
        }
    }

    public function cleanup(): void
    {
        $this->payload = null;
        $this->articles = [];
        $this->rows = [];
    }
}

final readonly class BenchmarkResult
{
    /**
     * @param list<BenchmarkSample> $samples
     * @param list<int> $queries Per-round listener samples, or one separate validation count.
     */
    public function __construct(
        public BenchmarkCase $case,
        public array $samples,
        public array $queries,
    ) {
    }

    public function medianMilliseconds(): float
    {
        return self::median(array_map(
            static fn (BenchmarkSample $sample): float => $sample->milliseconds,
            $this->samples,
        ));
    }

    public function p95Milliseconds(): float
    {
        $values = array_map(
            static fn (BenchmarkSample $sample): float => $sample->milliseconds,
            $this->samples,
        );
        sort($values);
        $position = (count($values) - 1) * 0.95;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $fraction = $position - $lower;

        return $values[$lower] + ($values[$upper] - $values[$lower]) * $fraction;
    }

    public function medianMemory(): int
    {
        return (int) round(self::median(array_map(
            static fn (BenchmarkSample $sample): int => $sample->memoryDelta,
            $this->samples,
        )));
    }

    public function medianPeak(): int
    {
        return (int) round(self::median(array_map(
            static fn (BenchmarkSample $sample): int => $sample->peakDelta,
            $this->samples,
        )));
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            throw new RuntimeException('A benchmark must have at least one measured round.');
        }
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}

/** @return array{rows: int, rounds: int, warmup: int, driver: string} */
function benchmarkOptions(array $argv): array
{
    $options = ['rows' => 1000, 'rounds' => 7, 'warmup' => 2, 'driver' => 'sqlite'];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            echo "Usage: php bench/benchmark.php [--driver=sqlite|mysql|pgsql] [--rows=1000] [--rounds=7] [--warmup=2]\n";
            echo "MySQL/PostgreSQL: set BENCH_DSN, BENCH_USER and BENCH_PASSWORD.\n";
            echo "rows must be at least 1000; fixed batches contain 500 inserts and 200 updates.\n";
            exit(0);
        }
        if (preg_match('/^--(rows|rounds|warmup)=([0-9]+)$/', $argument, $matches) === 1) {
            $options[$matches[1]] = (int) $matches[2];
            continue;
        }
        if (preg_match('/^--driver=(sqlite|mysql|pgsql)$/', $argument, $matches) === 1) {
            $options['driver'] = $matches[1];
            continue;
        }
        throw new InvalidArgumentException('Invalid option: ' . $argument . '; use --driver=sqlite|mysql|pgsql --rows=N --rounds=N --warmup=N');
    }
    if ($options['rows'] < 1000) {
        throw new InvalidArgumentException('rows must be at least 1000 for the relation-size comparison.');
    }
    if ($options['rounds'] < 1) {
        throw new InvalidArgumentException('rounds must be at least 1.');
    }
    if ($options['warmup'] < 0) {
        throw new InvalidArgumentException('warmup must not be negative.');
    }

    return $options;
}

/** @var list<array{driver: string, authorTable: string, articleTable: string}> */
$ownedBenchmarkTables = [];

function benchmarkEnvironment(string $name): ?string
{
    $value = getenv($name);

    return $value === false || $value === '' ? null : $value;
}

/** @return array{pdo: PDO, driver: string, server: string} */
function benchmarkDatabase(int $rows, string $driver): array
{
    $dsn = match ($driver) {
        'sqlite' => 'sqlite::memory:',
        'mysql', 'pgsql' => benchmarkEnvironment('BENCH_DSN')
            ?? throw new InvalidArgumentException('BENCH_DSN is required for MySQL/PostgreSQL.'),
    };
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The pdo_' . $driver . ' extension is required.');
    }
    $user = $driver === 'sqlite' ? null : benchmarkEnvironment('BENCH_USER');
    $password = $driver === 'sqlite' ? null : benchmarkEnvironment('BENCH_PASSWORD');
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $actualDriver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($actualDriver !== $driver) {
        throw new RuntimeException('BENCH_DSN driver does not match --driver=' . $driver . '.');
    }
    $server = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $suffix = $driver === 'sqlite' ? '' : '_' . bin2hex(random_bytes(6));
    BenchAuthor::$authorTable = 'bench_author' . $suffix;
    BenchAuthor::$articleTable = 'bench_article' . $suffix;
    $authorTable = BenchAuthor::table();
    $articleTable = BenchArticle::table();
    if ($driver !== 'sqlite') {
        global $ownedBenchmarkTables;
        $ownedBenchmarkTables[] = ['driver' => $driver, 'authorTable' => $authorTable, 'articleTable' => $articleTable];
        // Make the tables eligible for shutdown cleanup before any DDL executes.
    }

    $id = match ($driver) {
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'pgsql' => 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
    };
    $text = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    $real = $driver === 'pgsql' ? 'DOUBLE PRECISION' : ($driver === 'mysql' ? 'DOUBLE' : 'REAL');
    $time = $driver === 'sqlite' ? 'TEXT' : ($driver === 'mysql' ? 'DATETIME' : 'TIMESTAMP');
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    $pdo->exec("CREATE TABLE {$authorTable} (
        id {$id},
        name {$text} NOT NULL,
        status {$text} NOT NULL,
        meta {$text} NOT NULL,
        created_at {$time} NOT NULL
    )" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    $pdo->exec("CREATE TABLE {$articleTable} (
        id {$id},
        author_id INT NOT NULL,
        title {$text} NOT NULL,
        views INT NOT NULL,
        score {$real} NOT NULL,
        created_at {$time} NOT NULL,
        FOREIGN KEY (author_id) REFERENCES {$authorTable}(id)
    )" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    $index = 'bench_article_author' . $suffix;
    $pdo->exec("CREATE INDEX {$index} ON {$articleTable}(author_id)");

    $author = $pdo->prepare(
        "INSERT INTO {$authorTable} (name, status, meta, created_at) VALUES (?, ?, ?, ?)",
    );
    $article = $pdo->prepare(
        "INSERT INTO {$articleTable} (author_id, title, views, score, created_at) VALUES (?, ?, ?, ?, ?)",
    );
    $timestamp = '2026-01-01 00:00:00';
    $pdo->beginTransaction();
    try {
        for ($id = 1; $id <= $rows; $id++) {
            $status = $id % 10 === 0 ? BenchAuthorStatus::Disabled : BenchAuthorStatus::Active;
            $meta = json_encode(['group' => $id % 20, 'label' => 'author-' . $id], JSON_THROW_ON_ERROR);
            $author->execute(['Author ' . $id, $status->value, $meta, $timestamp]);
            $article->execute([$id, 'Article ' . $id, $id * 3, $id / 10, $timestamp]);
        }
        $pdo->commit();
    } catch (\Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return ['pdo' => $pdo, 'driver' => $driver, 'server' => $server];
}

/** @return list<array{authorId: int, title: string, views: int, score: float, createdAt: DateTimeImmutable}> */
function benchmarkArticleRows(int $count): array
{
    $rows = [];
    $timestamp = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    for ($index = 0; $index < $count; $index++) {
        $rows[] = [
            'authorId' => ($index % 500) + 1,
            'title' => 'Inserted article ' . $index,
            'views' => $index,
            'score' => $index / 10,
            'createdAt' => $timestamp,
        ];
    }

    return $rows;
}

/** @return list<BenchArticle> */
function benchmarkArticleEntities(int $count): array
{
    return array_map(static function (array $row): BenchArticle {
        $article = new BenchArticle();
        foreach ($row as $property => $value) {
            $article->{$property} = $value;
        }

        return $article;
    }, benchmarkArticleRows($count));
}

/** @return list<BenchArticle> */
function benchmarkLoadArticles(int $count): array
{
    $articles = [];
    for ($id = 1; $id <= $count; $id++) {
        $article = BenchArticle::find(['id' => $id]);
        if (!$article instanceof BenchArticle) {
            throw new RuntimeException('The benchmark fixture is missing article ' . $id . '.');
        }
        $articles[] = $article;
    }

    return $articles;
}

/** @return array<string, BenchmarkCase> */
function benchmarkCases(int $rows, string $driver): array
{
    $cases = [];

    $case = new BenchmarkCase(
        'PDO fetchAll (baseline)', $rows, 'rows/s',
        static function (BenchmarkCase $case, EntityGateway $gateway, PDO $pdo) use ($rows): int {
            $case->payload = $pdo->query('SELECT * FROM ' . BenchAuthor::table() . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $count = count($case->payload);

            return $count === $rows ? $rows : throw new RuntimeException('PDO fetchAll returned an unexpected row count.');
        },
    );
    $cases['pdo_fetch'] = $case;

    $case = new BenchmarkCase(
        'Builder::rows()', $rows, 'rows/s',
        static function (BenchmarkCase $case) use ($rows): int {
            $case->payload = BenchAuthor::query()->rows();
            $count = count($case->payload);

            return $count === $rows ? $rows : throw new RuntimeException('Builder::rows() returned an unexpected row count.');
        },
        baseline: 'pdo_fetch',
    );
    $cases['rows'] = $case;

    $case = new BenchmarkCase(
        'Builder::get()', $rows, 'rows/s',
        static function (BenchmarkCase $case) use ($rows): int {
            $case->payload = BenchAuthor::query()->get();
            $count = count($case->payload);

            return $count === $rows ? $rows : throw new RuntimeException('Builder::get() returned an unexpected row count.');
        },
        baseline: 'pdo_fetch',
    );
    $cases['get'] = $case;

    $case = new BenchmarkCase(
        'PDO fetch by PK (baseline, 500)', 500, 'rows/s',
        static function (BenchmarkCase $case, EntityGateway $gateway, PDO $pdo): int {
            $statement = $pdo->prepare('SELECT * FROM ' . BenchAuthor::table() . ' WHERE id = ?');
            $found = [];
            for ($id = 1; $id <= 500; $id++) {
                $statement->execute([$id]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    throw new RuntimeException('PDO primary-key fetch missed a fixture row.');
                }
                $found[] = $row;
            }
            $case->payload = $found;

            return count($found);
        },
    );
    $cases['pdo_find'] = $case;

    $case = new BenchmarkCase(
        'Entity::find() (cache miss, 500)', 500, 'ops/s',
        static function (BenchmarkCase $case): int {
            $found = [];
            for ($id = 1; $id <= 500; $id++) {
                BenchAuthor::flushCache();
                $author = BenchAuthor::find(['id' => $id]);
                if (!$author instanceof BenchAuthor) {
                    throw new RuntimeException('Cache-miss find() missed a fixture row.');
                }
                $found[] = $author;
            }
            $case->payload = $found;

            return count($found);
        },
        baseline: 'pdo_find',
    );
    $cases['find_miss'] = $case;

    $case = new BenchmarkCase(
        'Entity::find() (RowCache hit, 500)', 500, 'ops/s',
        static function (BenchmarkCase $case): int {
            $found = [];
            for ($id = 1; $id <= 500; $id++) {
                $author = BenchAuthor::find(['id' => $id]);
                if (!$author instanceof BenchAuthor) {
                    throw new RuntimeException('RowCache-hit find() missed a fixture row.');
                }
                $found[] = $author;
            }
            $case->payload = $found;

            return count($found);
        },
        prepare: static function (): void {
            for ($id = 1; $id <= 500; $id++) {
                if (!BenchAuthor::find(['id' => $id]) instanceof BenchAuthor) {
                    throw new RuntimeException('RowCache warmup missed a fixture row.');
                }
            }
        },
        baseline: 'pdo_find',
    );
    $cases['find_hit'] = $case;

    foreach ([500, 1000] as $relationRows) {
        if ($driver === 'sqlite' && $relationRows === 1000) {
            continue;
        }
        $case = new BenchmarkCase(
            'PDO two-query relation (baseline, ' . $relationRows . ')', $relationRows, 'authors/s',
            static function (BenchmarkCase $case, EntityGateway $gateway, PDO $pdo) use ($relationRows): int {
                $authors = $pdo->query('SELECT * FROM ' . BenchAuthor::table() . ' ORDER BY id LIMIT ' . $relationRows)->fetchAll(PDO::FETCH_ASSOC);
                $articles = $pdo->query('SELECT * FROM ' . BenchArticle::table() . ' ORDER BY id LIMIT ' . $relationRows)->fetchAll(PDO::FETCH_ASSOC);
                $case->payload = [$authors, $articles];

                return count($authors) === $relationRows ? $relationRows : throw new RuntimeException('PDO relation returned an unexpected author count.');
            },
        );
        $cases['pdo_relation_' . $relationRows] = $case;

        $case = new BenchmarkCase(
            "Builder::with('articles') ({$relationRows})", $relationRows, 'authors/s',
            static function (BenchmarkCase $case) use ($relationRows): int {
                $case->payload = BenchAuthor::query()->with('articles')->limit($relationRows)->get();
                $count = count($case->payload);
                foreach ($case->payload as $author) {
                    if (count($author->loadedRelation('articles')) !== 1) {
                        throw new RuntimeException('Eager loading returned an unexpected article count.');
                    }
                }

                return $count === $relationRows ? $relationRows : throw new RuntimeException('Eager loading returned an unexpected author count.');
            },
            baseline: 'pdo_relation_' . $relationRows,
        );
        $cases['relation_' . $relationRows] = $case;
    }

    $case = new BenchmarkCase(
        'insertAll() (500 rows)', 500, 'rows/s',
        static function (BenchmarkCase $case): int {
            BenchArticle::insertAll($case->rows);
            $case->payload = $case->rows;

            return count($case->rows);
        },
        prepare: static function (BenchmarkCase $case): void {
            $case->rows = benchmarkArticleRows(500);
        },
        after: static function (BenchmarkCase $case, EntityGateway $gateway, PDO $pdo) use ($rows): void {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . BenchArticle::table())->fetchColumn();
            if ($count !== $rows + 500) {
                throw new RuntimeException('insertAll() did not insert the expected rows.');
            }
        },
        write: true,
    );
    $cases['insert_all'] = $case;

    $case = new BenchmarkCase(
        'Loop save() (500 inserts)', 500, 'rows/s',
        static function (BenchmarkCase $case): int {
            foreach ($case->articles as $article) {
                $article->save();
            }
            $case->payload = $case->articles;

            return count($case->articles);
        },
        prepare: static function (BenchmarkCase $case): void {
            $case->articles = benchmarkArticleEntities(500);
        },
        after: static function (BenchmarkCase $case, EntityGateway $gateway, PDO $pdo) use ($rows): void {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . BenchArticle::table())->fetchColumn();
            if ($count !== $rows + 500) {
                throw new RuntimeException('Loop save() did not insert the expected rows.');
            }
        },
        write: true,
    );
    $cases['insert_loop'] = $case;

    $case = new BenchmarkCase(
        'increment() (200 updates)', 200, 'ops/s',
        static function (BenchmarkCase $case) use ($rows): int {
            for ($id = 1; $id <= 200; $id++) {
                $affected = BenchArticle::query()
                    ->where('id', (($id - 1) % $rows) + 1)
                    ->increment('views');
                if ($affected !== 1) {
                    throw new RuntimeException('increment() did not update exactly one row.');
                }
            }

            return 200;
        },
        write: true,
    );
    $cases['increment'] = $case;

    $case = new BenchmarkCase(
        'Dirty save() (200 updates)', 200, 'ops/s',
        static function (BenchmarkCase $case): int {
            foreach ($case->articles as $article) {
                $article->views++;
                $article->save();
            }
            $case->payload = $case->articles;

            return count($case->articles);
        },
        prepare: static function (BenchmarkCase $case): void {
            $case->articles = benchmarkLoadArticles(200);
        },
        write: true,
    );
    $cases['dirty_save'] = $case;

    $case = new BenchmarkCase(
        'Equal DateTimeImmutable save() (200 no-ops)', 200, 'ops/s',
        static function (BenchmarkCase $case): int {
            foreach ($case->articles as $article) {
                $article->createdAt = new DateTimeImmutable(
                    $article->createdAt->format('Y-m-d H:i:s'),
                    new DateTimeZone('UTC'),
                );
                $article->save();
            }
            $case->payload = $case->articles;

            return count($case->articles);
        },
        prepare: static function (BenchmarkCase $case): void {
            $case->articles = benchmarkLoadArticles(200);
        },
        write: true,
    );
    $cases['noop_save'] = $case;

    return $cases;
}

/** @return array{sample: BenchmarkSample, work: int, affectedRows: int|null} */
function benchmarkIteration(PDO $pdo, BenchmarkCase $case, bool $listener): array
{
    $queries = 0;
    $affectedRows = null;
    $timed = false;
    $gateway = new EntityGateway(new PdoConnection($pdo));
    $unsubscribe = null;
    if ($listener) {
        $unsubscribe = $gateway->subscribe(static function (Event $event) use (&$queries, &$affectedRows, &$timed): void {
            if ($timed && $event instanceof SqlEvent) {
                $queries++;
                if ($event->affectedRows !== null) {
                    $affectedRows = ($affectedRows ?? 0) + $event->affectedRows;
                }
            }
        });
    }
    Session::use($gateway);
    if ($case->write && !$pdo->beginTransaction()) {
        throw new RuntimeException('Unable to isolate a write benchmark in a transaction.');
    }

    $sample = new BenchmarkSample(0.0, 0, 0, 0);
    try {
        $case->prepare($gateway, $pdo);
        gc_collect_cycles();
        $memory = memory_get_usage();
        memory_reset_peak_usage();
        $timed = true;
        $started = hrtime(true);
        $work = $case->run($gateway, $pdo);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $timed = false;
        $memoryDelta = memory_get_usage() - $memory;
        $peakDelta = memory_get_peak_usage(true) - $memory;
        $case->after($gateway, $pdo);
        if ($work !== $case->work) {
            throw new RuntimeException($case->name . ' returned an unexpected work count.');
        }
        $sample = new BenchmarkSample($elapsed, $memoryDelta, $peakDelta, $queries);
    } finally {
        $unsubscribe?->__invoke();
        if ($case->write && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $case->cleanup();
        Session::discard();
        unset($gateway, $unsubscribe);
        gc_collect_cycles();
    }

    return ['sample' => $sample, 'work' => $case->work, 'affectedRows' => $affectedRows];
}

/** @return array{result: BenchmarkResult, affectedRows: int|null} */
function benchmark(PDO $pdo, BenchmarkCase $case, int $rounds, int $warmup, bool $listener = false): array
{
    $samples = [];
    $queries = [];
    for ($round = -$warmup; $round < $rounds; $round++) {
        $iteration = benchmarkIteration($pdo, $case, $listener);
        if ($round >= 0) {
            $samples[] = $iteration['sample'];
            $queries[] = $iteration['sample']->sqlQueries;
        }
    }
    $affectedRows = null;
    if (!$listener) {
        $validation = benchmarkIteration($pdo, $case, true);
        $queries = [$validation['sample']->sqlQueries];
        $affectedRows = $validation['affectedRows'];
    }

    return ['result' => new BenchmarkResult($case, $samples, $queries), 'affectedRows' => $affectedRows];
}

function benchmarkBytes(int $bytes): string
{
    $sign = $bytes < 0 ? '-' : '';
    $absolute = abs($bytes);
    if ($absolute >= 1024 * 1024) {
        return sprintf('%s%.2f MiB', $sign, $absolute / 1024 / 1024);
    }

    return sprintf('%s%.1f KiB', $sign, $absolute / 1024);
}

/** @param array<string, BenchmarkResult> $results */
function benchmarkPrintReadTable(array $results, array $order): void
{
    echo "| Scenario | Median (ms) | P95 (ms) | Throughput | vs PDO | Δ memory | Peak memory |\n";
    echo "|---|---:|---:|---:|---:|---:|---:|\n";
    foreach ($order as $key) {
        $result = $results[$key];
        $case = $result->case;
        $median = $result->medianMilliseconds();
        $throughput = $case->work / max($median / 1000, 0.000001);
        $ratio = '—';
        if ($case->baseline !== null) {
            $ratio = sprintf('%.2f×', $results[$case->baseline]->medianMilliseconds() / max($median, 0.000001));
        }
        printf(
            "| %s | %.3f | %.3f | %s %s | %s | %s | %s |\n",
            $case->name,
            $median,
            $result->p95Milliseconds(),
            number_format($throughput, 0),
            $case->unit,
            $ratio,
            benchmarkBytes($result->medianMemory()),
            benchmarkBytes($result->medianPeak()),
        );
    }
}

/** @param array<string, array{result: BenchmarkResult, affectedRows: int|null}> $results */
function benchmarkPrintWriteTable(array $results): void
{
    echo "| Scenario | Median (ms) | P95 (ms) | Throughput | SQL/op | Rows/op | Δ memory | Peak memory |\n";
    echo "|---|---:|---:|---:|---:|---:|---:|---:|\n";
    foreach ($results as $benchmark) {
        $result = $benchmark['result'];
        $case = $result->case;
        $median = $result->medianMilliseconds();
        $rowsPerOperation = $benchmark['affectedRows'] === null
            ? 'n/a'
            : sprintf('%.1f', $benchmark['affectedRows'] / $case->work);
        printf(
            "| %s | %.3f | %.3f | %s %s | %.3f | %s | %s | %s |\n",
            $case->name,
            $median,
            $result->p95Milliseconds(),
            number_format($case->work / max($median / 1000, 0.000001), 0),
            $case->unit,
            $result->queries[0] / $case->work,
            $rowsPerOperation,
            benchmarkBytes($result->medianMemory()),
            benchmarkBytes($result->medianPeak()),
        );
    }
}

/** @param array{0: BenchmarkResult, 1: BenchmarkResult} $pair */
function benchmarkPrintObservability(string $name, array $pair): void
{
    [$plain, $observed] = $pair;
    $overhead = $observed->medianMilliseconds() - $plain->medianMilliseconds();
    $microseconds = $overhead * 1000 / $plain->case->work;
    printf(
        "| %s | %.3f / %.3f | %.3f / %.3f | %+.3f | %+.2f |\n",
        $name,
        $plain->medianMilliseconds(),
        $plain->p95Milliseconds(),
        $observed->medianMilliseconds(),
        $observed->p95Milliseconds(),
        $overhead,
        $microseconds,
    );
}

function benchmarkDropOwnedTables(PDO $pdo, string $driver): void
{
    global $ownedBenchmarkTables;
    $remaining = [];
    foreach ($ownedBenchmarkTables as $tables) {
        if ($tables['driver'] !== $driver) {
            $remaining[] = $tables;
            continue;
        }
        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $tables['articleTable']);
            $pdo->exec('DROP TABLE IF EXISTS ' . $tables['authorTable']);
        } catch (\Throwable $exception) {
            $remaining[] = $tables;
            fwrite(STDERR, 'Unable to remove benchmark tables: ' . $exception->getMessage() . "\n");
        }
    }
    $ownedBenchmarkTables = $remaining;
}

function benchmarkCleanup(): void
{
    global $ownedBenchmarkTables;
    foreach ($ownedBenchmarkTables as $tables) {
        try {
            $dsn = benchmarkEnvironment('BENCH_DSN');
            if ($dsn === null) {
                fwrite(STDERR, "Unable to remove benchmark tables: BENCH_DSN is unavailable.\n");
                continue;
            }
            $pdo = new PDO($dsn, benchmarkEnvironment('BENCH_USER'), benchmarkEnvironment('BENCH_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            benchmarkDropOwnedTables($pdo, $tables['driver']);
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Unable to remove benchmark tables: ' . $exception->getMessage() . "\n");
        }
    }
}

register_shutdown_function('Pixiaoka\\Orm\\Benchmark\\benchmarkCleanup');

$options = benchmarkOptions($argv);
$driver = $options['driver'];
$rows = $options['rows'];
$rounds = $options['rounds'];
$warmup = $options['warmup'];

echo "# Pixiaoka ORM microbenchmark\n\n";
echo 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ', ' . PHP_OS_FAMILY . ' ' . php_uname('m') . ")\n";
$database = benchmarkDatabase($rows, $driver);
$pdo = $database['pdo'];
echo 'Database: ' . $driver . ' ' . $database['server'] . "\n";
echo 'OPcache CLI: ' . ((bool) ini_get('opcache.enable_cli') ? 'enabled' : 'disabled') . "\n";
echo "Parameters: rows={$rows}, rounds={$rounds}, warmup={$warmup}, driver={$driver}\n";
echo "Dataset: one article per author; external tables are uniquely named and removed on exit\n\n";
if (extension_loaded('xdebug')) {
    echo "**WARNING: Xdebug is loaded; timings and memory overhead are not representative.**\n\n";
}
echo "Fixture creation, write-transaction setup/rollback, and pre-hydration are outside timed regions.\n";
echo "Each round uses a new EntityGateway. Memory is the per-round delta; peak is relative to the round baseline.\n";
echo "Memory and peak columns show the median across rounds; SQL/op uses a separate timed validation pass.\n";
echo "Rows/op is n/a when a driver does not report affected rows (for example, INSERT RETURNING).\n";
echo "Listener comparisons repeat the same cases with one counter and subtract the unobserved median.\n";
echo "The PDO relation reference performs two queries; cache-hit PDO references still execute SQL.\n";
if ($driver === 'sqlite') {
    echo "SQLite eager loading is limited to 500 parents; its 1000-parent case is skipped explicitly.\n";
}
echo "\n";

try {
$cases = benchmarkCases($rows, $driver);
$results = [];
$readCases = ['pdo_fetch', 'rows', 'get', 'pdo_find', 'find_miss', 'find_hit', 'pdo_relation_500', 'relation_500'];
if ($driver !== 'sqlite') {
    array_push($readCases, 'pdo_relation_1000', 'relation_1000');
}
foreach ($readCases as $key) {
    $results[$key] = benchmark($pdo, $cases[$key], $rounds, $warmup)['result'];
}
if ($results['find_hit']->queries !== [0]) {
    throw new RuntimeException('The RowCache-hit benchmark unexpectedly executed SQL.');
}

echo "## Reads and hydration\n\n";
benchmarkPrintReadTable($results, $readCases);
if ($driver !== 'sqlite') {
    echo "\n## Eager-loading scale comparison (500 → 1000)\n\n";
    echo "| Path | Median ratio | Throughput change | Memory ratio | SQL/round |\n";
    echo "|---|---:|---:|---:|---:|\n";
    foreach (['pdo_relation', 'relation'] as $prefix) {
        $small = $results[$prefix . '_500'];
        $large = $results[$prefix . '_1000'];
        $smallMedian = $small->medianMilliseconds();
        $largeMedian = $large->medianMilliseconds();
        $smallThroughput = $small->case->work / $smallMedian;
        $largeThroughput = $large->case->work / $largeMedian;
        $sql = $prefix === 'pdo_relation'
            ? '2'
            : implode('/', array_unique(array_merge($small->queries, $large->queries)));
        printf(
            "| %s | %.2f× | %+.1f%% | %.2f× | %s |\n",
            $prefix === 'pdo_relation' ? 'PDO two-query relation' : "ORM with('articles')",
            $largeMedian / $smallMedian,
            ($largeThroughput / $smallThroughput - 1) * 100,
            $large->medianMemory() / max(1, $small->medianMemory()),
            $sql,
        );
    }
}

$writeResults = [];
foreach (['insert_all', 'insert_loop', 'increment', 'dirty_save', 'noop_save'] as $key) {
    $writeResults[$key] = benchmark($pdo, $cases[$key], $rounds, $warmup);
}
if ($writeResults['noop_save']['result']->queries !== [0]) {
    throw new RuntimeException('Equal DateTimeImmutable saves unexpectedly executed SQL.');
}
echo "\n## Writes\n\n";
benchmarkPrintWriteTable($writeResults);

$missWithListener = benchmark($pdo, $cases['find_miss'], $rounds, $warmup, true)['result'];
$incrementWithListener = benchmark($pdo, $cases['increment'], $rounds, $warmup, true)['result'];
if (array_unique($missWithListener->queries) !== [500] || array_unique($incrementWithListener->queries) !== [200]) {
    throw new RuntimeException('The observability listener did not observe the expected query counts.');
}

echo "\n## Observability listener overhead\n\n";
echo "One lightweight SqlEvent counter listener. The unobserved and observed cases use identical operations.\n\n";
echo "| Scenario | Without listener median / P95 (ms) | With listener median / P95 (ms) | Δ median (ms) | Overhead (µs/query) |\n";
echo "|---|---:|---:|---:|---:|\n";
benchmarkPrintObservability('find() cache miss (500)', [$results['find_miss'], $missWithListener]);
benchmarkPrintObservability('increment() (200)', [$writeResults['increment']['result'], $incrementWithListener]);

echo "\nBenchmark completed successfully.\n";
} finally {
    Session::discard();
    if ($driver !== 'sqlite') {
        benchmarkDropOwnedTables($pdo, $driver);
    }
}
