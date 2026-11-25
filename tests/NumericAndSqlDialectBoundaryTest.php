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
use Pixiaoka\Orm\Query\RenderContext;
use Pixiaoka\Orm\Query\SqlCompiler;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

final class SqlDialectLexerTest extends TestCase
{
    public function testPostgresJsonOperatorsAndNestedCommentsPreserveAllBoundParameters(): void
    {
        $sql = <<<'SQL'
SELECT data #> :path AS inner_json,
       data #>> :text_path AS inner_text,
       value::jsonb #>> '{name}' AS literal_text
FROM payload
/* outer :unused /* nested :also_unused */ still outer :unused */
WHERE id = :id AND :id = id
SQL;
        $context = new RenderContext();
        $rendered = $context->mergeRaw($sql, ['path' => '{a}', 'text_path' => '{b}', 'id' => 7]);
        self::assertSame(['q0' => '{a}', 'q1' => '{b}', 'q2' => 7, 'q3' => 7], $context->params());
        self::assertSame(str_replace([':path', ':text_path', ':id'], [':q0', ':q1', ':q2'], substr_replace($sql, ':q3', strrpos($sql, ':id'), 3)), $rendered);
        self::assertSame(
            str_replace([':path', ':text_path', ':id'], ["'{a}'", "'{b}'", '7'], substr_replace($sql, '7', strrpos($sql, ':id'), 3)),
            SqlCompiler::interpolate($sql, ['path' => '{a}', 'text_path' => '{b}', 'id' => 7], 'pgsql'),
        );
        self::assertSame($rendered, $context->mergeRaw($sql, ['path' => '{a}', 'text_path' => '{b}', 'id' => 7]));
    }

    public function testMysqlHashCommentRemainsValidButPostgresHashOperatorIsNotAComment(): void
    {
        $mysql = "SELECT 1 # :ignored\n, :actual";
        $context = new RenderContext();
        self::assertSame("SELECT 1 # :ignored\n, :q0", $context->mergeRaw($mysql, ['actual' => 9], 'mysql'));
        self::assertSame(['q0' => 9], $context->params());
        self::assertSame("SELECT 1 # :ignored\n, 9", SqlCompiler::interpolate($mysql, ['actual' => 9], 'mysql'));
    }

    public function testPostgresEscapeStringAndMysqlBackslashDoNotExposePseudoParameters(): void
    {
        $postgres = <<<'SQL'
SELECT E'\\':ignored' AS escaped, $$:dollar$$, $tag$:dollar$tag$, :actual::int
SQL;
        $context = new RenderContext();
        self::assertSame(str_replace(':actual', ':q0', $postgres), $context->mergeRaw($postgres, ['actual' => 7], 'pgsql'));
        self::assertSame(str_replace(':actual', '7', $postgres), SqlCompiler::interpolate($postgres, ['actual' => 7], 'pgsql'));

        $mysql = "SELECT '\\':ignored' AS escaped, :actual";
        self::assertSame(str_replace(':actual', ':q0', $mysql), (new RenderContext())->mergeRaw($mysql, ['actual' => 8], 'mysql'));
        self::assertSame(str_replace(':actual', '8', $mysql), SqlCompiler::interpolate($mysql, ['actual' => 8], 'mysql'));
    }

    public function testRawParameterValidationUsesTheSameDialectRules(): void
    {
        $sql = 'SELECT payload #>> :path AS value /* outer :ignored /* nested */ :ignored */ WHERE id = :id';
        $connection = new SqlExecutorConnection();
        $executor = new SqlExecutor($connection, new EventDispatcher(), new SystemClock());
        self::assertSame([['path' => '{name}', 'id' => 7]], $executor->selectRaw($sql, ['path' => '{name}', 'id' => 7]));
        self::assertSame([$sql], $connection->queries);
    }

    public function testPostgresDollarQuotedParametersExecuteAfterNormalization(): void
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_pgsql is not installed');
        }
        $dsn = getenv('PIXIAOKA_ORM_LIVE_PGSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('PIXIAOKA_ORM_LIVE_PGSQL_DSN is not configured');
        }
        $pdo = new PDO($dsn, getenv('PIXIAOKA_ORM_LIVE_PGSQL_USER') ?: null, getenv('PIXIAOKA_ORM_LIVE_PGSQL_PASSWORD') ?: null);
        $connection = new PdoConnection($pdo);
        $row = $connection->queryOne(<<<'SQL'
SELECT $tag$:not_a_parameter$tag$ AS body,
       :actual::int AS value,
       /* outer /* :not_a_parameter */ :not_a_parameter */ E'\\':not_a_parameter' AS escaped
SQL, ['actual' => 42]);
        self::assertNotNull($row);
        self::assertSame(':not_a_parameter', $row['body']);
        self::assertSame(42, (int) $row['value']);
        self::assertSame(":not_a_parameter", $row['escaped']);
    }

    public function testRealPostgresJsonOperatorsAndCommentsBindEveryOccurrence(): void
    {
        $connection = $this->liveConnection('pgsql');
        $row = $connection->queryOne(<<<'SQL'
SELECT data #> :path AS inner_json,
       data #>> :text_path AS inner_text,
       data::jsonb #>> '{name}' AS literal_text
FROM (SELECT '{"a":{"name":"Pixiaoka"},"name":"root"}'::jsonb AS data) AS payload
/* outer :unused /* nested :also_unused */ still outer :unused */
WHERE :id = :id
SQL, ['path' => '{a}', 'text_path' => '{a,name}', 'id' => 7]);
        self::assertNotNull($row);
        self::assertSame(['name' => 'Pixiaoka'], json_decode((string) $row['inner_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Pixiaoka', $row['inner_text']);
        self::assertSame('root', $row['literal_text']);
    }

    public function testRealMysqlHashCommentAndBackslashPreserveBoundValues(): void
    {
        $connection = $this->liveConnection('mysql');
        $row = $connection->queryOne(<<<'SQL'
SELECT '\\':not_a_parameter' AS escaped, :first AS first_value, :second AS second_value # :not_a_parameter
SQL, ['first' => "O'Reilly\\books", 'second' => 7]);
        self::assertNotNull($row);
        self::assertSame(':not_a_parameter', $row['escaped']);
        self::assertSame("O'Reilly\\books", $row['first_value']);
        self::assertSame(7, (int) $row['second_value']);
    }

    private function liveConnection(string $driver): PdoConnection
    {
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_' . $driver . ' is not installed');
        }
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped($prefix . '_DSN is not configured');
        }

        return new PdoConnection(new PDO(
            $dsn,
            getenv($prefix . '_USER') ?: null,
            getenv($prefix . '_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        ));
    }
}
