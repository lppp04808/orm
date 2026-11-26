<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\DriverAwareConnection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

final class DeltaCounter extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column(name: 'counter_count')]
    public int $count = 0;

    #[Column]
    public float $ratio = 0.0;

    #[Column]
    public string $label = 'initial';

    #[Column(name: 'changed_at')]
    public int $revision = 0;

    public static function table(): string
    {
        return 'delta_counter';
    }
}

final class DeltaSoftCounter extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public int $count = 0;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string
    {
        return 'delta_soft_counter';
    }
}

final class DeltaVersionedCounter extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public int $count = 0;

    #[Column]
    #[Version]
    public int $revision;

    public static function table(): string
    {
        return 'delta_versioned_counter';
    }
}

final class OrmDeltaUpdateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE delta_counter (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            counter_count INTEGER NOT NULL,
            ratio REAL NOT NULL,
            label TEXT NOT NULL,
            changed_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE delta_soft_counter (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            count INTEGER NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->pdo->exec('CREATE TABLE delta_versioned_counter (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            count INTEGER NOT NULL,
            revision INTEGER NOT NULL
        )');
        Session::use(new EntityGateway(new PdoConnection($this->pdo)));
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testIntegerIncrementAndDecrementWithExtraFieldAndColumnMapping(): void
    {
        $row = new DeltaCounter();
        $row->count = 10;
        $row->save();

        self::assertSame(1, DeltaCounter::query()->where('id', $row->id)->increment('count'));
        self::assertSame(11, $this->storedCounter($row->id)['counter_count']);

        self::assertSame(1, DeltaCounter::query()
            ->where('id', $row->id)
            ->decrement('counter_count', 3, ['revision' => 7, 'label' => 'updated']));
        self::assertSame([
            'counter_count' => 8,
            'changed_at' => 7,
            'label' => 'updated',
        ], $this->storedCounter($row->id));
    }

    #[DataProvider('duplicateAssignments')]
    public function testColumnAndPropertyNamesCannotAssignTheSameFieldTwice(bool $delta): void
    {
        $row = new DeltaCounter();
        $row->save();
        $query = DeltaCounter::query()->where('id', $row->id);
        $assignments = ['revision' => 1, 'changed_at' => 2];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate UPDATE column: changed_at');
        if ($delta) {
            $query->increment('count', 1, $assignments);
        } else {
            $query->update($assignments);
        }
    }

    /** @return array<string, array{bool}> */
    public static function duplicateAssignments(): array
    {
        return ['update' => [false], 'increment' => [true]];
    }

    public function testFloatIncrementAndDecrementUseDatabaseArithmetic(): void
    {
        $row = new DeltaCounter();
        $row->ratio = 1.25;
        $row->save();

        self::assertSame(1, DeltaCounter::query()->where('id', $row->id)->increment('ratio', 0.75));
        self::assertSame(2.0, (float) DeltaCounter::query()->where('id', $row->id)->value('ratio'));
        self::assertSame(1, DeltaCounter::query()->where('id', $row->id)->decrement('ratio'));
        self::assertSame(1.0, (float) DeltaCounter::query()->where('id', $row->id)->value('ratio'));
        self::assertSame(1, DeltaCounter::query()->where('id', $row->id)->increment('ratio', 2));
        self::assertSame(3.0, (float) DeltaCounter::query()->where('id', $row->id)->value('ratio'));
    }

    public function testSuccessfulUpdateInvalidatesFindCacheAndRereadsLatestValue(): void
    {
        $row = new DeltaCounter();
        $row->count = 10;
        $row->save();
        $id = $row->id;

        $cached = DeltaCounter::find(['id' => $id]);
        self::assertSame($row, $cached);
        $this->pdo->exec('UPDATE delta_counter SET counter_count = 20 WHERE id = ' . $id);

        self::assertSame(1, DeltaCounter::query()->where('id', $id)->increment('count', 3));
        $fresh = DeltaCounter::find(['id' => $id]);
        self::assertNotNull($fresh);
        self::assertNotSame($row, $fresh);
        self::assertSame(23, $fresh->count);
        self::assertSame(10, $row->count);
    }

    public function testSoftDeleteScopeIsPreservedAndWithTrashedCanUpdate(): void
    {
        $row = new DeltaSoftCounter();
        $row->count = 4;
        $row->save();
        $row->delete();

        self::assertSame(0, DeltaSoftCounter::query()->where('id', $row->id)->increment('count'));
        self::assertSame(4, (int) $this->pdo->query('SELECT count FROM delta_soft_counter')->fetchColumn());
        self::assertSame(1, DeltaSoftCounter::query()->withTrashed()->where('id', $row->id)->increment('count', 2));
        self::assertSame(6, (int) $this->pdo->query('SELECT count FROM delta_soft_counter')->fetchColumn());
    }

    #[DataProvider('dialects')]
    public function testDialectQuotesAndBindsAmountExtraAndWhere(string $driver, string $quote): void
    {
        $connection = new DeltaDialectConnection($driver);
        $gateway = new EntityGateway($connection);
        $builder = new \Pixiaoka\Orm\Query\Builder(DeltaCounter::class, $gateway);

        $query = $builder->where('id', 4)->buildDeltaUpdate(
            'count',
            '+',
            2,
            ['revision' => 9],
        );

        self::assertSame(
            "UPDATE {$quote}delta_counter{$quote} SET {$quote}counter_count{$quote} = "
            . "{$quote}counter_count{$quote} + :q0, {$quote}changed_at{$quote} = :q1 "
            . "WHERE {$quote}id{$quote} = :q2",
            $query['sql'],
        );
        self::assertSame(['q0' => 2, 'q1' => 9, 'q2' => 4], $query['params']);
    }

    /** @return array<string, array{string, string}> */
    public static function dialects(): array
    {
        return [
            'MySQL' => ['mysql', '`'],
            'PostgreSQL' => ['pgsql', '"'],
        ];
    }

    public function testBulkExecutionUsesDeltaSqlAndReturnsAffectedRows(): void
    {
        $connection = new DeltaDialectConnection('mysql');
        $gateway = new EntityGateway($connection);
        $builder = new \Pixiaoka\Orm\Query\Builder(DeltaCounter::class, $gateway);

        self::assertSame(1, $builder->where('id', 2)->decrement('count', 3, ['label' => 'next']));
        self::assertSame(
            'UPDATE `delta_counter` SET `counter_count` = `counter_count` - :q0, `label` = :q1 WHERE `id` = :q2',
            $connection->lastSql,
        );
        self::assertSame(['q0' => 3, 'q1' => 'next', 'q2' => 2], $connection->lastParams);
    }

    public function testFullTableDeltaUpdateIsRejectedEvenWithSoftDeleteScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UPDATE requires a WHERE condition');
        DeltaSoftCounter::query()->increment('count');
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetIsRejected(string $column, int|float $amount, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        DeltaCounter::query()->where('id', 1)->increment($column, $amount);
    }

    /** @return array<string, array{string, int|float, string}> */
    public static function invalidTargets(): array
    {
        return [
            'unknown column' => ['missing', 1, 'Unknown UPDATE column: missing'],
            'primary key' => ['id', 1, 'UPDATE cannot modify primary key column: id'],
            'non-numeric column' => ['label', 1, 'Delta UPDATE column must have an int or float type: label'],
            'float amount on int column' => ['count', 1.5, 'Delta UPDATE on an int column requires an int amount: counter_count'],
        ];
    }

    /** @return array<string, array{int|float}> */
    public static function invalidAmounts(): array
    {
        return [
            'zero int' => [0],
            'negative int' => [-2],
            'zero float' => [0.0],
            'negative float' => [-0.5],
            'not a number' => [NAN],
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountIsRejectedForIncrementAndDecrement(int|float $amount): void
    {
        foreach (['increment', 'decrement'] as $method) {
            try {
                DeltaCounter::query()->where('id', 1)->{$method}('ratio', $amount);
                self::fail('Invalid delta amount must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Delta UPDATE amount must be a finite number greater than zero', $e->getMessage());
            }
        }
    }

    #[DataProvider('conflictingExtraFields')]
    public function testExtraCannotContainResolvedTargetColumn(string $column, array $extra): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delta UPDATE extra fields cannot include the target column: counter_count');
        DeltaCounter::query()->where('id', 1)->increment($column, 1, $extra);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function conflictingExtraFields(): array
    {
        return [
            'property target with column extra' => ['count', ['counter_count' => 3]],
            'column target with property extra' => ['counter_count', ['count' => 3]],
        ];
    }

    public function testExtraUsesNormalUpdateFieldValidation(): void
    {
        foreach ([
            ['missing', 1, InvalidArgumentException::class, 'Unknown UPDATE column: missing'],
            ['id', 2, InvalidArgumentException::class, 'UPDATE cannot modify primary key column: id'],
            ['revision', 'not-an-int', \Pixiaoka\Orm\Exception\CastException::class, 'Cannot parse value as int'],
        ] as [$column, $value, $exception, $message]) {
            try {
                DeltaCounter::query()->where('id', 1)->increment('count', 1, [$column => $value]);
                self::fail('Invalid extra field must be rejected');
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e);
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testVersionedModelIsRejectedBeforeSql(): void
    {
        $row = new DeltaVersionedCounter();
        $row->save();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('uses optimistic locking; conditional UPDATE cannot preserve per-entity version semantics');
        DeltaVersionedCounter::query()->where('id', $row->id)->increment('count');
    }

    public function testWriteShapeAndControlledOperatorAreValidated(): void
    {
        try {
            DeltaCounter::query()->where('id', 1)->limit(1)->increment('count');
            self::fail('Limited updates must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('UPDATE/DELETE only supports base-table WHERE conditions', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delta UPDATE operator must be + or -');
        DeltaCounter::query()->where('id', 1)->buildDeltaUpdate('count', 'external', 1);
    }

    /** @return array{counter_count: int, changed_at: int, label: string} */
    private function storedCounter(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT counter_count, changed_at, label FROM delta_counter WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'counter_count' => (int) $row['counter_count'],
            'changed_at' => (int) $row['changed_at'],
            'label' => $row['label'],
        ];
    }
}

/** Only records dialect SQL; real SQLite execution is covered by the other tests. */
final class DeltaDialectConnection implements Connection, DriverAwareConnection
{
    public string $lastSql = '';

    /** @var array<string, int|float|string|bool|null> */
    public array $lastParams = [];

    public function __construct(private readonly string $dialect)
    {
    }

    public function execute(string $sql, array $params): int
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;

        return 1;
    }

    public function queryOne(string $sql, array $params): ?array
    {
        return null;
    }

    public function queryAll(string $sql, array $params): array
    {
        return [];
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function quoteIdentifier(string $identifier): string
    {
        $quote = $this->dialect === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn (string $part): string => $quote . $part . $quote,
            explode('.', $identifier),
        ));
    }

    public function supportsReturning(): bool
    {
        return $this->dialect !== 'mysql';
    }

    public function driver(): string
    {
        return $this->dialect;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }
}
