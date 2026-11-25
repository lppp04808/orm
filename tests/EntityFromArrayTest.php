<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Cast\Castable;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Relation\HasMany as HasManyQuery;
use Pixiaoka\Orm\Session;

enum FromArrayStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum FromArrayKind: int
{
    case One = 1;
    case Two = 2;
}

final readonly class FromArrayAmount implements Castable
{
    public function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Amount must not be negative');
        }
    }

    public function toDatabase(): int
    {
        return $this->cents;
    }

    public static function fromDatabase(int|float|string $value): static
    {
        if (!is_int($value) && preg_match('/^\d+$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Invalid amount');
        }

        return new self((int) $value);
    }
}

final class EntityFromArrayTest extends TestCase
{
    protected function setUp(): void
    {
        Session::discard();
        FromArrayRecord::$constructorCount = 0;
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testScalarValuesAndMixedKeysWorkWithoutSession(): void
    {
        $record = FromArrayRecord::fromArray($this->scalarData());

        self::assertSame(1, FromArrayRecord::$constructorCount);
        self::assertSame(1, $record->constructorCalls);
        self::assertTrue($record->isNew());
        self::assertFalse($record->hasGatewayBinding());
        self::assertNull($record->boundGateway());
        self::assertSame([], $record->takeOriginal());
        self::assertSame([], $record->hooks);
        self::assertSame('keep', $record->transient);
        self::assertFalse($record->propSet('id'));
        self::assertSame('Alice', $record->name);
        self::assertSame(28, $record->age);
        self::assertSame(9.5, $record->score);
        self::assertTrue($record->active);
        self::assertSame(['tags' => ['orm'], 'nested' => ['ok' => true]], $record->payload);
        self::assertSame('12345.67', (string) $record->price);
        self::assertSame(FromArrayStatus::Published, $record->status);
        self::assertSame(FromArrayKind::Two, $record->kind);
        self::assertSame(1299, $record->amount->cents);
        self::assertSame('2026-11-24 02:07:53 UTC', $record->happenedAt->format('Y-m-d H:i:s e'));
        self::assertSame(1_764_000_000, $record->createdAt->getTimestamp());
        self::assertSame('2025-11-24 16:00:00.123000', $record->createdMillis->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-11-24 00:00:00 UTC', $record->calendarDate->format('Y-m-d H:i:s e'));
        self::assertSame('2026-11-24 02:07:53.123456', $record->preciseAt->format('Y-m-d H:i:s.u'));
        self::assertNull($record->remark);
        self::assertSame('changed', $record->label);
        self::assertSame('default', $record->mode);
    }

    public function testObjectValuesDefaultsAndUninitializedStateArePreserved(): void
    {
        $time = new DateTimeImmutable('2026-11-24 10:07:53', new DateTimeZone('Asia/Shanghai'));
        $unix = new DateTimeImmutable('@1764000000');
        $millis = DateTimeImmutable::createFromFormat('!U.u', '1764000000.123456');
        $date = new DateTimeImmutable('2026-11-24 00:00:00', new DateTimeZone('UTC'));
        $precise = new DateTimeImmutable('2026-11-24 02:07:53.123456', new DateTimeZone('UTC'));
        $decimal = BcDecimal::fromString('12345.67');
        $amount = new FromArrayAmount(1299);
        self::assertInstanceOf(DateTimeImmutable::class, $millis);

        $data = $this->scalarData();
        $data['payload'] = ['direct' => true];
        $data['price'] = $decimal;
        $data['status'] = FromArrayStatus::Published;
        $data['kind'] = FromArrayKind::Two;
        $data['amount'] = $amount;
        $data['happenedAt'] = $time;
        $data['created_unix'] = $unix;
        $data['created_millis'] = $millis;
        $data['calendar_date'] = $date;
        $data['precise_at'] = $precise;
        unset($data['default_label'], $data['required']);
        $record = FromArrayRecord::fromArray($data);

        self::assertSame($decimal, $record->price);
        self::assertSame(FromArrayStatus::Published, $record->status);
        self::assertSame(FromArrayKind::Two, $record->kind);
        self::assertSame($amount, $record->amount);
        self::assertSame($time, $record->happenedAt);
        self::assertSame($unix, $record->createdAt);
        self::assertSame($millis, $record->createdMillis);
        self::assertSame($date, $record->calendarDate);
        self::assertSame($precise, $record->preciseAt);
        self::assertSame(['direct' => true], $record->payload);
        self::assertSame('constructed', $record->label);
        self::assertSame('default', $record->mode);
        self::assertFalse($record->propSet('required'));
        self::assertFalse($record->propSet('id'));
        self::assertTrue($record->isNew());
        self::assertSame([], $record->takeOriginal());
    }

    /** @return array<string, array{string}> */
    public static function nonColumnKeys(): array
    {
        return [
            'ordinary property' => ['transient'],
            'relation method' => ['children'],
            'new flag' => ['ormNew'],
            'gateway binding' => ['ormGateway'],
            'original snapshot' => ['ormOriginal'],
            'loaded relations' => ['ormRelations'],
            'loaded relation flags' => ['ormLoadedRelations'],
        ];
    }

    #[DataProvider('nonColumnKeys')]
    public function testOnlyColumnFieldsCanBeAssigned(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key: ' . $key);
        FromArrayRecord::fromArray([$key => 'rejected']);
    }

    public function testUnknownKeyIsRejectedByDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key: missing');
        FromArrayRecord::fromArray(['name' => 'Alice', 'missing' => 'value']);
    }

    public function testIgnoreUnknownSkipsOnlyUnknownKeys(): void
    {
        $record = FromArrayRecord::fromArray([
            'name' => 'Alice',
            'missing' => new \stdClass(),
            'transient' => 'changed',
            'ormOriginal' => ['name' => 'forged'],
            'children' => [],
        ], true);

        self::assertSame('Alice', $record->name);
        self::assertSame('keep', $record->transient);
        self::assertSame([], $record->takeOriginal());
        self::assertFalse($record->hasGatewayBinding());
        self::assertFalse($record->relationLoaded('children'));
    }

    #[DataProvider('booleanValues')]
    public function testNonStringKeysAreAlwaysRejected(bool $ignoreUnknown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keys must be strings');
        FromArrayRecord::fromArray([0 => 'Alice'], $ignoreUnknown);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function duplicateFields(): array
    {
        return [
            'same string value' => [['name' => 'same', 'display_name' => 'same']],
            'same numeric meaning' => [['age' => 28, 'age_years' => '28']],
            'same null value' => [['remark' => null, 'remark_text' => null]],
        ];
    }

    #[DataProvider('duplicateFields')]
    public function testDuplicateFieldAliasesAreRejectedEvenWhenValuesMatch(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was provided more than once');
        FromArrayRecord::fromArray($data, true);
    }

    public function testKeyResolutionAndConflictsPrecedeCastingAndConstruction(): void
    {
        $failures = [
            [InvalidArgumentException::class, ['missing' => 1]],
            [InvalidArgumentException::class, ['name' => 'same', 'display_name' => 'same']],
            [InvalidArgumentException::class, ['age' => 'bad', 'name' => 'same', 'display_name' => 'same']],
            [CastException::class, ['age' => 'bad']],
        ];
        foreach ($failures as [$exception, $data]) {
            try {
                FromArrayRecord::fromArray($data);
                self::fail('Invalid input must not produce an entity');
            } catch (\Throwable $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }
        self::assertSame(0, FromArrayRecord::$constructorCount);

        FromArrayRecord::fromArray(['name' => 'valid']);
        self::assertSame(1, FromArrayRecord::$constructorCount);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        return [
            'integer' => ['age', 'not-a-number'],
            'JSON' => ['payload', '{bad json'],
            'enum' => ['status', 'deleted'],
            'decimal' => ['price', '1.2.3'],
            'castable' => ['amount', '-1'],
            'temporal text' => ['happenedAt', '2026/11/24'],
            'Unix time' => ['created_unix', '1.5'],
            'non-nullable' => ['name', null],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testConversionFailuresRemainCastExceptions(string $key, mixed $value): void
    {
        $this->expectException(CastException::class);
        FromArrayRecord::fromArray([$key => $value], true);
    }

    public function testToArrayRoundTripUsesColumnNamesAndStoredRepresentations(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::scope($gateway, function (): void {
            $record = FromArrayRecord::fromArray($this->scalarData());
            $exported = $record->toArray();

            self::assertArrayHasKey('display_name', $exported);
            self::assertArrayHasKey('age_years', $exported);
            self::assertArrayNotHasKey('name', $exported);
            self::assertArrayNotHasKey('transient', $exported);
            self::assertSame(1, $exported['active']);
            self::assertSame('published', $exported['status']);
            self::assertSame(2, $exported['kind']);
            self::assertSame(1299, $exported['amount']);
            self::assertSame('2026-11-24 02:07:53', $exported['happenedAt']);
            self::assertSame(1_764_000_000, $exported['created_unix']);
            self::assertSame(1_764_000_000_123, $exported['created_millis']);
            self::assertSame('2026-11-24', $exported['calendar_date']);
            self::assertSame('2026-11-24 02:07:53.123456', $exported['precise_at']);

            $copy = FromArrayRecord::fromArray($exported);
            self::assertSame($exported, $copy->toArray());
            self::assertTrue($copy->isNew());
            self::assertFalse($copy->hasGatewayBinding());
            self::assertSame([], $copy->takeOriginal());
        });
    }

    public function testCustomTemporalDisplayOutputIsNotAssumedToBeReversible(): void
    {
        $pdo = new PDO('sqlite::memory:');
        Session::scope(new EntityGateway(new PdoConnection($pdo)), function (): void {
            $record = FromArrayDisplayRecord::fromArray(['at' => '2026-11-24 02:07:53']);
            self::assertSame('2026', $record->toArray()['at']);

            $this->expectException(CastException::class);
            FromArrayDisplayRecord::fromArray($record->toArray());
        });
    }

    public function testEntityCanBePersistedThroughTheNormalSavePath(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE from_array_record (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            display_name TEXT NOT NULL,
            age_years INTEGER NOT NULL,
            score REAL NOT NULL,
            active INTEGER NOT NULL,
            payload TEXT NOT NULL,
            price TEXT NOT NULL,
            status TEXT NOT NULL,
            kind INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            happenedAt TEXT NOT NULL,
            created_unix INTEGER NOT NULL,
            created_millis INTEGER NOT NULL,
            calendar_date TEXT NOT NULL,
            precise_at TEXT NOT NULL,
            remark_text TEXT NULL,
            default_label TEXT NOT NULL,
            mode_text TEXT NOT NULL,
            required TEXT NOT NULL
        )');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        $events = [];
        $gateway->subscribe(static function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $record = FromArrayRecord::fromArray($this->scalarData());
        self::assertSame([], $events);
        self::assertSame([], $record->hooks);

        Session::scope($gateway, function () use ($record, &$events, $pdo, $gateway): void {
            self::assertNull($gateway->knownIdentity($record));
            $record->save();

            self::assertGreaterThan(0, $record->id);
            self::assertFalse($record->isNew());
            self::assertTrue($record->hasGatewayBinding());
            self::assertNotSame([], $record->takeOriginal());
            self::assertSame(['beforeSave', 'beforeInsert', 'afterSave', 'afterInsert'], $record->hooks);
            self::assertNotSame([], array_filter(
                $events,
                static fn (Event $event): bool => $event instanceof SqlEvent,
            ));

            $stored = $pdo->query('SELECT display_name, payload, price, status, kind, amount, created_millis FROM from_array_record')
                ->fetch(PDO::FETCH_ASSOC);
            self::assertSame('Alice', $stored['display_name']);
            self::assertSame('{"tags":["orm"],"nested":{"ok":true}}', $stored['payload']);
            self::assertSame('12345.67', $stored['price']);
            self::assertSame('published', $stored['status']);
            self::assertSame(2, $stored['kind']);
            self::assertSame(1299, $stored['amount']);
            self::assertSame(1_764_000_000_123, $stored['created_millis']);

            $gateway->clearCache(FromArrayRecord::class);
            $found = FromArrayRecord::find(['id' => $record->id]);
            self::assertNotNull($found);
            self::assertNotSame($record, $found);
            self::assertSame(28, $found->age);
            self::assertTrue($found->active);
            self::assertSame(FromArrayStatus::Published, $found->status);
            self::assertSame(1299, $found->amount->cents);
            self::assertSame('2026-11-24 02:07:53.123456', $found->preciseAt->format('Y-m-d H:i:s.u'));
        });
    }

    /** @return array<string, array{bool}> */
    public static function booleanValues(): array
    {
        return ['false' => [false], 'true' => [true]];
    }

    /** @return array<string, mixed> */
    private function scalarData(): array
    {
        return [
            'name' => 'Alice',
            'age_years' => '28',
            'score' => '9.5',
            'active' => '1',
            'payload' => '{"tags":["orm"],"nested":{"ok":true}}',
            'price' => '12345.67',
            'status' => 'published',
            'kind' => '2',
            'amount' => '1299',
            'happenedAt' => '2026-11-24 02:07:53',
            'created_unix' => '1764000000',
            'created_millis' => '1764000000123',
            'calendar_date' => '2026-11-24',
            'precise_at' => '2026-11-24 02:07:53.123456',
            'remark_text' => null,
            'default_label' => 'changed',
            'required' => 'present',
        ];
    }
}

final class FromArrayRecord extends Entity
{
    public static int $constructorCount = 0;

    /** @var list<string> */
    public array $hooks = [];

    public string $transient = 'keep';

    public int $constructorCalls = 0;

    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column(name: 'display_name')]
    public string $name;

    #[Column(name: 'age_years')]
    public int $age;

    #[Column]
    public float $score;

    #[Column]
    public bool $active;

    #[Column]
    public array $payload;

    #[Column]
    public BcDecimal $price;

    #[Column]
    public FromArrayStatus $status;

    #[Column]
    public FromArrayKind $kind;

    #[Column]
    public FromArrayAmount $amount;

    #[Column]
    #[Temporal]
    public DateTimeImmutable $happenedAt;

    #[Column(name: 'created_unix')]
    #[Temporal(Temporal::UNIX)]
    public DateTimeImmutable $createdAt;

    #[Column(name: 'created_millis')]
    #[Temporal(Temporal::UNIX_MS)]
    public DateTimeImmutable $createdMillis;

    #[Column(name: 'calendar_date')]
    #[Temporal(Temporal::DATE)]
    public DateTimeImmutable $calendarDate;

    #[Column(name: 'precise_at')]
    #[Temporal(Temporal::TEXT_US)]
    public DateTimeImmutable $preciseAt;

    #[Column(name: 'remark_text')]
    public ?string $remark = null;

    #[Column(name: 'default_label')]
    public string $label = 'declared';

    #[Column(name: 'mode_text')]
    public string $mode = 'default';

    #[Column]
    public string $required;

    public function __construct()
    {
        self::$constructorCount++;
        $this->constructorCalls++;
        $this->label = 'constructed';
    }

    public static function table(): string
    {
        return 'from_array_record';
    }

    #[HasMany(target: self::class, foreignKey: ['id'])]
    public function children(): HasManyQuery
    {
        return $this->makeRelation();
    }

    public function beforeSave(): void { $this->hooks[] = 'beforeSave'; }
    public function afterSave(): void { $this->hooks[] = 'afterSave'; }
    public function beforeInsert(): void { $this->hooks[] = 'beforeInsert'; }
    public function afterInsert(): void { $this->hooks[] = 'afterInsert'; }
    public function beforeUpdate(): void { $this->hooks[] = 'beforeUpdate'; }
    public function afterUpdate(): void { $this->hooks[] = 'afterUpdate'; }
    public function beforeDelete(): void { $this->hooks[] = 'beforeDelete'; }
    public function afterDelete(): void { $this->hooks[] = 'afterDelete'; }
}

final class FromArrayDisplayRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id = 1;

    #[Column]
    #[Temporal(displayFormat: 'Y')]
    public DateTimeImmutable $at;

    public static function table(): string
    {
        return 'unused';
    }
}
