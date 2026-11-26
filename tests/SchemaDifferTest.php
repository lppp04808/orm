<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\Index;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Schema\ColumnSchema;
use Pixiaoka\Orm\Schema\IndexSchema;
use Pixiaoka\Orm\Schema\SchemaDiffer;
use Pixiaoka\Orm\Schema\SchemaInspector;
use Pixiaoka\Orm\Schema\TableSchema;

#[Index(['name', 'tenant_id'], name: 'idx_schema_name')]
#[Index(['email'], name: 'uniq_schema_email', unique: true)]
final class DifferEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name = '';

    #[Column]
    public int $tenant_id = 0;

    #[Column]
    public ?string $email = null;

    #[Column]
    public bool $enabled = true;

    #[Column]
    #[Temporal]
    public ?DateTimeImmutable $happened_at = null;

    public static function table(): string
    {
        return 'differ_rows';
    }
}

final class TypedSchemaValue implements \Pixiaoka\Orm\Cast\Castable
{
    public function toDatabase(): int|float|string|null { return '0.00'; }
    public static function fromDatabase(int|float|string $value): static { return new self(); }
}

final class TypedSchemaRow extends Entity
{
    #[Column, PrimaryKey]
    public TypedSchemaValue $amount;

    public static function table(): string { return 'typed_schema'; }
}

final class SchemaDifferTest extends TestCase
{
    public function testSqliteInspectorReadsSecondaryIndexes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE differ_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, obsolete TEXT)');
        $pdo->exec('CREATE INDEX idx_schema_name ON differ_rows (name)');
        $pdo->exec('CREATE UNIQUE INDEX uniq_schema_email ON differ_rows (email)');
        $table = (new SchemaInspector($pdo))->table('differ_rows');

        self::assertCount(2, $table->indexes);
        self::assertSame('idx_schema_name', $table->indexes[0]->name);
        self::assertFalse($table->indexes[0]->unique);
        self::assertSame('uniq_schema_email', $table->indexes[1]->name);
        self::assertTrue($table->indexes[1]->unique);
        self::assertSame(['email'], $table->indexes[1]->columns);
    }

    public function testSqliteInspectorRejectsPartialIndexesInsteadOfTreatingThemAsFullIndexes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE differ_rows (id INTEGER PRIMARY KEY, email TEXT, enabled INTEGER NOT NULL)');
        $pdo->exec('CREATE UNIQUE INDEX uniq_schema_email ON differ_rows (email) WHERE enabled = 1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uniq_schema_email is partial');
        (new SchemaInspector($pdo))->table('differ_rows');
    }

    public function testStructuredReportAndSqlitePreviewCanBeExecuted(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE differ_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tenant_id INTEGER NOT NULL, email TEXT, enabled INTEGER NOT NULL, happened_at TEXT, obsolete TEXT)');
        $pdo->exec('CREATE INDEX idx_obsolete_name ON differ_rows (obsolete, name)');
        $pdo->exec('CREATE INDEX idx_only_obsolete ON differ_rows (obsolete)');
        $table = (new SchemaInspector($pdo))->table('differ_rows');
        $meta = (new MetadataReader())->read(DifferEntity::class);
        $differ = new SchemaDiffer();
        $report = $differ->compare($meta, $table, 'sqlite');

        self::assertTrue($report['hasChanges']);
        self::assertSame('drop_index', $report['changes'][0]['kind']);
        self::assertSame('idx_obsolete_name', $report['changes'][0]['name']);
        self::assertSame(['drop_index', 'drop_column', 'add_index'], array_values(array_unique(array_column($report['changes'], 'kind'))));
        self::assertSame(1, count(array_filter(
            $report['changes'],
            static fn (array $change): bool => $change['name'] === 'idx_only_obsolete',
        )), 'SQLite 必须先显式删除引用待删除字段的索引');
        self::assertStringContainsString('CREATE INDEX "idx_schema_name" ON "differ_rows" ("name", "tenant_id");', implode("\n", $differ->previewSql($meta, $table, 'sqlite')));
        self::assertStringContainsString('CREATE UNIQUE INDEX "uniq_schema_email" ON "differ_rows" ("email");', implode("\n", $differ->previewSql($meta, $table, 'sqlite')));

        foreach ($differ->previewSql($meta, $table, 'sqlite') as $sql) {
            $pdo->exec($sql);
        }
        $updated = (new SchemaInspector($pdo))->table('differ_rows');
        self::assertSame([], $differ->compare($meta, $updated, 'sqlite')['changes']);
    }

    public function testTypeNullableAndIndexChangesAcrossDialects(): void
    {
        $table = new TableSchema('differ_rows', [
            $this->column('id', 'bigint', false, primaryOrder: 1, generated: true),
            $this->column('name', 'text', false),
            $this->column('tenant_id', 'integer', false),
            $this->column('email', 'text', true),
            $this->column('enabled', 'text', true),
            $this->column('happened_at', 'text', true),
        ], [new IndexSchema('idx_schema_name', ['tenant_id'], false)]);
        $meta = (new MetadataReader())->read(DifferEntity::class);
        $differ = new SchemaDiffer();

        $mysql = $differ->compare($meta, $table, 'mysql');
        $pgsql = $differ->compare($meta, $table, 'pgsql');
        $sqlite = $differ->compare($meta, $table, 'sqlite');

        self::assertStringContainsString('ALTER TABLE `differ_rows` MODIFY COLUMN `enabled` tinyint(1) NOT NULL;', $this->sql($mysql));
        self::assertStringContainsString('ALTER TABLE `differ_rows` MODIFY COLUMN `happened_at` datetime NULL;', $this->sql($mysql));
        self::assertStringContainsString('ALTER TABLE `differ_rows` DROP INDEX `idx_schema_name`;', $this->sql($mysql));
        self::assertStringContainsString('ALTER TABLE `differ_rows` ADD INDEX `idx_schema_name` (`name`, `tenant_id`);', $this->sql($mysql));
        self::assertStringContainsString('ALTER TABLE `differ_rows` ADD UNIQUE INDEX `uniq_schema_email` (`email`);', $this->sql($mysql));

        self::assertStringContainsString('ALTER TABLE "differ_rows" ALTER COLUMN "enabled" TYPE boolean;', $this->sql($pgsql));
        self::assertStringContainsString('ALTER TABLE "differ_rows" ALTER COLUMN "enabled" SET NOT NULL;', $this->sql($pgsql));
        self::assertStringContainsString('ALTER TABLE "differ_rows" ALTER COLUMN "happened_at" TYPE timestamp without time zone;', $this->sql($pgsql));
        self::assertStringContainsString('DROP INDEX "idx_schema_name";', $this->sql($pgsql));
        self::assertStringContainsString('CREATE INDEX "idx_schema_name" ON "differ_rows" ("name", "tenant_id");', $this->sql($pgsql));

        self::assertTrue($sqlite['hasUnsupported']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rebuild the table manually');
        $differ->previewSql($meta, $table, 'sqlite');
    }

    public function testPostgresIndexReplacementUsesStandaloneCreateIndex(): void
    {
        $meta = (new MetadataReader())->read(DifferEntity::class);
        $table = new TableSchema('differ_rows', [
            $this->column('id', 'bigint', false, primaryOrder: 1, generated: true),
            $this->column('name', 'text', false),
            $this->column('tenant_id', 'bigint', false),
            $this->column('email', 'text', true),
            $this->column('enabled', 'boolean', false),
            $this->column('happened_at', 'timestamp without time zone', true),
        ], [new IndexSchema('idx_schema_name', ['tenant_id']), new IndexSchema('uniq_schema_email', ['email'], true)]);

        self::assertSame([
            'DROP INDEX "idx_schema_name";',
            'CREATE INDEX "idx_schema_name" ON "differ_rows" ("name", "tenant_id");',
        ], (new SchemaDiffer())->previewSql($meta, $table, 'pgsql'));
    }

    public function testCompatibleTypesDoNotProduceFalseChanges(): void
    {
        $table = new TableSchema('differ_rows', [
            $this->column('id', 'INTEGER', false, primaryOrder: 1, generated: true),
            $this->column('name', 'TEXT', false),
            $this->column('tenant_id', 'INTEGER', false),
            $this->column('email', 'TEXT', true),
            $this->column('enabled', 'INTEGER', false),
            $this->column('happened_at', 'TEXT', true),
        ], [
            new IndexSchema('idx_schema_name', ['name', 'tenant_id']),
            new IndexSchema('uniq_schema_email', ['email'], true),
        ]);
        $report = (new SchemaDiffer())->compare((new MetadataReader())->read(DifferEntity::class), $table, 'sqlite');
        self::assertFalse($report['hasChanges']);
        self::assertSame([], $report['changes']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('significantColumnTypes')]
    public function testColumnPrecisionLengthAndTimezoneAreSignificant(string $driver, string $actual): void
    {
        $differ = $this->typedDiffer();
        $table = new TableSchema('typed_schema', [$this->column('amount', $actual, false)]);
        $changes = array_values(array_filter(
            $differ->compare($this->typedMeta(), $table, $driver)['changes'],
            static fn (array $change): bool => $change['kind'] === 'change_type',
        ));
        self::assertCount(1, $changes, "类型 $actual 不应与期望的 decimal(10,2) 等价");
        self::assertSame('amount', $changes[0]['name']);
    }

    /** @return array<string, array{string, string}> */
    public static function significantColumnTypes(): array
    {
        return [
            'mysql decimal precision' => ['mysql', 'decimal(10,3)'],
            'pgsql decimal precision' => ['pgsql', 'numeric(12,2)'],
            'mysql varchar length' => ['mysql', 'varchar(100)'],
            'pgsql varchar length' => ['pgsql', 'character varying(100)'],
            'pgsql timestamp timezone' => ['pgsql', 'timestamp(6) with time zone'],
            'pgsql timestamptz alias' => ['pgsql', 'timestamptz'],
            'pgsql timestamp precision' => ['pgsql', 'timestamp(3) without time zone'],
        ];
    }

    public function testEquivalentColumnPrecisionAndAliasesDoNotProduceFalseChanges(): void
    {
        $differ = $this->typedDiffer();
        foreach ([
            'mysql' => 'DECIMAL(10,2)',
            'pgsql' => 'numeric(10,2)',
            'sqlite' => 'TEXT',
        ] as $driver => $actual) {
            $table = new TableSchema('typed_schema', [$this->column('amount', $actual, false)]);
            self::assertSame([], $differ->compare($this->typedMeta(), $table, $driver)['changes']);
        }
        foreach ([
            'mysql' => 'VARCHAR(50)',
            'pgsql' => 'character varying(50)',
        ] as $driver => $actual) {
            $table = new TableSchema('typed_schema', [$this->column('amount', $actual, false)]);
            $differ = new SchemaDiffer([TypedSchemaValue::class => [
                'mysql' => 'varchar(50)', 'pgsql' => 'varchar(50)', 'sqlite' => 'text',
            ]]);
            self::assertSame([], $differ->compare($this->typedMeta(), $table, $driver)['changes']);
        }
    }

    public function testSqliteDoesNotGenerateAnAdditionalPrimaryKeyColumn(): void
    {
        $meta = (new MetadataReader())->read(DifferEntity::class);
        $table = new TableSchema('differ_rows', [$this->column('name', 'text', false)]);
        $differ = new SchemaDiffer();
        $changes = array_values(array_filter(
            $differ->compare($meta, $table, 'sqlite')['changes'],
            static fn (array $change): bool => $change['kind'] === 'add_column' && $change['name'] === 'id',
        ));
        self::assertCount(1, $changes);
        self::assertFalse($changes[0]['executable']);
        self::assertStringNotContainsString('ADD COLUMN "id"', $changes[0]['sql']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rebuild the table manually');
        $differ->previewSql($meta, $table, 'sqlite');
    }

    private function typedMeta(): \Pixiaoka\Orm\Metadata\ModelMeta
    {
        $base = (new MetadataReader())->read(TypedSchemaRow::class);
        return new \Pixiaoka\Orm\Metadata\ModelMeta(
            $base->class, 'typed_schema', ['amount' => $base->field('amount')],
            [$base->field('amount')], 'natural', [],
        );
    }

    private function typedDiffer(): SchemaDiffer
    {
        return new SchemaDiffer([TypedSchemaValue::class => [
            'mysql' => 'decimal(10,2)', 'pgsql' => 'numeric(10,2)', 'sqlite' => 'text',
        ]]);
    }

    /** @param array{changes: list<array{sql: string}>} $report */
    private function sql(array $report): string
    {
        return implode("\n", array_column($report['changes'], 'sql'));
    }

    private function column(string $name, string $type, bool $nullable, int $primaryOrder = 0, bool $generated = false): ColumnSchema
    {
        return new ColumnSchema($name, $type, $nullable, null, $primaryOrder, $generated);
    }
}
