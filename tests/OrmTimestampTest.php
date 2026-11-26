<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Session;

final class OrmTimestampTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE timestamp_record (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            body TEXT NOT NULL,
            created_on TEXT NOT NULL,
            modify_time INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE ' . TimestampOnlyRecord::table() . ' (
            id INTEGER PRIMARY KEY,
            created_on INTEGER NOT NULL,
            modify_time INTEGER NOT NULL
        )');
        Session::use(new EntityGateway(new PdoConnection($this->pdo)));
    }

    public function testAttributeNamesAndDateTimeTypesDriveEveryWritePath(): void
    {
        $meta = (new MetadataReader())->read(TimestampRecord::class);
        self::assertSame('created_on', $meta->timestampField('created_at')?->property);
        self::assertSame('modify_time', $meta->timestampField('updated_at')?->property);

        $before = time();
        $record = new TimestampRecord();
        $record->body = 'first';
        $record->save();
        self::assertGreaterThanOrEqual($before, $record->created_on->getTimestamp());
        self::assertSame('UTC', $record->created_on->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $record->modify_time);
        $created = $record->created_on;
        $updated = $record->modify_time;
        $stored = $this->pdo->query('SELECT created_on, modify_time FROM timestamp_record')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($created->format('Y-m-d H:i:s'), $stored['created_on']);
        self::assertSame($updated, (int) $stored['modify_time']);

        $record->body = 'second';
        $record->save();
        self::assertSame($created->format('Y-m-d H:i:s'), $record->created_on->format('Y-m-d H:i:s'));
        self::assertGreaterThanOrEqual($updated, $record->modify_time);
        self::assertCount(1, $this->pdo->query('SELECT * FROM timestamp_record')->fetchAll());

        TimestampRecord::insertAll([
            ['body' => 'automatic'],
            [
                'body' => 'explicit',
                'created_on' => new DateTimeImmutable('2020-01-02 03:04:05', new DateTimeZone('+08:00')),
                'modify_time' => 123456,
            ],
        ]);
        $rows = $this->pdo->query('SELECT body, created_on, modify_time FROM timestamp_record ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('2020-01-01 19:04:05', $rows[2]['created_on']);
        self::assertSame(123456, (int) $rows[2]['modify_time']);
        self::assertGreaterThan(0, (int) $rows[1]['modify_time']);

        TimestampRecord::upsert(['id' => 100, 'body' => 'upsert']);
        $upsert = $this->pdo->query('SELECT created_on, modify_time FROM timestamp_record WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $upsert['created_on']);
        self::assertGreaterThan(0, (int) $upsert['modify_time']);
        $originalCreatedOn = $upsert['created_on'];
        TimestampRecord::upsert([
            'id' => 100,
            'body' => 'upserted',
            'created_on' => new DateTimeImmutable('@1'),
            'modify_time' => $upsert['modify_time'] + 1,
        ]);
        self::assertSame($originalCreatedOn, $this->pdo->query('SELECT created_on FROM timestamp_record WHERE id = 100')->fetchColumn());
        self::assertSame('upserted', $this->pdo->query('SELECT body FROM timestamp_record WHERE id = 100')->fetchColumn());
        self::assertNotSame(
            (int) $upsert['modify_time'],
            (int) $this->pdo->query('SELECT modify_time FROM timestamp_record WHERE id = 100')->fetchColumn(),
            '默认冲突更新必须维护 updated_at；精确值取决于测试时钟',
        );
    }

    public function testUpsertIgnoreDoesNotChangeUpdatedAt(): void
    {
        TimestampRecord::upsert(['id' => 1, 'body' => 'original', 'modify_time' => 123]);
        $before = $this->pdo->query('SELECT * FROM timestamp_record')->fetch(PDO::FETCH_ASSOC);

        $affected = TimestampRecord::upsert(['id' => 1, 'body' => 'ignored', 'modify_time' => 456], []);
        $after = $this->pdo->query('SELECT * FROM timestamp_record')->fetch(PDO::FETCH_ASSOC);

        self::assertSame(0, $affected);
        self::assertSame($before, $after);
    }

    #[DataProvider('invalidUpsertFields')]
    public function testInvalidUpsertFieldsLeaveExistingRowsUnchanged(array $values, ?array $updates, ?array $conflicts, string $exception, string $message): void
    {
        TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 123], []);
        $before = $this->pdo->query('SELECT * FROM timestamp_only_record')->fetchAll(PDO::FETCH_ASSOC);

        try {
            TimestampOnlyRecord::upsert($values, $updates, $conflicts);
            self::fail('无效 UPSERT 必须抛出异常');
        } catch (\InvalidArgumentException|\Pixiaoka\Orm\Exception\IdentityException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame($before, $this->pdo->query('SELECT * FROM timestamp_only_record')->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function invalidUpsertFields(): array
    {
        $argument = \InvalidArgumentException::class;
        $identity = \Pixiaoka\Orm\Exception\IdentityException::class;

        return [
            'empty input' => [[], [], null, $argument, 'requires at least one field'],
            'empty conflict key' => [['id' => 1], [], [], $argument, 'requires a conflict key'],
            'positional input' => [[1, 'id' => 1], [], null, $argument, 'field names must be strings'],
            'missing key value' => [['modify_time' => 9], [], null, $identity, 'missing conflict key id'],
            'update conflict key' => [['id' => 1], ['id'], null, $argument, 'cannot update conflict keys'],
        ];
    }

    public function testUpsertWithoutExplicitUpdatesRequiresAnOrdinaryField(): void
    {
        TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 123], []);
        $before = $this->pdo->query('SELECT * FROM timestamp_only_record')->fetch(PDO::FETCH_ASSOC);

        try {
            TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 456]);
            self::fail('默认 UPSERT 不能把自动 updated_at 当成用户要求更新的普通字段');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'UPSERT has no fields to update; pass $updateColumns=[] to ignore conflicts',
                $e->getMessage(),
            );
        }

        self::assertSame(
            $before,
            $this->pdo->query('SELECT * FROM timestamp_only_record')->fetch(PDO::FETCH_ASSOC),
        );
    }

    #[DataProvider('liveDrivers')]
    public function testRealDatabaseUpsertWithoutOrdinaryUpdateFieldIsRejected(string $driver): void
    {
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('未配置 ' . $prefix . '_DSN');
        }
        $pdo = new PDO($dsn, getenv($prefix . '_USER') ?: null, getenv($prefix . '_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $table = 'timestamp_only_' . bin2hex(random_bytes(5));
        $quote = $driver === 'mysql' ? '`' : '"';
        $q = static fn (string $identifier): string => $quote . $identifier . $quote;
        $primary = $driver === 'mysql' ? 'BIGINT PRIMARY KEY' : 'INTEGER PRIMARY KEY';
        $pdo->exec("CREATE TABLE {$q($table)} (id $primary, created_on BIGINT NOT NULL, modify_time BIGINT NOT NULL)");
        TimestampOnlyRecord::$liveTable = $table;
        $reader = new MetadataReader();
        $reader->read(TimestampOnlyRecord::class);
        Session::use(new EntityGateway(new PdoConnection($pdo), $reader));

        try {
            TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 123], []);
            $before = $pdo->query("SELECT * FROM {$q($table)}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(123, (int) $before['modify_time']);

            try {
                TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 456]);
                self::fail('默认 UPSERT 不能把自动 updated_at 当成用户要求更新的普通字段');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    'UPSERT has no fields to update; pass $updateColumns=[] to ignore conflicts',
                    $e->getMessage(),
                );
            }
            self::assertSame($before, $pdo->query("SELECT * FROM {$q($table)}")->fetch(PDO::FETCH_ASSOC));

            TimestampOnlyRecord::upsert(['id' => 1, 'modify_time' => 456], []);
            self::assertSame($before, $pdo->query("SELECT * FROM {$q($table)}")->fetch(PDO::FETCH_ASSOC));
        } finally {
            Session::discard();
            TimestampOnlyRecord::$liveTable = 'timestamp_only_record';
            $pdo->exec("DROP TABLE IF EXISTS {$q($table)}");
        }
    }

    /** @return array<string, array{string}> */
    public static function liveDrivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    public function testDefaultUpdatedAtKeepsExplicitZero(): void
    {
        $record = new TimestampRecord();
        $record->body = 'first';
        $record->modify_time = 0;
        $record->save();
        self::assertSame(0, $record->modify_time);
        self::assertSame(0, (int) $this->pdo->query('SELECT modify_time FROM timestamp_record')->fetchColumn());
    }

    public function testUpdatedAtRespectsExplicitlyChangedValue(): void
    {
        $record = new TimestampRecord();
        $record->body = 'first';
        $record->save();
        $record = TimestampRecord::find(['id' => $record->id]);
        self::assertNotNull($record);
        $record->body = 'changed';
        $record->modify_time = 777;
        $record->save();
        self::assertSame(777, (int) $this->pdo->query('SELECT modify_time FROM timestamp_record')->fetchColumn());
    }

    public function testTimestampRolesSupportUnixDateTimeStorage(): void
    {
        $meta = (new MetadataReader())->read(UnixTimestampRecord::class);
        self::assertSame(Temporal::UNIX, $meta->timestampField('created_at')?->temporal);
    }

    /** @return array<class-string, string> */
    public static function invalidTimestampModels(): array
    {
        return [
            DuplicateCreatedAtRecord::class => [DuplicateCreatedAtRecord::class, 'duplicates timestamp role'],
            DualRoleTimestampRecord::class => [DualRoleTimestampRecord::class, 'cannot serve as both the creation and update timestamp'],
            StringTimestampRecord::class => [StringTimestampRecord::class, 'only support int or DateTimeImmutable'],
            MissingColumnTimestampRecord::class => [MissingColumnTimestampRecord::class, 'timestamp roles must also declare #[Column]'],
            PrimaryTimestampRecord::class => [PrimaryTimestampRecord::class, 'primary keys cannot be automatic timestamp fields'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTimestampModels')]
    public function testInvalidTimestampConfigurationIsRejected(string $class, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);
        (new MetadataReader())->read($class);
    }
}

final class TimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $body;

    #[Column]
    #[CreatedAt]
    public DateTimeImmutable $created_on;

    #[Column(name: 'modify_time')]
    #[UpdatedAt]
    public int $modify_time;

    public static function table(): string
    {
        return 'timestamp_record';
    }
}

final class TimestampOnlyRecord extends Entity
{
    public static string $liveTable = 'timestamp_only_record';

    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    #[CreatedAt]
    public int $created_on;

    #[Column(name: 'modify_time')]
    #[UpdatedAt]
    public int $modify_time;

    public static function table(): string
    {
        return self::$liveTable;
    }
}

final class UnixTimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;

    #[Column]
    #[CreatedAt]
    #[Temporal(Temporal::UNIX)]
    public DateTimeImmutable $created_on;

    public static function table(): string
    {
        return 'unused';
    }
}

final class DuplicateCreatedAtRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;
    #[Column]
    #[CreatedAt]
    public int $first;
    #[Column]
    #[CreatedAt]
    public int $second;
    public static function table(): string { return 'unused'; }
}

final class DualRoleTimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;
    #[Column]
    #[CreatedAt]
    #[UpdatedAt]
    public int $at;
    public static function table(): string { return 'unused'; }
}

final class StringTimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;
    #[Column]
    #[CreatedAt]
    public string $created_on;
    public static function table(): string { return 'unused'; }
}

final class MissingColumnTimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;
    #[CreatedAt]
    public int $created_on;
    public static function table(): string { return 'unused'; }
}

final class PrimaryTimestampRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    #[CreatedAt]
    public int $id;
    public static function table(): string { return 'unused'; }
}
