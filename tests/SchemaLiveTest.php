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
            $auto = $driver === 'mysql' ? 'BIGINT NOT NULL AUTO_INCREMENT' : 'BIGINT NOT NULL';
            $pdo->exec("CREATE TABLE $q$table$q (id $auto PRIMARY KEY, label VARCHAR(255) NOT NULL, note TEXT NULL, obsolete TEXT NULL, tenant_id BIGINT NOT NULL, UNIQUE (tenant_id))");
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
            $pdo->exec($driver === 'mysql'
                ? "CREATE INDEX $q$expression$q ON $q$table$q ((LOWER(label)))"
                : "CREATE INDEX $q$expression$q ON $q$table$q (LOWER(label))");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('expression');
            $inspector->table($table);
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS $q$table$q");
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
