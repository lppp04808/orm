<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Query\JoinClause;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComplexCountContractTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE count_contract (id INTEGER PRIMARY KEY, category TEXT NULL, amount INTEGER NOT NULL)');
        $this->pdo->exec("INSERT INTO count_contract VALUES (1, 'a', 10), (2, NULL, 20), (3, 'a', 30), (4, NULL, 40)");
        Session::use(new EntityGateway(new PdoConnection($this->pdo)));
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testSimpleCountAndNullableColumnCountHaveDistinctSemantics(): void
    {
        self::assertSame(4, CountContract::query()->count());
        self::assertSame(2, CountContract::query()->count('category'));
        self::assertSame(4, CountContract::query()->count('amount'));
    }

    public function testDistinctCountColumnUsesOutputAliasAndCountsNonNullValues(): void
    {
        $query = CountContract::query()->selectAs('category', 'category_value')->distinct();
        self::assertSame(1, $query->count('category_value'));
        self::assertSame(2, $query->count());
    }

    public function testGroupedCountAndNullableGroupedCountAreDifferent(): void
    {
        $query = CountContract::query()->select('category')->groupBy('category');
        self::assertSame(2, $query->count(), 'SQLite treats NULL as a distinct group; two distinct categories including NULL');
        try {
            $query->count('category');
            self::fail('COUNT(column) across groups has no unambiguous scalar contract');
        } catch (\LogicException $e) {
            self::assertStringContainsString('does not support', $e->getMessage());
        }
    }

    public function testJoinCountColumnIsRejectedInsteadOfIgnoringRequestedColumn(): void
    {
        $query = CountContract::query()->alias('c')->selectAs('c.category', 'category_value')
            ->leftJoin('count_contract', 'other', fn (JoinClause $join) => $join->on('other.id', '=', 'c.id'));
        self::assertSame(4, $query->count());
        try {
            $query->count('category_value');
            self::fail('JOIN COUNT(column) requires explicit aggregate semantics');
        } catch (\LogicException $e) {
            self::assertStringContainsString('does not support', $e->getMessage());
        }
    }

    public function testUnionAndDerivedCountColumnRejectUnsupportedOutputAliases(): void
    {
        $union = CountContract::query()->selectAs('category', 'category_value')->where('id', 1)
            ->unionAll(CountContract::query()->selectAs('category', 'category_value')->where('id', 2));
        $derived = CountContract::query()->select('category_value')->from($union, 'u');
        foreach ([$union, $derived] as $query) {
            try {
                $query->count('category_value');
                self::fail('Complex COUNT(column) cannot silently become COUNT(*)');
            } catch (\LogicException $e) {
                self::assertStringContainsString('does not support', $e->getMessage());
            }
        }
        self::assertSame(2, $derived->count());
    }

    public function testCountRejectsNullOutputAndUnknownAlias(): void
    {
        foreach ([
            CountContract::query()->selectRaw('NULL AS missing_value'),
            CountContract::query()->selectAs('category', 'category_value')->distinct(),
        ] as $index => $query) {
            try {
                $query->count($index === 0 ? 'missing_value' : 'unknown');
                self::fail('COUNT must reject unknown or null-only output');
            } catch (\LogicException $e) {
                self::assertStringContainsString('does not support', $e->getMessage());
            }
        }
    }

    #[DataProvider('drivers')]
    public function testRealDatabaseNullableDistinctCount(string $driver): void
    {
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped($prefix . '_DSN is not configured');
        }
        $pdo = new PDO($dsn, getenv($prefix . '_USER') ?: null, getenv($prefix . '_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection = new PdoConnection($pdo);
        Session::use(new EntityGateway($connection));
        $table = 'count_contract_' . bin2hex(random_bytes(5));
        $q = $connection->quoteIdentifier($table);
        try {
            $pdo->exec("CREATE TABLE $q (id INTEGER PRIMARY KEY, category VARCHAR(32) NULL, amount INTEGER NOT NULL)");
            $pdo->exec("INSERT INTO $q VALUES (1, 'a', 10), (2, NULL, 20), (3, 'a', 30), (4, NULL, 40)");
            // The model's table name is fixed; execute the same compiler shape explicitly for a temporary table.
            $quotedCategory = $connection->quoteIdentifier('category');
            $quotedValue = $connection->quoteIdentifier('category_value');
            $count = (int) $pdo->query("SELECT COUNT($quotedValue) FROM (SELECT DISTINCT $quotedCategory AS $quotedValue FROM $q) AS " . $connection->quoteIdentifier('orm_count'))->fetchColumn();
            self::assertSame(1, $count);
            self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT $quotedCategory AS $quotedValue FROM $q) AS " . $connection->quoteIdentifier('orm_count'))->fetchColumn());
        } finally {
            Session::discard();
            $pdo->exec("DROP TABLE IF EXISTS $q");
        }
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }
}

final class CountContract extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public ?string $category = null;

    #[Column]
    public int $amount;

    public static function table(): string
    {
        return 'count_contract';
    }
}
