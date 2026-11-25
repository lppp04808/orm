<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Gateway\SqlExecutor;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\SystemClock;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SqlDialectLexerTest.php';

final class NumericAndSqlDialectBoundaryTest extends TestCase
{
    private Caster $caster;
    /** @var array<string, \Pixiaoka\Orm\Metadata\FieldMeta> */
    private array $meta = [];

    protected function setUp(): void
    {
        $this->caster = new Caster();
    }

    public function testIntegerBoundsAndDecimalPrecisionRoundTrip(): void
    {
        $int = $this->field('n_int');
        self::assertSame(PHP_INT_MIN, $this->caster->toPhp(TypedRow::class, $int, (string) PHP_INT_MIN));
        self::assertSame(PHP_INT_MAX, $this->caster->toPhp(TypedRow::class, $int, (string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, $this->caster->toDbNormalized(TypedRow::class, $int, (string) PHP_INT_MAX));

        $decimal = $this->field('n_dec');
        $value = BcDecimal::fromString('12345678901234567890.123456789');
        self::assertSame('12345678901234567890.123456789', $this->caster->toDb(TypedRow::class, $decimal, $value));
    }

    #[DataProvider('overflowingIntegers')]
    public function testIntegerOverflowIsRejectedInBothDirections(string $value): void
    {
        $int = $this->field('n_int');
        foreach (['toPhp', 'toDbNormalized'] as $direction) {
            try {
                $this->caster->{$direction}(TypedRow::class, $int, $value);
                self::fail($direction . ' accepted integer overflow ' . $value);
            } catch (CastException $e) {
                self::assertStringContainsString('exceeds', $e->getMessage());
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function overflowingIntegers(): array
    {
        $max = (string) PHP_INT_MAX;
        $min = ltrim((string) PHP_INT_MIN, '-');
        $aboveMax = substr($max, 0, -1) . (string) ((int) $max[strlen($max) - 1] + 1);
        $belowMin = '-' . substr($min, 0, -1) . (string) ((int) $min[strlen($min) - 1] + 1);

        return [
            'above max' => [$aboveMax],
            'below min' => [$belowMin],
            'enormous positive' => [str_repeat('9', 100)],
            'enormous negative' => ['-' . str_repeat('9', 100)],
            'zero-padded overflow' => [str_repeat('0', 100) . $aboveMax],
        ];
    }

    #[DataProvider('nonFiniteFloats')]
    public function testNonFiniteFloatInputIsRejected(mixed $value): void
    {
        $float = $this->field('n_float');
        try {
            $this->caster->toPhp(TypedRow::class, $float, $value);
            self::fail('Database input accepted a non-finite float');
        } catch (CastException $e) {
            self::assertStringContainsString('finite', $e->getMessage());
        }
    }

    /** @return array<string, array{mixed}> */
    public static function nonFiniteFloats(): array
    {
        return [
            'numeric infinity' => ['1e999'],
            'negative numeric infinity' => ['-1e999'],
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('nonFiniteOutput')]
    public function testNonFiniteFloatOutputIsRejected(mixed $value): void
    {
        $float = $this->field('n_float');
        foreach (['toDb', 'toDbNormalized'] as $method) {
            try {
                $this->caster->{$method}(TypedRow::class, $float, $value);
                self::fail($method . ' accepted non-finite output');
            } catch (CastException $e) {
                self::assertStringContainsString('finite', $e->getMessage());
            }
        }
    }

    /** @return array<string, array{mixed}> */
    public static function nonFiniteOutput(): array
    {
        return ['infinity' => [INF], 'negative infinity' => [-INF], 'nan' => [NAN]];
    }

    public function testOverflowingPrimaryKeyCannotBeSilentlyConvertedToMaximum(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE typed_row (id INTEGER PRIMARY KEY AUTOINCREMENT, n_int INTEGER NOT NULL, n_float REAL NOT NULL, n_str TEXT NOT NULL, n_bool INTEGER NOT NULL, n_arr TEXT NOT NULL, n_dec TEXT NOT NULL, n_enum INTEGER NOT NULL, n_time TEXT NOT NULL, n_unix INTEGER NOT NULL, opt TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0)');
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::use($gateway);
        try {
            TypedRow::query()->where('id', '9223372036854775808')->first();
            self::fail('Primary-key condition cannot overflow');
        } catch (CastException $e) {
            self::assertStringContainsString('exceeds', $e->getMessage());
        } finally {
            Session::discard();
        }
    }

    #[DataProvider('nonFiniteOutput')]
    public function testRawFloatBindingsRejectNonFiniteValues(float $value): void
    {
        $executor = new SqlExecutor(new SqlExecutorConnection(), new EventDispatcher(), new SystemClock());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');
        $executor->selectRaw('SELECT :value', ['value' => $value]);
    }

    private function field(string $name): \Pixiaoka\Orm\Metadata\FieldMeta
    {
        return $this->meta[$name] ??= (new \Pixiaoka\Orm\Metadata\MetadataReader())->read(TypedRow::class)->field($name);
    }
}
