<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use DateTimeZone;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Exception\OrmException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 本文件独立使用的枚举（与 OrmKernelTest 的 SampleStatus 解耦） */
enum MetaSampleStatus: int
{
    case Off = 0;
    case On = 1;
    case Minimum = PHP_INT_MIN;
    case Maximum = PHP_INT_MAX;
}

/**
 * Caster 类型边界 + MetadataReader 校验规则的专项测试。
 * 覆盖：各类型进出转换、失败路径、注解校验（空字段/无主键/混用/可空主键/非法类型/时间注解）。
 */
final class OrmCastMetadataTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;
    private Caster $caster;
    private MetadataReader $reader;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE typed_row (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            n_int INTEGER NOT NULL,
            n_float REAL NOT NULL,
            n_str TEXT NOT NULL,
            n_bool INTEGER NOT NULL,
            n_arr TEXT NOT NULL,
            n_dec TEXT NOT NULL,
            n_enum INTEGER NOT NULL,
            n_time TEXT NOT NULL,
            n_unix INTEGER NOT NULL,
            opt TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
        $this->caster = new Caster();
        $this->reader = new MetadataReader();
    }

    // ---- 类型化模型：一个模型覆盖全部支持类型 ----

    public function testTypedRoundTripAllSupportedTypes(): void
    {
        $row = new TypedRow();
        $row->n_int = -42;
        $row->n_float = 3.14;
        $row->n_str = '你好 "world"';
        $row->n_bool = true;
        $row->n_arr = ['a' => 1, 'b' => [2, 3]];
        $row->n_dec = BcDecimal::fromString('12345.67');
        $row->n_enum = MetaSampleStatus::On;
        $row->n_time = new DateTimeImmutable('2026-11-14 12:34:56', new DateTimeZone('UTC'));
        $row->n_unix = new DateTimeImmutable('@1770000000');
        $row->save();

        $found = TypedRow::query()->where('id', $row->id)->first();

        self::assertNotNull($found);
        self::assertSame(-42, $found->n_int);
        self::assertSame(3.14, $found->n_float);
        self::assertSame('你好 "world"', $found->n_str);
        self::assertTrue($found->n_bool);
        self::assertSame(['a' => 1, 'b' => [2, 3]], $found->n_arr);
        self::assertSame('12345.67', (string) $found->n_dec);
        self::assertSame(MetaSampleStatus::On, $found->n_enum);
        // 文本时间：UTC Y-m-d H:i:s
        self::assertSame('2026-11-14 12:34:56', $found->n_time->format('Y-m-d H:i:s'));
        // Unix 时间：秒级往返
        self::assertSame(1770000000, $found->n_unix->getTimestamp());
    }

    public function testNullableColumnRoundTripNull(): void
    {
        $row = new TypedRow();
        $row->n_int = 1;
        $row->n_float = 1.5;
        $row->n_str = 'x';
        $row->n_bool = false;
        $row->n_arr = [];
        $row->n_dec = BcDecimal::fromString('0');
        $row->n_enum = MetaSampleStatus::Off;
        $row->n_time = new DateTimeImmutable('2026-01-01 00:00:00');
        $row->n_unix = new DateTimeImmutable('@100');
        $row->save();

        $found = TypedRow::query()->where('id', $row->id)->first();
        self::assertNotNull($found);
        self::assertNull($found->opt);
        self::assertNull($found->toArray()['opt']);
    }

    public function testNullableColumnRoundTripValue(): void
    {
        $row = $this->makeRow();
        $row->opt = 'some value';
        $row->save();

        $found = TypedRow::query()->where('id', $row->id)->first();
        self::assertNotNull($found);
        self::assertSame('some value', $found->opt);
    }

    // ---- Caster 失败路径 ----

    public function testCasterRejectsNonNumericIntText(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_int');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, 'abc');
    }

    public function testCasterRejectsNullForNonNullField(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_str');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, null);
    }

    public function testCasterRejectsFloatTextAsInt(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_int');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, '3.14');
    }

    public function testCasterRejectsInvalidJson(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_arr');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, '{not json');
    }

    public function testCasterRejectsNonArrayJsonScalar(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_arr');
        $this->expectException(CastException::class);
        // JSON 合法但不是数组
        $this->caster->toPhp(TypedRow::class, $field, '"a string"');
    }

    public function testCasterRejectsInvalidDecimalText(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_dec');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, '12.34.56');
    }

    public function testCasterRejectsUnknownEnumValue(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_enum');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, 99);
    }

    public function testCasterAcceptsNumericStringEnumValue(): void
    {
        // SQLite 常返回字符串 '1'，枚举要能还原
        $field = $this->fieldOf(TypedRow::class, 'n_enum');
        $enum = $this->caster->toPhp(TypedRow::class, $field, '1');
        self::assertSame(MetaSampleStatus::On, $enum);
    }

    public function testCasterAcceptsSignedIntegerEnumBoundaryStrings(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_enum');
        self::assertSame(MetaSampleStatus::Minimum, $this->caster->toPhp(TypedRow::class, $field, (string) PHP_INT_MIN));
        self::assertSame(MetaSampleStatus::Maximum, $this->caster->toPhp(TypedRow::class, $field, (string) PHP_INT_MAX));
    }

    public function testCasterRejectsIntegerEnumOverflow(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_enum');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, PHP_INT_MAX . '0');
    }

    public function testMalformedTemporalValueWithNullByteThrowsCastException(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_time');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, "2026-01-01 00:00:00\0");
    }

    public function testCasterRejectsMalformedTimeText(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_time');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, '2026/11/14 10:00');
    }

    public function testCasterRejectsNonStringTimeInput(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_time');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, 12345);
    }

    public function testCasterToDbRejectsWrongRuntimeType(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_bool');
        $this->expectException(CastException::class);
        $this->caster->toDb(TypedRow::class, $field, 'yes');
    }

    #[DataProvider('invalidDatabaseOutput')]
    public function testCasterRejectsInvalidDatabaseOutput(string $property, mixed $value, string $message): void
    {
        $field = $this->fieldOf(TypedRow::class, $property);
        $this->expectException(CastException::class);
        $this->expectExceptionMessage($message);
        $this->caster->toDb(TypedRow::class, $field, $value);
    }

    public static function invalidDatabaseOutput(): array
    {
        return [
            'required null' => ['n_str', null, 'Cannot write null to a non-nullable field'],
            'array runtime type' => ['n_arr', '[]', 'Expected an array value'],
            'decimal runtime type' => ['n_dec', '1.25', 'Expected a Decimal value'],
            'time runtime type' => ['n_time', '2026-01-01 00:00:00', 'Expected a DateTimeImmutable value'],
            'enum runtime type' => ['n_enum', 1, 'Expected a BackedEnum value'],
            'integer runtime type' => ['n_int', '42', 'Expected an int value'],
            'float runtime type' => ['n_float', '1.25', 'Expected a numeric value'],
            'string runtime type' => ['n_str', 42, 'Expected a string value'],
        ];
    }

    #[DataProvider('invalidDatabaseInput')]
    public function testCasterRejectsInvalidDatabaseInput(string $property, mixed $value, string $message): void
    {
        $field = $this->fieldOf(TypedRow::class, $property);
        $this->expectException(CastException::class);
        $this->expectExceptionMessage($message);
        $this->caster->toPhp(TypedRow::class, $field, $value);
    }

    public static function invalidDatabaseInput(): array
    {
        return [
            'float text' => ['n_float', 'not numeric', 'Cannot parse value as float'],
            'string object' => ['n_str', new \stdClass(), 'Cannot parse value as string'],
            'bool text' => ['n_bool', 'false', 'bool accepts only 0/1/true/false'],
            'bool integer' => ['n_bool', 2, 'bool accepts only 0/1/true/false'],
            'non-json scalar' => ['n_arr', 42, 'array accepts only JSON text or an array'],
        ];
    }

    public function testCasterConvertsNumericDriverValuesIntoStringProperties(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_str');
        self::assertSame('42', $this->caster->toPhp(TypedRow::class, $field, 42));
        self::assertSame('1.25', $this->caster->toPhp(TypedRow::class, $field, 1.25));
    }

    public function testCasterToDbEncodesArrayAsJson(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_arr');
        $out = $this->caster->toDb(TypedRow::class, $field, ['k' => '中文']);
        self::assertIsString($out);
        self::assertSame('{"k":"中文"}', $out);
    }

    public function testCasterToDbBoolBecomesOneOrZero(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_bool');
        self::assertSame(1, $this->caster->toDb(TypedRow::class, $field, true));
        self::assertSame(0, $this->caster->toDb(TypedRow::class, $field, false));
    }

    public function testCasterToDbTimeConvertsToUtcText(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_time');
        $local = new DateTimeImmutable('2026-11-14 20:00:00', new DateTimeZone('Asia/Shanghai'));
        $out = $this->caster->toDb(TypedRow::class, $field, $local);
        // 东八区 20:00 == UTC 12:00
        self::assertSame('2026-11-14 12:00:00', $out);
    }

    public function testCasterToDbUnixTimeConvertsToTimestamp(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_unix');
        $time = new DateTimeImmutable('@1770000000');
        self::assertSame(1770000000, $this->caster->toDb(TypedRow::class, $field, $time));
    }

    // ---- MetadataReader 校验规则 ----

    public function testMetadataRejectsEntityWithoutColumns(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(NoColumnEntity::class);
    }

    public function testMetadataRejectsMissingPrimaryKey(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(NoPrimaryKeyEntity::class);
    }

    public function testMetadataRejectsNullablePrimaryKey(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(NullableKeyEntity::class);
    }

    public function testMetadataRejectsUnsupportedPropertyType(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(UnsupportedTypeEntity::class);
    }

    public function testMetadataRejectsTemporalOnNonDateTime(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(TemporalOnIntEntity::class);
    }

    public function testMetadataRejectsNonEntityClass(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(\stdClass::class);
    }

    public function testMetadataRejectsTwoGeneratedKeys(): void
    {
        $this->expectException(MetadataException::class);
        $this->reader->read(TwoGeneratedKeysEntity::class);
    }

    public function testMetadataColumnOverrideChangesColumnName(): void
    {
        $renamed = $this->reader->read(RenamedColumnEntity::class);
        self::assertSame('real_name', $renamed->field('id')->column);
        self::assertSame('the_value', $renamed->field('prop')->column);
    }

    public function testMetadataPrimaryKeysSortedByOrder(): void
    {
        $meta = $this->reader->read(CompositeOrderEntity::class);
        $columns = array_map(static fn ($f) => $f->column, $meta->primaryKey);
        // 声明顺序 k1, k2, k3，order 指定 k3=1, k1=2, k2=3
        self::assertSame(['k3', 'k1', 'k2'], $columns);
    }

    public function testMetadataResultIsCachedPerClass(): void
    {
        $a = $this->reader->read(TypedRow::class);
        $b = $this->reader->read(TypedRow::class);
        self::assertSame($a, $b);
    }

    public function testBcDecimalRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BcDecimal::fromString('1e5');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecimalEndings')]
    public function testDecimalRejectsTrailingControlCharacters(string $value): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_dec');
        $this->expectException(CastException::class);
        $this->caster->toPhp(TypedRow::class, $field, $value);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimalEndings(): array
    {
        return ['line feed' => ["12.3\n"], 'carriage return' => ["12.3\r"], 'trailing text' => ['12.3x']];
    }

    public function testBcDecimalRejectsTrailingNewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BcDecimal::fromString("1\n");
    }

    public function testCastExceptionCarriesModelAndProperty(): void
    {
        $field = $this->fieldOf(TypedRow::class, 'n_int');
        try {
            $this->caster->toPhp(TypedRow::class, $field, 'xyz');
            self::fail('应抛 CastException');
        } catch (CastException $e) {
            self::assertSame(TypedRow::class, $e->model);
            self::assertSame('n_int', $e->property);
            self::assertInstanceOf(OrmException::class, $e);
        }
    }

    // ---- 辅助 ----

    private function makeRow(): TypedRow
    {
        $row = new TypedRow();
        $row->n_int = 1;
        $row->n_float = 1.5;
        $row->n_str = 'x';
        $row->n_bool = false;
        $row->n_arr = [];
        $row->n_dec = BcDecimal::fromString('0');
        $row->n_enum = MetaSampleStatus::Off;
        $row->n_time = new DateTimeImmutable('2026-01-01 00:00:00');
        $row->n_unix = new DateTimeImmutable('@100');

        return $row;
    }

    private function fieldOf(string $class, string $property): \Pixiaoka\Orm\Metadata\FieldMeta
    {
        return $this->reader->read($class)->field($property);
    }
}

// ---- 测试模型 ----

final class TypedRow extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public int $n_int;

    #[Column]
    public float $n_float;

    #[Column]
    public string $n_str;

    #[Column]
    public bool $n_bool;

    #[Column]
    public array $n_arr;

    #[Column]
    public BcDecimal $n_dec;

    #[Column]
    public MetaSampleStatus $n_enum;

    #[Column]
    #[Temporal]
    public DateTimeImmutable $n_time;

    #[Column]
    #[Temporal(Temporal::UNIX)]
    public DateTimeImmutable $n_unix;

    #[Column]
    public ?string $opt = null;

    #[Column]
    public int $created_at;

    #[Column]
    public int $updated_at = 0;

    public static function table(): string
    {
        return 'typed_row';
    }
}

final class NoColumnEntity extends Entity
{
    public int $notAColumn = 0;

    public static function table(): string
    {
        return 'no_column';
    }
}

final class NoPrimaryKeyEntity extends Entity
{
    #[Column]
    public int $a = 0;

    public static function table(): string
    {
        return 'no_pk';
    }
}

final class NullableKeyEntity extends Entity
{
    #[Column]
    #[PrimaryKey]
    public ?int $id = null;

    #[Column]
    public int $a = 0;

    public static function table(): string
    {
        return 'nullable_pk';
    }
}

final class UnsupportedTypeEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public mixed $anything = null;

    public static function table(): string
    {
        return 'unsupported_type';
    }
}

final class TemporalOnIntEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    #[Temporal(Temporal::UNIX)]
    public int $when = 0;

    public static function table(): string
    {
        return 'temporal_on_int';
    }
}

final class TwoGeneratedKeysEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id2;

    public static function table(): string
    {
        return 'two_generated';
    }
}

final class RenamedColumnEntity extends Entity
{
    #[Column(name: 'real_name')]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column(name: 'the_value')]
    public string $prop = '';

    public static function table(): string
    {
        return 'renamed_column';
    }
}

final class CompositeOrderEntity extends Entity
{
    #[Column]
    #[PrimaryKey(order: 2)]
    public int $k1 = 0;

    #[Column]
    #[PrimaryKey(order: 3)]
    public int $k2 = 0;

    #[Column]
    #[PrimaryKey(order: 1)]
    public int $k3 = 0;

    public static function table(): string
    {
        return 'composite_order';
    }
}
