<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Gateway\SqlExecutor;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\SystemClock;
use Pixiaoka\Orm\Query\RenderContext;
use Pixiaoka\Orm\Query\SqlCompiler;
use Pixiaoka\Orm\Sql\SqlLexer;

require_once __DIR__ . '/SqlExecutorTest.php';

final class SqlDialectLexerTest extends TestCase
{
    public function testPostgresJsonOperatorsAndNestedCommentsPreserveAllBoundParameters(): void
    {
        $sql = <<<'SQL'
SELECT data #> :path::text[] AS inner_json,
       data #>> :text_path::text[] AS inner_text,
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
        $repeated = new RenderContext();
        self::assertSame($rendered, $repeated->mergeRaw($sql, ['path' => '{a}', 'text_path' => '{b}', 'id' => 7]));
    }

    #[DataProvider('nonNestedCommentDrivers')]
    public function testOnlyPostgresTreatsBlockCommentsAsNested(string $driver): void
    {
        $sql = '/* a /* b */ SELECT :id';
        self::assertSame(['id'], SqlLexer::placeholders($sql, $driver));
        self::assertSame([], SqlLexer::placeholders($sql, 'pgsql'));
    }

    /** @return array<string, array{string}> */
    public static function nonNestedCommentDrivers(): array
    {
        return ['mysql' => ['mysql'], 'sqlite' => ['sqlite']];
    }

    #[DataProvider('nonNestedCommentDrivers')]
    public function testOnlyPostgresRecognizesDollarQuotes(string $driver): void
    {
        $sql = 'SELECT $a$ AS marker, :id';
        self::assertSame(['id'], SqlLexer::placeholders($sql, $driver));
        self::assertSame([], SqlLexer::placeholders('SELECT $a$:ignored$a$', 'pgsql'));
    }

    public function testPostgresDollarQuoteNormalizationRespectsIdentifiersAndTokenBoundaries(): void
    {
        self::assertSame(
            "SELECT \"col\\\" AS identifier, E'a:b'",
            SqlLexer::normalizePostgresDollarQuotes('SELECT "col\\" AS identifier, $$a:b$$'),
        );
        self::assertSame("SELECT E'a:b'", SqlLexer::normalizePostgresDollarQuotes('SELECT $$a:b$$'));
        self::assertSame("SELECT E'a:b'", SqlLexer::normalizePostgresDollarQuotes('SELECT$$a:b$$'));
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
SELECT E'\':ignored' AS escaped, $$:dollar$$, $tag$:dollar$tag$, :actual::int
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
        $sql = <<<'SQL'
SELECT $tag$:not_a_parameter$tag$ AS body,
       :actual::int AS value,
       /* outer /* :not_a_parameter */ :not_a_parameter */
SQL;
        $row = $connection->queryOne(
            $sql . " E'" . chr(92) . "':not_a_parameter' AS escaped",
            ['actual' => 42],
        );
        self::assertNotNull($row);
        self::assertSame(':not_a_parameter', $row['body']);
        self::assertSame(42, (int) $row['value']);
        self::assertSame("':not_a_parameter", $row['escaped']);
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
WHERE :id::int = :id::int
SQL, ['path' => '{a}', 'text_path' => '{a,name}', 'id' => 7]);
        self::assertNotNull($row);
        self::assertSame(['name' => 'Pixiaoka'], json_decode((string) $row['inner_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Pixiaoka', $row['inner_text']);
        self::assertSame('root', $row['literal_text']);
    }

    public function testRealMysqlHashCommentAndBackslashPreserveBoundValues(): void
    {
        $connection = $this->liveConnection('mysql');
        $sql = "SELECT '" . chr(92) . "'escaped' AS escaped_result, :first AS first_result, "
            . ":second AS second_result # :not_a_parameter\n";
        $row = $connection->queryOne($sql, ['first' => "O'Reilly\\books", 'second' => 7]);
        self::assertNotNull($row);
        self::assertSame("'escaped", $row['escaped_result']);
        self::assertSame("O'Reilly\\books", $row['first_result']);
        self::assertSame(7, (int) $row['second_result']);
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
