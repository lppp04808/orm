<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
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
        TimestampRecord::upsert(['id' => 100, 'body' => 'upserted', 'created_on' => new DateTimeImmutable('@1')]);
        self::assertSame($originalCreatedOn, $this->pdo->query('SELECT created_on FROM timestamp_record WHERE id = 100')->fetchColumn());
        self::assertSame('upserted', $this->pdo->query('SELECT body FROM timestamp_record WHERE id = 100')->fetchColumn());
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
