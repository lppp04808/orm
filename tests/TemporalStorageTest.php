<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Schema\ColumnSchema;
use Pixiaoka\Orm\Schema\SchemaDiffer;
use Pixiaoka\Orm\Schema\SchemaInspector;
use Pixiaoka\Orm\Schema\TableSchema;
use Pixiaoka\Orm\Session;

final class TemporalStorageTest extends TestCase
{
    private Caster $caster;
    private MetadataReader $reader;

    protected function setUp(): void
    {
        Session::discard();
        $this->caster = new Caster();
        $this->reader = new MetadataReader();
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testStorageConstantsAndDateRoundTripUseUtc(): void
    {
        self::assertSame('text', Temporal::TEXT);
        self::assertSame('date', Temporal::DATE);
        self::assertSame('text_us', Temporal::TEXT_US);
        self::assertSame('unix', Temporal::UNIX);
        self::assertSame('unix_ms', Temporal::UNIX_MS);
        $field = $this->reader->read(TemporalStorageRecord::class)->field('onDate');
        $time = new DateTimeImmutable('2026-11-24 02:07:53.123456', new DateTimeZone('Asia/Shanghai'));

        self::assertSame('2026-11-23', $this->caster->toDb(TemporalStorageRecord::class, $field, $time));
        $restored = $this->caster->toPhp(TemporalStorageRecord::class, $field, '2026-11-23');
        self::assertSame('2026-11-23 00:00:00.000000 UTC', $restored->format('Y-m-d H:i:s.u e'));
    }

    /** @return array<string, array{string, string}> */
    public static function microsecondTexts(): array
    {
        return [
            'no fraction' => ['2026-11-24 02:07:53', '2026-11-24 02:07:53.000000'],
            'one digit' => ['2026-11-24 02:07:53.5', '2026-11-24 02:07:53.500000'],
            'trailing zero omitted' => ['2026-11-24 02:07:53.12', '2026-11-24 02:07:53.120000'],
            'full precision' => ['2026-11-24 02:07:53.123456', '2026-11-24 02:07:53.123456'],
        ];
    }

    #[DataProvider('microsecondTexts')]
    public function testMicrosecondTextAcceptsPostgresPrecisionForms(string $input, string $expected): void
    {
        $field = $this->reader->read(TemporalStorageRecord::class)->field('atMicros');
        $restored = $this->caster->toPhp(TemporalStorageRecord::class, $field, $input);
        self::assertSame($expected, $restored->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $restored->getTimezone()->getName());
    }

    public function testMicrosecondStorageNormalizesTimezoneBeforeWriting(): void
    {
        $field = $this->reader->read(TemporalStorageRecord::class)->field('atMicros');
        $time = new DateTimeImmutable('2026-11-24 10:07:53.123456', new DateTimeZone('+08:00'));
        $stored = $this->caster->toDb(TemporalStorageRecord::class, $field, $time);
        self::assertSame('2026-11-24 02:07:53.123456', $stored);
        self::assertSame(
            '2026-11-24 02:07:53.123456 UTC',
            $this->caster->toPhp(TemporalStorageRecord::class, $field, $stored)->format('Y-m-d H:i:s.u e'),
        );
    }

    /** @return array<string, array{int|string, string}> */
    public static function millisecondTimestamps(): array
    {
        return [
            'positive' => [1_764_988_073_123, '2025-12-06 02:27:53.123000'],
            'negative millisecond' => [-1, '1969-12-31 23:59:59.999000'],
            'positive millisecond' => [1, '1970-01-01 00:00:00.001000'],
            'negative fractional second' => [-1001, '1969-12-31 23:59:58.999000'],
            'negative integer string' => ['-1', '1969-12-31 23:59:59.999000'],
            'positive integer string' => ['1001', '1970-01-01 00:00:01.001000'],
        ];
    }

    #[DataProvider('millisecondTimestamps')]
    public function testMillisecondStorageRoundTripsExactly(int|string $input, string $expected): void
    {
        $field = $this->reader->read(TemporalStorageRecord::class)->field('atMillis');
        $restored = $this->caster->toPhp(TemporalStorageRecord::class, $field, $input);
        self::assertSame($expected, $restored->format('Y-m-d H:i:s.u'));
        self::assertSame((int) $input, $this->caster->toDb(TemporalStorageRecord::class, $field, $restored));
    }

    public function testUnixMillisecondsUseIntegerPrecision(): void
    {
        $field = $this->reader->read(TemporalStorageRecord::class)->field('atMillis');
        $time = DateTimeImmutable::createFromFormat('!U.u', '9007199254740.991000');
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        self::assertSame(9_007_199_254_740_991, $time->getTimestamp() * 1000 + 991);
        self::assertSame(9_007_199_254_740_991, $this->caster->toDb(TemporalStorageRecord::class, $field, $time));
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidTemporalValues(): array
    {
        return [
            'invalid date' => ['onDate', '2026-02-30', 'Cannot parse temporal text'],
            'date includes time' => ['onDate', '2026-11-24 00:00:00', 'Cannot parse temporal text'],
            'microseconds include timezone' => ['atMicros', '2026-11-24 02:07:53.5+00:00', 'Cannot parse temporal text'],
            'invalid microsecond date' => ['atMicros', '2026-02-30 02:07:53.5', 'Cannot parse temporal text'],
            'too many fractional digits' => ['atMicros', '2026-11-24 02:07:53.1234567', 'Cannot parse temporal text'],
            'decimal millisecond timestamp' => ['atMillis', '1.5', 'Cannot parse value as a Unix timestamp'],
            'signed millisecond timestamp' => ['atMillis', '+1', 'Cannot parse value as a Unix timestamp'],
        ];
    }

    #[DataProvider('invalidTemporalValues')]
    public function testInvalidStorageValuesThrowCastException(string $property, string $value, string $message): void
    {
        $field = $this->reader->read(TemporalStorageRecord::class)->field($property);
        $this->expectException(CastException::class);
        $this->expectExceptionMessage($message);
        $this->caster->toPhp(TemporalStorageRecord::class, $field, $value);
    }

    #[DataProvider('outOfRangeUnixValues')]
    public function testUnixStorageRejectsValuesOutsidePhpIntegerRange(string $property, string $input): void
    {
        $field = $this->reader->read($property === 'nUnix' ? TemporalUnixSecondsRecord::class : TemporalStorageRecord::class)->field($property);
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('Integer value exceeds PHP integer range');
        $this->caster->toPhp(TemporalStorageRecord::class, $field, $input);
    }

    /** @return array<string, array{string, string}> */
    public static function outOfRangeUnixValues(): array
    {
        $overflow = '9223372036854775808';
        $underflow = '-9223372036854775809';
        return [
            'seconds overflow' => ['nUnix', $overflow],
            'seconds underflow' => ['nUnix', $underflow],
            'milliseconds overflow' => ['atMillis', $overflow],
            'milliseconds underflow' => ['atMillis', $underflow],
        ];
    }

    #[DataProvider('unixIntegerBoundaries')]
    public function testUnixStorageRetainsValidIntegerBoundaries(string $property, string $input): void
    {
        $model = $property === 'nUnix' ? TemporalUnixSecondsRecord::class : TemporalStorageRecord::class;
        $field = $this->reader->read($model)->field($property);
        $value = $this->caster->toPhp($model, $field, $input);
        self::assertSame((int) $input, $this->caster->toDb($model, $field, $value));
    }

    /** @return array<string, array{string, string}> */
    public static function unixIntegerBoundaries(): array
    {
        return [
            'seconds minimum' => ['nUnix', (string) PHP_INT_MIN],
            'seconds maximum' => ['nUnix', (string) PHP_INT_MAX],
            'milliseconds minimum' => ['atMillis', (string) PHP_INT_MIN],
            'milliseconds maximum' => ['atMillis', (string) PHP_INT_MAX],
        ];
    }

    public function testUnixMillisecondOutputRejectsOverflowInLastWholeSecond(): void
    {
        $model = TemporalStorageRecord::class;
        $field = $this->reader->read($model)->field('atMillis');
        $seconds = intdiv(PHP_INT_MAX, 1000);
        $fraction = (PHP_INT_MAX % 1000 + 1) * 1000;
        $value = (new DateTimeImmutable('@' . $seconds))->modify('+' . $fraction . ' microseconds');

        $this->expectException(CastException::class);
        $this->expectExceptionMessage('Unix millisecond timestamp exceeds PHP integer range');
        $this->caster->toDb($model, $field, $value);
    }

    public function testDisplayFormattingAffectsOnlyToArray(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE temporal_storage_record (id INTEGER PRIMARY KEY, onDate TEXT NOT NULL, at_micros TEXT NOT NULL, atMillis INTEGER NOT NULL)');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::use($gateway);
        $queries = 0;
        $unsubscribe = $gateway->subscribe(static function (Event $event) use (&$queries): void {
            if ($event instanceof SqlEvent) {
                $queries++;
            }
        });
        $record = new TemporalStorageRecord();
        $record->id = 1;
        $record->onDate = new DateTimeImmutable('2026-11-24', new DateTimeZone('UTC'));
        $record->atMicros = new DateTimeImmutable('2026-11-24 02:07:53.123456', new DateTimeZone('UTC'));
        $record->atMillis = $record->atMicros;
        $record->save();

        self::assertSame('2026-11-23 18:07:53 PST', $record->toArray()['at_micros']);
        self::assertSame(
            '2026-11-24 02:07:53.123456',
            $pdo->query('SELECT at_micros FROM temporal_storage_record')->fetchColumn(),
        );
        $field = $this->reader->read(TemporalStorageRecord::class)->field('atMicros');
        self::assertSame(
            '2026-11-24 02:07:53.123456',
            $this->caster->toDb(TemporalStorageRecord::class, $field, $record->atMicros),
        );

        $found = TemporalStorageRecord::find(['id' => 1]);
        self::assertNotNull($found);
        $queriesBeforeSave = $queries;
        $found->atMicros = new DateTimeImmutable('2026-11-24 10:07:53.123456', new DateTimeZone('+08:00'));
        $found->save();
        self::assertSame($queriesBeforeSave, $queries, 'An equal instant with a display format must not issue SQL');
        self::assertSame($found, TemporalStorageRecord::find(['id' => 1]), 'Display formatting must not alter RowCache identity');
        $unsubscribe();
    }

    public function testToArrayWithoutDisplayFormatRetainsStoredRepresentation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::use($gateway);
        $record = new TemporalStoragePlainRecord();
        $record->id = 1;
        $record->at = new DateTimeImmutable('2026-11-24 02:07:53.123456', new DateTimeZone('UTC'));
        self::assertSame('2026-11-24 02:07:53', $record->toArray()['at']);
    }

    public function testAutomaticTimestampUsesMicrosecondsAndPreservesExplicitNow(): void
    {
        $micros = $this->reader->read(TemporalStorageRecord::class)->field('atMicros');
        $millis = $this->reader->read(TemporalStorageRecord::class)->field('atMillis');
        $current = $this->caster->currentTimestamp($micros);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $this->caster->toDb(TemporalStorageRecord::class, $micros, $current));
        self::assertSame('UTC', $current->getTimezone()->getName());
        self::assertSame(
            $current->getTimestamp() * 1000 + intdiv((int) $current->format('u'), 1000),
            $this->caster->toDb(TemporalStorageRecord::class, $millis, $current),
        );
        self::assertSame(
            '1970-01-01 00:02:03.000000',
            $this->caster->currentTimestamp($micros, 123)->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '1970-01-01 00:02:03.000000',
            $this->caster->currentTimestamp($millis, 123)->format('Y-m-d H:i:s.u'),
        );
    }

    public function testAutomaticDateTimestampIsPersistedAtUtcMidnight(): void
    {
        $field = $this->reader->read(TemporalAutomaticDateRecord::class)->field('createdOn');
        $now = $this->caster->currentTimestamp($field);
        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame($now->format('Y-m-d'), $this->caster->toDb(TemporalAutomaticDateRecord::class, $field, $now));
    }

    public function testAutomaticTimestampsRetainDeclaredPrecisionOnInsert(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE temporal_automatic_precision (id INTEGER PRIMARY KEY, created_us TEXT NOT NULL, created_ms INTEGER NOT NULL)');
        Session::use(new EntityGateway(new PdoConnection($pdo)));
        $record = new TemporalAutomaticPrecisionRecord();
        $record->save();
        $raw = $pdo->query('SELECT created_us, created_ms FROM temporal_automatic_precision')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($raw);
        self::assertSame($record->createdUs->format('Y-m-d H:i:s.u'), $raw['created_us']);
        self::assertSame(
            $record->createdMs->getTimestamp() * 1000 + intdiv((int) $record->createdMs->format('u'), 1000),
            (int) $raw['created_ms'],
        );
    }

    public function testMetadataExportImportAndLegacyCacheRemainCompatible(): void
    {
        $this->reader->read(TemporalStorageRecord::class);
        $cache = $this->reader->export();
        $definition = $cache['models'][TemporalStorageRecord::class]['fields']['atMicros'];
        self::assertSame('Y-m-d H:i:s T', $definition['temporalDisplayFormat']);
        self::assertSame('America/Los_Angeles', $definition['temporalDisplayTimezone']);
        $restored = (new MetadataReader($cache))->read(TemporalStorageRecord::class)->field('atMicros');
        self::assertSame('Y-m-d H:i:s T', $restored->temporalDisplayFormat);
        self::assertSame('America/Los_Angeles', $restored->temporalDisplayTimezone);

        unset(
            $cache['models'][TemporalStorageRecord::class]['fields']['atMicros']['temporalDisplayFormat'],
            $cache['models'][TemporalStorageRecord::class]['fields']['atMicros']['temporalDisplayTimezone'],
        );
        $legacy = (new MetadataReader($cache))->read(TemporalStorageRecord::class)->field('atMicros');
        self::assertNull($legacy->temporalDisplayFormat);
        self::assertNull($legacy->temporalDisplayTimezone);
    }

    /** @return array<class-string, string> */
    public static function invalidTemporalMetadata(): array
    {
        return [
            InvalidTemporalStorageRecord::class => [InvalidTemporalStorageRecord::class, 'invalid temporal storage'],
            EmptyTemporalDisplayRecord::class => [EmptyTemporalDisplayRecord::class, 'display format must not be empty'],
            TemporalTimezoneWithoutFormatRecord::class => [TemporalTimezoneWithoutFormatRecord::class, 'display timezone requires a display format'],
            InvalidTemporalTimezoneRecord::class => [InvalidTemporalTimezoneRecord::class, 'invalid temporal display timezone'],
        ];
    }

    #[DataProvider('invalidTemporalMetadata')]
    public function testInvalidTemporalMetadataIsRejected(string $class, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);
        $this->reader->read($class);
    }

    public function testImportedDisplayMetadataTypesAreValidated(): void
    {
        $this->reader->read(TemporalStorageRecord::class);
        $cache = $this->reader->export();
        $cache['models'][TemporalStorageRecord::class]['fields']['atMicros']['temporalDisplayFormat'] = false;
        $reader = new MetadataReader($cache);
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('invalid metadata cache field types');
        $reader->read(TemporalStorageRecord::class);
    }

    public function testRelationKeysIgnoreDisplayConfiguration(): void
    {
        $relation = $this->reader->relation(TemporalRelationSource::class, 'target');
        self::assertSame('at', $relation->sourceKey[0]->property);
        self::assertSame('at', $relation->targetKey[0]->property);
        self::assertSame('Y', $relation->sourceKey[0]->temporalDisplayFormat);
        self::assertNull($relation->targetKey[0]->temporalDisplayFormat);
    }

    /** @return array<string, array{string}> */
    public static function temporalSchemaTypes(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    #[DataProvider('temporalSchemaTypes')]
    public function testTemporalSchemaPreviewsAndCompatibleTypes(string $driver): void
    {
        $types = match ($driver) {
            'mysql' => ['on_date' => 'date', 'at_micros' => 'datetime(6)', 'at_millis' => 'bigint'],
            'pgsql' => ['on_date' => 'date', 'at_micros' => 'timestamp(6)', 'at_millis' => 'bigint'],
            default => ['on_date' => 'text', 'at_micros' => 'text', 'at_millis' => 'integer'],
        };
        $expected = [
            'id' => $driver === 'sqlite' ? 'integer' : 'bigint',
            'on_date' => $types['on_date'],
            'at_micros' => $types['at_micros'],
            'at_millis' => $types['at_millis'],
        ];
        $empty = new TableSchema('temporal_schema_record', [new ColumnSchema('existing', 'text', false, null, 0, false)]);
        $meta = $this->reader->read(TemporalSchemaRecord::class);
        $differ = new SchemaDiffer();
        $report = $differ->compare($meta, $empty, $driver);
        $changes = [];
        foreach ($report['changes'] as $change) {
            if ($change['kind'] !== 'add_column') {
                continue;
            }
            $changes[$change['name']] = $change;
            self::assertSame($expected[$change['name']] ?? null, $change['expected']);
            self::assertFalse($change['executable']);
        }
        self::assertSame(['on_date', 'at_micros', 'at_millis'], array_values(array_diff(array_keys($changes), ['id'])));

        $columns = [];
        foreach ($expected as $name => $type) {
            $columns[] = new ColumnSchema($name, strtoupper($type), false, null, $name === 'id' ? 1 : 0, false);
        }
        $matching = new TableSchema('temporal_schema_record', $columns);
        self::assertSame([], $differ->compare($meta, $matching, $driver)['changes']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function temporalPrecisionDifferences(): array
    {
        return [
            'MySQL micros' => ['mysql', 'at_micros', 'datetime'],
            'PostgreSQL micros' => ['pgsql', 'at_micros', 'timestamp(3) without time zone'],
            'MySQL date' => ['mysql', 'on_date', 'datetime'],
            'PostgreSQL date' => ['pgsql', 'on_date', 'timestamp without time zone'],
        ];
    }

    #[DataProvider('temporalPrecisionDifferences')]
    public function testTemporalSchemaPrecisionAndDateChangesAreNotIgnored(string $driver, string $column, string $actual): void
    {
        $table = new TableSchema('temporal_schema_record', [new ColumnSchema($column, $actual, false, null, 0, false)]);
        $report = (new SchemaDiffer())->compare($this->reader->read(TemporalSchemaRecord::class), $table, $driver);
        $types = array_values(array_filter($report['changes'], static fn (array $change): bool => $change['kind'] === 'change_type'));
        self::assertCount(1, $types);
        self::assertSame($column, $types[0]['name']);
    }

    #[DataProvider('postgresTimezoneTypes')]
    public function testPostgresTimezoneAwareTypesAreNotEquivalentToModelTimestamp(string $actual): void
    {
        $table = new TableSchema('temporal_schema_record', [new ColumnSchema('at_micros', $actual, false, null, 0, false)]);
        $report = (new SchemaDiffer())->compare($this->reader->read(TemporalSchemaRecord::class), $table, 'pgsql');
        $types = array_values(array_filter($report['changes'], static fn (array $change): bool => $change['kind'] === 'change_type'));
        self::assertCount(1, $types);
        self::assertSame('at_micros', $types[0]['name']);
    }

    /** @return array<string, array{string}> */
    public static function postgresTimezoneTypes(): array
    {
        return [
            'with time zone' => ['timestamp with time zone'],
            'alias' => ['timestamptz'],
            'precision with time zone' => ['timestamp(6) with time zone'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function liveDrivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('liveDrivers')]
    public function testRealDatabaseTemporalStorageAndSchema(string $driver): void
    {
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('未配置 ' . $prefix . '_DSN');
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('当前 PHP 未加载 pdo_' . $driver);
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');
        $pdo = new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $table = 'temporal_live_' . bin2hex(random_bytes(8));
        $quote = $driver === 'mysql' ? '`' : '"';
        $quoted = $quote . $table . $quote;
        $dateType = 'DATE';
        $microsType = $driver === 'mysql' ? 'DATETIME(6)' : 'TIMESTAMP(6)';
        try {
            $pdo->exec("CREATE TABLE $quoted (id BIGINT NOT NULL PRIMARY KEY, on_date $dateType NOT NULL, at_micros $microsType NOT NULL, at_millis BIGINT NOT NULL)");
            $meta = $this->reader->read(TemporalSchemaRecord::class);
            $caster = new Caster();
            $time = new DateTimeImmutable('2026-11-24 10:07:53.123456', new DateTimeZone('Asia/Shanghai'));
            $insert = $pdo->prepare("INSERT INTO $quoted (id, on_date, at_micros, at_millis) VALUES (1, :on_date, :at_micros, :at_millis)");
            foreach ($meta->fields as $property => $field) {
                if ($property !== 'id') {
                    $insert->bindValue(':' . $field->column, $caster->toDb(TemporalSchemaRecord::class, $field, $time));
                }
            }
            $insert->execute();

            $raw = $pdo->query("SELECT on_date, at_micros, at_millis FROM $quoted")->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($raw);
            self::assertSame('2026-11-24', $raw['on_date']);
            self::assertSame('2026-11-24 02:07:53.123456', $raw['at_micros']);
            self::assertSame('1795486073123', (string) $raw['at_millis']);
            self::assertSame(
                '2026-11-24 02:07:53.123456 UTC',
                $caster->toPhp(TemporalSchemaRecord::class, $meta->field('atMicros'), $raw['at_micros'])->format('Y-m-d H:i:s.u e'),
            );
            self::assertSame(
                '2026-11-24 02:07:53.123000 UTC',
                $caster->toPhp(TemporalSchemaRecord::class, $meta->field('atMillis'), $raw['at_millis'])->format('Y-m-d H:i:s.u e'),
            );
            $actual = (new SchemaInspector($pdo))->table($table);
            self::assertSame('date', strtolower($actual->columns[1]->databaseType));
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta($meta->class, $table, $meta->fields, $meta->primaryKey, $meta->identityKind, $meta->timestamps);
            $report = (new SchemaDiffer())->compare($model, $actual, $driver);
            self::assertSame([], $report['changes']);
        } finally {
            Session::discard();
            $pdo->exec("DROP TABLE IF EXISTS $quoted");
        }
    }
}

trait TemporalStorageIdentity
{
    #[Column, PrimaryKey]
    public int $id = 1;
}

final class TemporalStorageRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(Temporal::DATE)]
    public DateTimeImmutable $onDate;

    #[Column(name: 'at_micros')]
    #[Temporal(Temporal::TEXT_US, displayFormat: 'Y-m-d H:i:s T', displayTimezone: 'America/Los_Angeles')]
    public DateTimeImmutable $atMicros;

    #[Column]
    #[Temporal(Temporal::UNIX_MS)]
    public DateTimeImmutable $atMillis;

    public static function table(): string
    {
        return 'temporal_storage_record';
    }
}

final class TemporalStoragePlainRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}

final class TemporalAutomaticDateRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[CreatedAt]
    #[Temporal(Temporal::DATE)]
    public DateTimeImmutable $createdOn;

    public static function table(): string
    {
        return 'unused';
    }
}

final class TemporalAutomaticPrecisionRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column(name: 'created_us')]
    #[CreatedAt]
    #[Temporal(Temporal::TEXT_US)]
    public DateTimeImmutable $createdUs;

    #[Column(name: 'created_ms')]
    #[UpdatedAt]
    #[Temporal(Temporal::UNIX_MS)]
    public DateTimeImmutable $createdMs;

    public static function table(): string
    {
        return 'temporal_automatic_precision';
    }
}

final class TemporalUnixSecondsRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(Temporal::UNIX)]
    public DateTimeImmutable $nUnix;

    public static function table(): string
    {
        return 'unused';
    }
}

final class TemporalSchemaRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column(name: 'on_date')]
    #[Temporal(Temporal::DATE)]
    public DateTimeImmutable $onDate;

    #[Column(name: 'at_micros')]
    #[Temporal(Temporal::TEXT_US)]
    public DateTimeImmutable $atMicros;

    #[Column(name: 'at_millis')]
    #[Temporal(Temporal::UNIX_MS)]
    public DateTimeImmutable $atMillis;

    public static function table(): string
    {
        return 'temporal_schema_record';
    }
}

final class TemporalRelationSource extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(Temporal::TEXT_US, displayFormat: 'Y', displayTimezone: 'UTC')]
    public DateTimeImmutable $at;

    #[BelongsToAttribute(TemporalRelationTarget::class, localKey: ['at'], foreignKey: ['at'])]
    public function target(): BelongsTo
    {
        return $this->makeRelation();
    }

    public static function table(): string
    {
        return 'unused';
    }
}

final class TemporalRelationTarget extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(Temporal::TEXT_US)]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}

final class InvalidTemporalStorageRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal('datetime')]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}

final class EmptyTemporalDisplayRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(displayFormat: '  ')]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}

final class TemporalTimezoneWithoutFormatRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(displayTimezone: 'Asia/Shanghai')]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}

final class InvalidTemporalTimezoneRecord extends Entity
{
    use TemporalStorageIdentity;

    #[Column]
    #[Temporal(displayFormat: 'Y-m-d', displayTimezone: 'Not/A_Timezone')]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}
