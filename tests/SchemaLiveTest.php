<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\Index;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\ModelGenerator;
use Pixiaoka\Orm\Schema\IndexSchema;
use Pixiaoka\Orm\Schema\SchemaDiffer;
use Pixiaoka\Orm\Schema\SchemaInspector;

#[Index(['label'], name: 'idx_live_differ_label')]
final class LiveDifferEntity extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    public string $label;

    #[Column]
    public ?string $note = null;

    public static function table(): string
    {
        return 'live_differ_rows';
    }
}

final class LiveColumnDefaultsEntity extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    #[Column]
    public string $label;

    #[Column]
    public int $quantity;

    #[Column]
    public ?string $note = null;

    #[Column]
    public bool $enabled;

    public static function table(): string
    {
        return 'live_default_rows';
    }
}

/**
 * 显式配置 PIXIAOKA_ORM_LIVE_MYSQL_DSN / PIXIAOKA_ORM_LIVE_PGSQL_DSN 才执行。
 * 默认跳过，不把本机服务或 CI 凭据写进库测试；只创建并删除本次随机表。
 */
final class SchemaLiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pixiaoka-orm-live-schema-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    #[DataProvider('drivers')]
    public function testRealNativePrepareSchemaAndGeneratedModels(string $driver): void
    {
        $dsn = getenv('PIXIAOKA_ORM_LIVE_' . strtoupper($driver) . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped("未配置 PIXIAOKA_ORM_LIVE_" . strtoupper($driver) . "_DSN");
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped("当前 PHP 未加载 pdo_$driver");
        }
        $user = getenv('PIXIAOKA_ORM_LIVE_' . strtoupper($driver) . '_USER');
        $password = getenv('PIXIAOKA_ORM_LIVE_' . strtoupper($driver) . '_PASSWORD');
        $pdo = new PDO($dsn, $user === false ? null : $user,
            $password === false ? null : $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        self::assertSame($driver, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $suffix = bin2hex(random_bytes(5));
        $single = 'schema_single_' . $suffix;
        $composite = 'schema_composite_' . $suffix;
        try {
            $this->createTables($pdo, $driver, $single, $composite);
            if ($driver === 'pgsql') {
                self::assertSame('AZ', $pdo->query("SELECT E'\\x41Z'")->fetchColumn());
                self::assertSame('AZ', $pdo->query("SELECT E'\\101Z'")->fetchColumn());
                self::assertSame('AZ', $pdo->query("SELECT E'\\u0041Z'")->fetchColumn());
                self::assertSame('😀', $pdo->query("SELECT E'\\U0001F600'")->fetchColumn());
            }
            $inspector = new SchemaInspector($pdo);
            $singleSchema = $inspector->table($single);
            self::assertTrue($singleSchema->hasGeneratedKey());
            self::assertSame(['id'], array_map(static fn ($column): string => $column->name, $singleSchema->primaryKeys));
            $compositeSchema = $inspector->table($composite);
            self::assertSame(['sku', 'tenant_id'], array_map(static fn ($column): string => $column->name, $compositeSchema->primaryKeys));
            self::assertFalse($compositeSchema->hasGeneratedKey());

            $namespace = 'LiveSchema\\' . ucfirst($driver) . $suffix;
            $files = (new ModelGenerator($pdo))->generate($this->directory, $namespace, tables: [$single, $composite])['generated'];
            $reader = new MetadataReader();
            foreach ([$single, $composite] as $table) {
                $file = $files[$table];
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);
                self::assertSame(0, $status, implode(PHP_EOL, $output));
                require $file;
                $class = $namespace . '\\' . $this->className($table);
                $meta = $reader->read($class);
                self::assertSame($table, $class::table());
                self::assertSame('string', $meta->field('label')->typeName());
                self::assertSame('bool', $meta->field('enabled')->typeName());
                self::assertSame('guest', (new $class())->label);
                self::assertTrue((new $class())->enabled);
                if ($table === $single) {
                    $model = new $class();
                    self::assertSame('CURRENT_TIMESTAMP', $model->literal_time);
                    self::assertSame('(ready)', $model->literal_paren);
                    self::assertSame('', $model->dynamic_time);
                    self::assertSame('string', $meta->field('happened_at')->typeName());
                    if ($driver === 'mysql') {
                        self::assertSame('string', $meta->field('happened_on')->typeName());
                    } else {
                        self::assertSame(\DateTimeImmutable::class, $meta->field('happened_on')->typeName());
                    }
                }
            }
        } finally {
            $quote = $driver === 'mysql' ? '`' : '"';
            foreach ([$single, $composite] as $table) {
                $pdo->exec("DROP TABLE IF EXISTS $quote$table$quote");
            }
        }
    }

    #[DataProvider('drivers')]
    public function testRealIndexIntrospectionAndDifferSql(string $driver): void
    {
        $pdo = $this->connection($driver);
        $table = 'schema_differ_' . bin2hex(random_bytes(5));
        $q = $driver === 'mysql' ? '`' : '"';
        try {
            $pdo->exec("CREATE TABLE $q$table$q (id BIGINT PRIMARY KEY, label VARCHAR(255) NOT NULL, note TEXT NULL, obsolete TEXT NULL, tenant_id BIGINT NOT NULL, UNIQUE (id))");
            $index = 'idx_' . $table;
            $pdo->exec("CREATE INDEX $q$index$q ON $q$table$q (tenant_id)");
            $inspector = new SchemaInspector($pdo);
            $schema = $inspector->table($table);
            self::assertSame([$index], array_map(static fn (IndexSchema $item): string => $item->name, $schema->indexes));
            self::assertSame(['tenant_id'], $schema->indexes[0]->columns);

            $meta = (new MetadataReader())->read(LiveDifferEntity::class);
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta(
                $meta->class, $table, $meta->fields, $meta->primaryKey, $meta->identityKind, $meta->timestamps,
                indexes: [new IndexSchema('idx_live_differ_label', ['label'])],
            );
            $differ = new SchemaDiffer();
            if ($driver === 'mysql') {
                $report = $differ->compare($model, $schema, $driver);
                self::assertTrue($report['hasUnsupported']);
                $index = array_values(array_filter($report['changes'], static fn (array $change): bool => $change['kind'] === 'add_index'));
                self::assertCount(1, $index);
                self::assertFalse($index[0]['executable']);
                self::assertStringContainsString('prefix length', (string) $index[0]['note']);
                try {
                    $differ->previewSql($model, $schema, $driver);
                    self::fail('MySQL TEXT indexes must not be emitted without a prefix length');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('cannot be executed safely', $e->getMessage());
                }
                $model = new \Pixiaoka\Orm\Metadata\ModelMeta(
                    $meta->class, $table, $meta->fields, $meta->primaryKey, $meta->identityKind, $meta->timestamps,
                );
            }
            foreach ($differ->previewSql($model, $schema, $driver) as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (\PDOException $e) {
                    throw new \RuntimeException("Schema 预览执行失败: $sql", 0, $e);
                }
            }
            $actual = $inspector->table($table);
            self::assertSame([], $differ->compare($model, $actual, $driver)['changes']);

            $expression = 'expr_' . $table;
            if ($driver === 'pgsql') {
                $replacement = new \Pixiaoka\Orm\Metadata\ModelMeta(
                    $meta->class, $table, $meta->fields, $meta->primaryKey, $meta->identityKind, $meta->timestamps,
                    indexes: [new IndexSchema('idx_live_differ_label', ['note'])],
                );
                foreach ($differ->previewSql($replacement, $actual, $driver) as $sql) {
                    $pdo->exec($sql);
                }
                self::assertSame([], $differ->compare($replacement, $inspector->table($table), $driver)['changes']);

                $pdo->exec("CREATE INDEX $q$expression$q ON $q$table$q (LOWER(label))");
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('expression');
                $inspector->table($table);
            } else {
                self::assertTrue(true, 'MySQL TEXT 列的 LOWER() 索引由数据库拒绝，前缀索引限制已在上方验证');
            }
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $q$table$q");
        }
    }

    public function testPostgresInspectorRejectsPartialIndexes(): void
    {
        $pdo = $this->connection('pgsql');
        $table = 'schema_partial_' . bin2hex(random_bytes(5));
        $index = $table . '_email';
        try {
            $pdo->exec("CREATE TABLE $table (id BIGINT PRIMARY KEY, email TEXT, enabled BOOLEAN NOT NULL)");
            $pdo->exec("CREATE UNIQUE INDEX $index ON $table (email) WHERE enabled");

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("$index is partial");
            (new SchemaInspector($pdo))->table($table);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    public function testPostgresTimestampDefaultPrecisionAndTimezone(): void
    {
        $pdo = $this->connection('pgsql');
        $table = 'schema_timestamp_' . bin2hex(random_bytes(5));
        try {
            $pdo->exec("CREATE TABLE $table (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name TEXT NOT NULL, tenant_id BIGINT NOT NULL, email TEXT, enabled BOOLEAN NOT NULL, happened_at TIMESTAMP)");
            $meta = (new MetadataReader())->read(DifferEntity::class);
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta($meta->class, $table, $meta->fields, $meta->primaryKey, $meta->identityKind, $meta->timestamps);
            $inspector = new SchemaInspector($pdo);
            $differ = new SchemaDiffer();
            foreach (['TIMESTAMP', 'TIMESTAMP(6)', 'TIMESTAMP WITHOUT TIME ZONE', 'TIMESTAMP(6) WITHOUT TIME ZONE'] as $type) {
                $pdo->exec("ALTER TABLE $table ALTER COLUMN happened_at TYPE $type");
                self::assertSame([], $differ->compare($model, $inspector->table($table), 'pgsql')['changes'], $type);
            }
            foreach (['TIMESTAMP(3)', 'TIMESTAMPTZ', 'TIMESTAMP WITH TIME ZONE'] as $type) {
                $pdo->exec("ALTER TABLE $table ALTER COLUMN happened_at TYPE $type");
                self::assertSame(['change_type'], array_column($differ->compare($model, $inspector->table($table), 'pgsql')['changes'], 'kind'), $type);
            }
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    #[DataProvider('drivers')]
    public function testRealIntegerWidthsAndUnsigned(string $driver): void
    {
        $pdo = $this->connection($driver);
        $table = 'schema_width_' . bin2hex(random_bytes(5));
        try {
            $pdo->exec("CREATE TABLE $table (id BIGINT PRIMARY KEY, label TEXT NOT NULL, note TEXT, narrow SMALLINT, medium INTEGER" . ($driver === 'mysql' ? ', unsigned_value BIGINT UNSIGNED' : '') . ')');
            $base = (new MetadataReader())->read(LiveDifferEntity::class);
            $fields = $base->fields;
            $int = $base->field('id')->type;
            foreach ($driver === 'mysql' ? ['narrow', 'medium', 'unsigned_value'] : ['narrow', 'medium'] as $field) {
                $fields[$field] = new \Pixiaoka\Orm\Metadata\FieldMeta($field, $field, $int, false, false, 0, 'text');
            }
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta($base->class, $table, $fields, $base->primaryKey, $base->identityKind, $base->timestamps);
            $schema = (new SchemaInspector($pdo))->table($table);
            $changes = (new SchemaDiffer())->compare($model, $schema, $driver)['changes'];
            $typeChanges = array_values(array_filter($changes, static fn (array $change): bool => $change['kind'] === 'change_type'));
            self::assertSame($driver === 'mysql' ? ['narrow', 'medium', 'unsigned_value'] : ['narrow', 'medium'], array_column($typeChanges, 'name'));
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    #[DataProvider('drivers')]
    public function testRealPrimaryAndRequiredMigrationsAreGuarded(string $driver): void
    {
        $pdo = $this->connection($driver);
        $table = 'schema_guard_' . bin2hex(random_bytes(5));
        $base = (new MetadataReader())->read(LiveDifferEntity::class);
        $model = new \Pixiaoka\Orm\Metadata\ModelMeta($base->class, $table, $base->fields, $base->primaryKey, $base->identityKind, $base->timestamps);
        $differ = new SchemaDiffer();
        $inspector = new SchemaInspector($pdo);
        try {
            $pdo->exec("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY, note TEXT)");
            $pdo->exec("INSERT INTO $table (id) VALUES (1)");
            $report = $differ->compare($model, $inspector->table($table), $driver);
            self::assertTrue($report['hasUnsupported']);
            self::assertSame(['change_type', 'add_column'], array_column($report['changes'], 'kind'));
            self::assertSame([false, false], array_column($report['changes'], 'executable'));
            $pdo->exec("DROP TABLE $table");
            $pdo->exec("CREATE TABLE $table (id BIGINT NOT NULL, label TEXT, note TEXT)");
            $pdo->exec("INSERT INTO $table (id, label) VALUES (1, NULL)");
            $report = $differ->compare($model, $inspector->table($table), $driver);
            self::assertTrue($report['hasUnsupported']);
            self::assertContains('change_primary_key', array_column($report['changes'], 'kind'));
            $nullability = array_values(array_filter($report['changes'], static fn (array $change): bool => $change['kind'] === 'change_nullable'));
            self::assertCount(1, $nullability);
            self::assertFalse($nullability[0]['executable']);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    #[DataProvider('drivers')]
    public function testRealDependencyPreventsDroppingColumn(string $driver): void
    {
        $pdo = $this->connection($driver);
        $parent = 'schema_parent_' . bin2hex(random_bytes(5));
        $child = 'schema_child_' . bin2hex(random_bytes(5));
        try {
            $pdo->exec("CREATE TABLE $parent (id BIGINT PRIMARY KEY, label TEXT NOT NULL, note TEXT, ref_id BIGINT UNIQUE NOT NULL, guarded BIGINT, CONSTRAINT {$parent}_check CHECK (guarded >= 0))");
            $pdo->exec("CREATE TABLE $child (parent_ref BIGINT NOT NULL, CONSTRAINT {$child}_fk FOREIGN KEY (parent_ref) REFERENCES $parent(ref_id))");
            $base = (new MetadataReader())->read(LiveDifferEntity::class);
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta($base->class, $parent, $base->fields, $base->primaryKey, $base->identityKind, $base->timestamps);
            $differ = new SchemaDiffer();
            $schema = (new SchemaInspector($pdo))->table($parent);
            $report = $differ->compare($model, $schema, $driver);
            self::assertSame(['ref_id', 'guarded'], array_column($report['changes'], 'name'));
            self::assertSame([false, false], array_column($report['changes'], 'executable'));
            $this->expectException(\InvalidArgumentException::class);
            $differ->previewSql($model, $schema, $driver);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $child");
            $pdo->exec("DROP TABLE IF EXISTS $parent");
        }
    }

    /** @return array<string, array{string}> */
    public static function defaultDrivers(): array
    {
        return self::drivers() + ['sqlite' => ['sqlite']];
    }

    #[DataProvider('defaultDrivers')]
    public function testExplicitColumnDefaultsBackfillExistingRows(string $driver): void
    {
        $pdo = $driver === 'sqlite' ? new PDO('sqlite::memory:') : $this->connection($driver);
        if ($driver === 'mysql') {
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES'");
        } elseif ($driver === 'pgsql') {
            $pdo->exec('SET standard_conforming_strings = off');
        }
        $table = 'schema_defaults_' . bin2hex(random_bytes(5));
        $base = (new MetadataReader())->read(LiveColumnDefaultsEntity::class);
        $model = new \Pixiaoka\Orm\Metadata\ModelMeta($base->class, $table, $base->fields, $base->primaryKey, $base->identityKind, $base->timestamps);
        $label = "O'Reilly\\资料";
        $differ = new SchemaDiffer(columnDefaults: [$table => ['label' => $label, 'quantity' => 0, 'note' => '', 'enabled' => false]]);
        $inspector = new SchemaInspector($pdo);
        try {
            $pdo->exec("CREATE TABLE $table (id BIGINT NOT NULL PRIMARY KEY)");
            $pdo->exec("INSERT INTO $table VALUES (1)");
            $schema = $inspector->table($table);
            self::assertTrue((new SchemaDiffer())->compare($model, $schema, $driver)['hasUnsupported']);
            $sql = $differ->previewSql($model, $schema, $driver);
            self::assertCount(4, $sql);
            foreach ($sql as $statement) {
                $pdo->exec($statement);
            }
            $pdo->exec("INSERT INTO $table (id) VALUES (2)");
            $rows = $pdo->query("SELECT label, quantity, note, enabled FROM $table ORDER BY id")->fetchAll(PDO::FETCH_NUM);
            $enabled = $driver === 'pgsql' ? false : 0;
            self::assertSame([[$label, 0, '', $enabled], [$label, 0, '', $enabled]], $rows);
            self::assertSame([], $differ->compare($model, $inspector->table($table), $driver)['changes']);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    public function testPostgresForeignKeySupportingIndexIsNotSilentlyIgnored(): void
    {
        $pdo = $this->connection('pgsql');
        $parent = 'schema_parent_' . bin2hex(random_bytes(5));
        $child = 'schema_child_' . bin2hex(random_bytes(5));
        $index = $parent . '_label';
        try {
            $pdo->exec("CREATE TABLE $parent (id BIGINT PRIMARY KEY, label TEXT NOT NULL, note TEXT)");
            $pdo->exec("CREATE UNIQUE INDEX $index ON $parent (label)");
            $pdo->exec("CREATE TABLE $child (parent_label TEXT REFERENCES $parent (label))");
            $base = (new MetadataReader())->read(LiveDifferEntity::class);
            $model = new \Pixiaoka\Orm\Metadata\ModelMeta($base->class, $parent, $base->fields, $base->primaryKey, $base->identityKind, $base->timestamps);
            $differ = new SchemaDiffer();
            $schema = (new SchemaInspector($pdo))->table($parent);
            $report = $differ->compare($model, $schema, 'pgsql');
            self::assertSame([$index], array_column($report['changes'], 'name'));
            self::assertSame(['drop_index'], array_column($report['changes'], 'kind'));
            self::assertTrue($report['hasUnsupported']);
            self::assertFalse($report['changes'][0]['executable']);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $child");
            $pdo->exec("DROP TABLE IF EXISTS $parent");
        }
    }

    private function connection(string $driver): PDO
    {
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('未配置 ' . $prefix . '_DSN');
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped("当前 PHP 未加载 pdo_$driver");
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user,
            $password === false ? null : $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function createTables(PDO $pdo, string $driver, string $single, string $composite): void
    {
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE `$single` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL DEFAULT 'guest', enabled BOOLEAN NOT NULL DEFAULT TRUE, note VARCHAR(50) NULL, literal_time VARCHAR(32) NOT NULL DEFAULT 'CURRENT_TIMESTAMP', literal_paren VARCHAR(32) NOT NULL DEFAULT '(ready)', dynamic_time VARCHAR(32) NOT NULL DEFAULT (CURRENT_TIMESTAMP), happened_at DATETIME NULL, happened_on TIMESTAMP NULL) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE `$composite` (tenant_id BIGINT NOT NULL, sku VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL DEFAULT 'guest', enabled BOOLEAN NOT NULL DEFAULT TRUE, PRIMARY KEY (sku, tenant_id)) ENGINE=InnoDB");
            return;
        }
        $pdo->exec("CREATE TABLE \"$single\" (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, label VARCHAR(100) NOT NULL DEFAULT 'guest', enabled BOOLEAN NOT NULL DEFAULT TRUE, note VARCHAR(50) NULL, literal_time TEXT NOT NULL DEFAULT 'CURRENT_TIMESTAMP', literal_paren TEXT NOT NULL DEFAULT '(ready)', dynamic_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP::TEXT, happened_at TIMESTAMPTZ NULL, happened_on TIMESTAMP WITHOUT TIME ZONE NULL)");
        $pdo->exec("CREATE TABLE \"$composite\" (tenant_id BIGINT NOT NULL, sku VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL DEFAULT 'guest', enabled BOOLEAN NOT NULL DEFAULT TRUE, PRIMARY KEY (sku, tenant_id))");
    }

    private function className(string $table): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
    }
}
