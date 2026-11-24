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
