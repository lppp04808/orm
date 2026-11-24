<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\DriverAwareConnection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\SqlCompiler;
use Pixiaoka\Orm\Query\SqlPreview;
use Pixiaoka\Orm\Session;

final class SqlPreviewTest extends TestCase
{
    private PDO $pdo;
    private PreviewRecordingConnection $connection;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE preview_record (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            amount REAL NOT NULL,
            quantity INTEGER NOT NULL,
            active INTEGER NOT NULL,
            note TEXT NULL,
            deleted_at TEXT NULL
        )');
        $this->pdo->exec("INSERT INTO preview_record VALUES (7, 'before', 1.5, 2, 0, 'original', NULL)");
        $this->connection = new PreviewRecordingConnection('sqlite');
        $this->gateway = new EntityGateway($this->connection);
        Session::use($this->gateway);
        $this->gateway->subscribe(static function (Event $event): void {
            self::fail('Preview must not dispatch ' . $event::class);
        });
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testSelectAndCountPreviewsInterpolateWithoutExecution(): void
    {
        $query = PreviewRecord::query()->where('id', 7);
        $preview = $query->preview();
        self::assertInstanceOf(SqlPreview::class, $preview);

        $expected = 'SELECT "id", "name", "amount", "quantity", "active", "note", "deleted_at" '
            . 'FROM "preview_record" WHERE "deleted_at" IS NULL AND "id" = 7';
        self::assertSame($expected, $preview->get());
        self::assertSame($expected . ' LIMIT 1', $preview->first());
        self::assertSame($expected, $preview->get(), 'first() cannot poison a reusable preview');
        self::assertSame($expected, $preview->rows());
        self::assertSame($expected . ' LIMIT 1', $preview->row());

        $scalar = PreviewRecord::query()->select('name')->where('id', 7)->preview()->value();
        self::assertSame('SELECT "name" FROM "preview_record" WHERE "deleted_at" IS NULL AND "id" = 7 LIMIT 1', $scalar);
        self::assertSame(
            'SELECT COUNT("name") FROM "preview_record" WHERE "deleted_at" IS NULL AND "id" = 7',
            $query->preview()->count('name'),
        );
        self::assertSame([], $this->connection->operations);
        self::assertSame('before', $this->storedName());
    }

    public function testEagerLoadingAndTerminalValidationMatchBuilder(): void
    {
        $sql = PreviewRecord::query()->with('children')->where('id', 7)->preview()->get();
        self::assertStringStartsWith('SELECT "id", "name"', $sql);
        self::assertStringContainsString('FROM "preview_record" WHERE "deleted_at" IS NULL AND "id" = 7', $sql);
        self::assertStringNotContainsString('preview_child', $sql);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rows() does not support with() eager loading');
        PreviewRecord::query()->with('children')->preview()->rows();
    }

    public function testUpdateDeltaAndDeletePreviews(): void
    {
        $query = PreviewRecord::query()->where('id', 7);
        self::assertSame(
            'UPDATE "preview_record" SET "name" = \'after\' WHERE "deleted_at" IS NULL AND "id" = 7',
            $query->preview()->update(['name' => 'after']),
        );
        self::assertSame(
            'UPDATE "preview_record" SET "amount" = "amount" + 1.25, "name" = \'delta\' '
            . 'WHERE "deleted_at" IS NULL AND "id" = 7',
            $query->preview()->increment('amount', 1.25, ['name' => 'delta']),
        );
        self::assertSame(
            'UPDATE "preview_record" SET "amount" = "amount" - 0.5 WHERE "deleted_at" IS NULL AND "id" = 7',
            $query->preview()->decrement('amount', 0.5),
        );
        self::assertSame(
            'DELETE FROM "preview_plain_record" WHERE "id" = 7',
            PreviewPlainRecord::query()->where('id', 7)->preview()->delete(),
        );
        self::assertSame([], $this->connection->operations);
        self::assertSame('before', $this->storedName());
    }

    public function testSoftDeleteRestoreAndScopes(): void
    {
        $before = time();
        $sql = PreviewRecord::query()->where('id', 7)->preview()->delete();
        $after = time();
        self::assertMatchesRegularExpression(
            '/^UPDATE "preview_record" SET "deleted_at" = \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\' '
            . 'WHERE "deleted_at" IS NULL AND "id" = 7$/',
            $sql,
        );
        preg_match('/\'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/', $sql, $matches);
        $timestamp = (new DateTimeImmutable($matches[1], new DateTimeZone('UTC')))->getTimestamp();
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);

        $query = PreviewRecord::query()->withTrashed()->where('id', 7);
        $beforeBuild = $query->build();
        $restore = $query->preview()->restore();
        self::assertSame(
            'UPDATE "preview_record" SET "deleted_at" = NULL WHERE "deleted_at" IS NOT NULL AND "id" = 7',
            $restore,
        );
        self::assertSame($beforeBuild, $query->build());
        self::assertSame('with', $query->state->softDeleteMode, 'preview() is a snapshot');
        self::assertStringNotContainsString('deleted_at" IS', $query->preview()->get());

        $only = PreviewRecord::query()->onlyTrashed()->where('id', 7);
        self::assertStringContainsString('"deleted_at" IS NOT NULL', $only->preview()->get());
        self::assertSame(
            'DELETE FROM "preview_record" WHERE "deleted_at" IS NOT NULL AND "id" = 7',
            $only->preview()->forceDelete(),
        );
        self::assertSame([], $this->connection->operations);
        self::assertSame('before', $this->storedName());
    }

    public function testFirstAndRestoreDoNotMutateOriginalBuilder(): void
    {
        $query = PreviewRecord::query()->where('id', 7)->limit(9);
        $before = $query->build();
        $preview = $query->preview();
        self::assertStringEndsWith('LIMIT 1', $preview->first());
        self::assertStringEndsWith('LIMIT 9', $preview->get());
        self::assertSame($before, $query->build());
        self::assertSame(9, $query->state->limit);

        $restorable = PreviewRecord::query()->where('id', 7);
        $restorableBefore = $restorable->build();
        $restorablePreview = $restorable->preview();
        self::assertStringContainsString('IS NOT NULL', $restorablePreview->restore());
        self::assertStringContainsString('IS NULL', $restorablePreview->get());
        self::assertSame($restorableBefore, $restorable->build());
        self::assertSame('default', $restorable->state->softDeleteMode);
        self::assertSame('default', $restorable->state->cacheSoftDeleteMode);
    }

    public function testVersionedModelRejectsAllConditionalWritePreviews(): void
    {
        $operations = [
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->update(['name' => 'x']),
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->increment('quantity'),
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->decrement('quantity'),
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->delete(),
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->restore(),
            static fn (): string => PreviewVersionedRecord::query()->where('id', 1)->preview()->forceDelete(),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Versioned conditional writes must be rejected');
            } catch (LogicException $e) {
                self::assertStringContainsString('cannot preserve per-entity version semantics', $e->getMessage());
            }
        }
        self::assertSame([], $this->connection->operations);
    }

    public function testNonSoftDeleteModelRejectsRestoreAndForceDelete(): void
    {
        $plain = PreviewPlainRecord::query()->where('id', 1);
        self::assertSame('DELETE FROM "preview_plain_record" WHERE "id" = 1', $plain->preview()->delete());
        foreach (['restore', 'forceDelete'] as $method) {
            try {
                $plain->preview()->{$method}();
                self::fail($method . ' requires a soft-delete field');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('does not declare a soft-delete field', $e->getMessage());
            }
        }

        try {
            $plain->forceDelete();
            self::fail('Real and preview conditional writes must share forceDelete validation');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('does not declare a soft-delete field', $e->getMessage());
        }
        self::assertSame([], $this->connection->operations);
    }

    public function testRelationConstraintRejectsPreviewAndTerminalCalls(): void
    {
        try {
            PreviewRecord::query()->whereHas('children', static function (Builder $query): string {
                return $query->preview()->get();
            })->preview()->get();
            self::fail('Relation constraints must not preview SQL');
        } catch (RelationException) {
        }

        $this->expectException(RelationException::class);
        PreviewRecord::query()->whereHas('children', static fn (Builder $query): SqlPreview => $query->preview())
            ->preview()->get();
    }

    #[DataProvider('literalDialects')]
    public function testLiteralFormattingUsesTheCurrentDialect(
        string $driver,
        string $true,
        string $string,
    ): void {
        $raw = Builder::raw(':enabled', ['enabled' => true]);
        $sql = Builder::raw(
            ':enabled AND :disabled AND :integer AND :float AND :null_value AND :text',
            [
                'enabled' => true,
                'disabled' => false,
                'integer' => -42,
                'float' => 1.25,
                'null_value' => null,
                'text' => "O'Reilly\\line\n:q0\0\t",
            ],
        );
        self::assertSame($true . ' AND ' . ($driver === 'pgsql' ? 'FALSE' : '0') . ' AND -42 AND 1.25 AND NULL AND ' . $string,
            SqlCompiler::interpolate($sql->sql, $sql->params, $driver));
        self::assertSame($true, SqlCompiler::interpolate($raw->sql, $raw->params, $driver));
    }

    /** @return array<string, array{string, string, string}> */
    public static function literalDialects(): array
    {
        $escaped = "'O''Reilly{slash}line\\n:q0\\0\\t'";
        return [
            'sqlite' => ['sqlite', '1', str_replace('{slash}', '\\', $escaped)],
            'mysql' => ['mysql', '1', str_replace('{slash}', '\\\\', $escaped)],
            'pgsql' => ['pgsql', 'TRUE', str_replace('{slash}', '\\', $escaped)],
        ];
    }

    public function testWholeFloatAndNonFiniteFloatFormatting(): void
    {
        self::assertSame('SELECT 1.0', SqlCompiler::interpolate('SELECT :q0', ['q0' => 1.0], 'sqlite'));
        foreach ([NAN, INF, -INF] as $value) {
            try {
                SqlCompiler::interpolate('SELECT :q0', ['q0' => $value], 'sqlite');
                self::fail('Non-finite floats cannot become SQL literals');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('finite', $e->getMessage());
            }
        }
    }

    public function testMoreThanTenParametersUseFullPlaceholderNames(): void
    {
        $query = PreviewPlainRecord::query()->whereIn('id', range(1, 12));
        self::assertSame(
            'SELECT "id" FROM "preview_plain_record" WHERE "id" IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12)',
            $query->preview()->rows(),
        );
    }

    public function testLexerPreservesQuotedCommentedAndDollarQuotedText(): void
    {
        $projection = <<<'SQL'
':q0' AS single_quoted,
'''escaped''' AS single_escaped,
":q0" AS double_quoted,
"""escaped""" AS double_escaped,
`:q0` AS backtick,
```escaped``` AS backtick_escaped,
$$:q0$$ AS dollar,
$tag$:q0$tag$ AS tagged
SQL;
        $query = PreviewPlainRecord::query()
            ->selectRaw($projection)
            ->whereRaw("id = :actual::int AND ':q0' = ':q0' /* :q0 */ -- :q0\n# :q0\n", ['actual' => 7]);
        $sql = $query->preview()->rows();

        self::assertStringContainsString("SELECT ':q0' AS single_quoted", $sql);
        self::assertStringContainsString("'''escaped'''", $sql);
        self::assertStringContainsString('":q0" AS double_quoted', $sql);
        self::assertStringContainsString('"""escaped"""', $sql);
        self::assertStringContainsString('`:q0` AS backtick', $sql);
        self::assertStringContainsString('```escaped```', $sql);
        self::assertStringContainsString('$$:q0$$', $sql);
        self::assertStringContainsString('$tag$:q0$tag$', $sql);
        self::assertStringContainsString('WHERE id = 7::int', $sql);
        self::assertStringContainsString("':q0' = ':q0'", $sql);
        self::assertStringContainsString('/* :q0 */', $sql);
        self::assertStringContainsString('-- :q0', $sql);
        self::assertStringContainsString('# :q0', $sql);
        self::assertSame([], $this->connection->operations);
    }

    public function testBackslashEscapesAreOnlyActiveInsideMysqlQuotedText(): void
    {
        $sql = "SELECT '\\':q0' AS literal, :q0";
        self::assertSame(
            "SELECT '\\':q0' AS literal, 7",
            SqlCompiler::interpolate($sql, ['q0' => 7], 'mysql'),
        );
    }

    public function testMissingPlaceholderAndNoExecutionAcrossAllPreviews(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQL preview is missing parameter: missing');
        SqlCompiler::interpolate('SELECT :missing', [], 'sqlite');
    }

    private function storedName(): string
    {
        return (string) $this->pdo->query('SELECT name FROM preview_record WHERE id = 7')->fetchColumn();
    }
}

final class PreviewRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public float $amount;

    #[Column]
    public int $quantity;

    #[Column]
    public bool $active;

    #[Column]
    public ?string $note = null;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string
    {
        return 'preview_record';
    }

    #[HasMany(target: PreviewChild::class, localKey: ['id'], foreignKey: ['parent_id'])]
    public function children(): \Pixiaoka\Orm\Relation\HasMany
    {
        return $this->makeRelation();
    }
}

final class PreviewChild extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public int $parent_id;

    public static function table(): string
    {
        return 'preview_child';
    }
}

final class PreviewPlainRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    public static function table(): string
    {
        return 'preview_plain_record';
    }
}

final class PreviewVersionedRecord extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public int $quantity;

    #[Column]
    #[Version]
    public int $revision;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string
    {
        return 'preview_versioned_record';
    }
}

final class PreviewRecordingConnection implements Connection, DriverAwareConnection
{
    /** @var list<array{string, string, array<string, int|float|string|bool|null>}> */
    public array $operations = [];

    public function __construct(private readonly string $dialect)
    {
    }

    public function execute(string $sql, array $params): int
    {
        $this->operations[] = ['execute', $sql, $params];
        return 0;
    }

    public function queryOne(string $sql, array $params): ?array
    {
        $this->operations[] = ['queryOne', $sql, $params];
        return null;
    }

    public function queryAll(string $sql, array $params): array
    {
        $this->operations[] = ['queryAll', $sql, $params];
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
