<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Cast\BcDecimal;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\ModelGenerator;
use Pixiaoka\Orm\Schema\ColumnSchema;
use Pixiaoka\Orm\Schema\SchemaInspector;
use Pixiaoka\Orm\Schema\TableSchema;
use Pixiaoka\Orm\Schema\TypeMapper;

/** 真实 SQLite introspection、模型渲染及 Composer bin 的端到端测试。 */
final class ModelGeneratorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixiaoka-orm-generator-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryTree($this->directory);
    }

    public function testSingleKeyDefaultsNullabilityAndObjectDefaults(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(<<<'SQL'
            CREATE TABLE pc_user (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL DEFAULT 'guest',
                enabled BOOLEAN NOT NULL DEFAULT 'true',
                visits INTEGER NOT NULL,
                rating REAL,
                note TEXT NULL,
                amount NUMERIC(10, 2) NOT NULL DEFAULT 12.50,
                happened_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'
            )
            SQL);

        $schema = new SchemaInspector($pdo);
        self::assertSame(['pc_user'], $schema->tables());
        $table = $schema->table('pc_user');
        self::assertTrue($table->hasGeneratedKey());
        self::assertSame('id', $table->primaryKeys[0]->name);
        self::assertTrue($table->columns[0]->generated);

        $result = (new ModelGenerator($pdo))->generate($this->directory . '/models', 'App\\Model', 'pc_');
        $file = realpath($this->directory . '/models') . DIRECTORY_SEPARATOR . 'User.php';
        self::assertSame(['pc_user' => $file], $result['generated']);
        self::assertSame([], $result['skipped']);
        $code = file_get_contents($file);
        self::assertIsString($code);
        self::assertStringContainsString('namespace App\\Model;', $code);
        self::assertStringContainsString('use Pixiaoka\\Orm\\Entity;', $code);
        self::assertStringContainsString('#[PrimaryKey(generated: true)]', $code);
        self::assertStringContainsString("public string \$name = 'guest';", $code);
        self::assertStringContainsString('public bool $enabled = true;', $code);
        self::assertStringContainsString('public int $visits = 0;', $code);
        self::assertStringContainsString('public ?float $rating;', $code);
        self::assertStringContainsString('public ?string $note;', $code);
        self::assertStringNotContainsString('$note = null', $code);
        self::assertStringContainsString('public BcDecimal $amount;', $code);
        self::assertStringNotContainsString('new BcDecimal', $code);
        self::assertStringContainsString("#[Temporal]\n    public DateTimeImmutable \$happened_at;", $code);
        self::assertStringNotContainsString('new DateTimeImmutable', $code);
        self::assertStringNotContainsString("'0000-00-00", $code);
        self::assertStringContainsString("return 'pc_user';", $code);

        $this->lint($file);
        require $file;
        $model = new \App\Model\User();
        self::assertSame('guest', $model->name);
        self::assertTrue($model->enabled);
        self::assertSame(0, $model->visits);
        self::assertFalse($model->propSet('note'));
        $model->note = null;
        self::assertTrue($model->propSet('note'));
        $model->amount = BcDecimal::fromString('12.50');
        $fields = (new MetadataReader())->read($model::class)->fields;
        self::assertSame(BcDecimal::class, $fields['amount']->typeName());
        self::assertSame(\DateTimeImmutable::class, $fields['happened_at']->typeName());

        // 默认不覆盖，force 才允许覆盖同一目标。
        $second = (new ModelGenerator($pdo))->generate($this->directory . '/models', 'App\\Model', 'pc_');
        self::assertSame([], $second['generated']);
        self::assertSame(['pc_user' => $file], $second['skipped']);
        file_put_contents($file, str_replace("'guest'", "'changed'", $code));
        $third = (new ModelGenerator($pdo))->generate($this->directory . '/models', 'App\\Model', 'pc_', true);
        self::assertSame(['pc_user' => $file], $third['generated']);
        self::assertStringContainsString("'guest'", (string) file_get_contents($file));
    }

    public function testGeneratedTimeColumnsUseConfigurableTimestampRoles(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE timestamped_item (id INTEGER PRIMARY KEY, created_at INTEGER NOT NULL, updated_at DATETIME NOT NULL, renamed_at DATETIME NOT NULL)');
        $code = (new ModelGenerator($pdo))->render((new SchemaInspector($pdo))->table('timestamped_item'), 'GeneratedTime\\Model');
        self::assertStringContainsString("#[CreatedAt]\n    public int \$created_at = 0;", $code);
        self::assertStringContainsString("#[UpdatedAt]\n    public DateTimeImmutable \$updated_at;", $code);
        self::assertStringNotContainsString("#[CreatedAt]\n    public DateTimeImmutable \$renamed_at;", $code);
        $file = (new ModelGenerator($pdo))->generate($this->directory, 'GeneratedTime\\Model', tables: ['timestamped_item'])['generated']['timestamped_item'];
        $this->lint($file);
        require $file;
        $meta = (new MetadataReader())->read(\GeneratedTime\Model\TimestampedItem::class);
        self::assertSame('created_at', $meta->timestampField('created_at')?->property);
        self::assertSame('updated_at', $meta->timestampField('updated_at')?->property);
    }

    public function testNonNullableIntegerVersionGetsAnnotationAndImport(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE versioned_item (id INTEGER PRIMARY KEY, body TEXT NOT NULL, version INTEGER NOT NULL)');
        $table = (new SchemaInspector($pdo))->table('versioned_item');
        $code = (new ModelGenerator($pdo))->render($table, 'GeneratedVersion\\Model');

        self::assertStringContainsString('use Pixiaoka\\Orm\\Annotation\\Version;', $code);
        self::assertSame(1, substr_count($code, '#[Version]'));
        self::assertStringContainsString("#[Version]\n    public int \$version = 0;", $code);
        $file = (new ModelGenerator($pdo))->generate($this->directory, 'GeneratedVersion\\Model', tables: ['versioned_item'])['generated']['versioned_item'];
        $this->lint($file);
        require $file;
        $meta = (new MetadataReader())->read(\GeneratedVersion\Model\VersionedItem::class);
        self::assertSame('version', $meta->versionField()?->property);
        self::assertSame('version', $meta->versionField()?->column);
    }

    public function testOnlyEligibleVersionColumnIsAnnotatedAndImported(): void
    {
        $cases = [
            'ordinary_revision' => [
                'CREATE TABLE ordinary_revision (id INTEGER PRIMARY KEY, body TEXT NOT NULL, revision INTEGER NOT NULL)',
                'public int $revision = 0;',
            ],
            'nullable_version' => [
                'CREATE TABLE nullable_version (id INTEGER PRIMARY KEY, body TEXT NOT NULL, version INTEGER NULL)',
                'public ?int $version;',
            ],
            'text_version' => [
                'CREATE TABLE text_version (id INTEGER PRIMARY KEY, body TEXT NOT NULL, version TEXT NOT NULL)',
                "public string \$version = '';",
            ],
        ];

        foreach ($cases as $tableName => [$ddl, $property]) {
            $pdo = $this->sqlite();
            $pdo->exec($ddl);
            $table = (new SchemaInspector($pdo))->table($tableName);
            $code = (new ModelGenerator($pdo))->render($table, 'GeneratedVersion\\Model', 'IneligibleVersion');
            self::assertStringContainsString($property, $code, $tableName);
            self::assertStringNotContainsString('#[Version]', $code, $tableName . ' 不应自动声明乐观锁');
            self::assertStringNotContainsString('use Pixiaoka\\Orm\\Annotation\\Version;', $code, $tableName . ' 不应导入 Version');
        }
    }

    public function testCompositePrimaryKeyKeepsConstraintOrderAndDefaults(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(<<<'SQL'
            CREATE TABLE order_item (
                tenant_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                revision INTEGER NOT NULL DEFAULT 2,
                enabled BOOLEAN NOT NULL DEFAULT 0,
                note TEXT,
                PRIMARY KEY (sku, tenant_id)
            )
            SQL);

        $table = (new SchemaInspector($pdo))->table('order_item');
        self::assertSame(['sku', 'tenant_id'], array_map(static fn ($column): string => $column->name, $table->primaryKeys));
        self::assertFalse($table->hasGeneratedKey());
        $code = (new ModelGenerator($pdo))->render($table, 'Domain\\Catalog');
        self::assertMatchesRegularExpression('/#\[PrimaryKey\(order: 1\)\]\n    public string \$sku;/', $code);
        self::assertMatchesRegularExpression('/#\[PrimaryKey\(order: 2\)\]\n    public int \$tenant_id;/', $code);
        self::assertStringNotContainsString('generated: true', $code);
        self::assertStringContainsString('public int $revision = 2;', $code);
        self::assertStringContainsString('public bool $enabled = false;', $code);
        self::assertStringContainsString('public ?string $note;', $code);

        $result = (new ModelGenerator($pdo))->generate($this->directory, 'Domain\\Catalog', tables: ['order_item']);
        $file = $result['generated']['order_item'];
        $this->lint($file);
        require $file;
        $item = new \Domain\Catalog\OrderItem();
        self::assertSame(2, $item->revision);
        self::assertFalse($item->enabled);
        $meta = (new MetadataReader())->read($item::class);
        self::assertSame(['sku', 'tenant_id'], array_map(static fn ($field): string => $field->property, $meta->primaryKey));
    }

    public function testInvalidInputsFailWithoutWritingFiles(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE ordinary (id INTEGER PRIMARY KEY, required_name TEXT NOT NULL)');
        $generator = new ModelGenerator($pdo);

        try {
            $generator->generate($this->directory, 'App Bad', tables: ['ordinary']);
            self::fail('非法 namespace 应被拒绝');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Invalid PHP namespace', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->directory);

        foreach (["users'; DROP TABLE users; --", 'a-b', '1table', 'schema.table'] as $table) {
            try {
                $generator->generate($this->directory, 'App\\Model', tables: [$table]);
                self::fail("非法表名 $table 应被拒绝");
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Invalid table name', $error->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->directory);

        $file = $this->directory . '/not-a-directory';
        self::assertTrue(mkdir($this->directory, 0777, true));
        file_put_contents($file, 'keep');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Output path is not a directory');
        $generator->generate($file, 'App\\Model', tables: ['ordinary']);
    }

    public function testCommandLineGeneratesRealLintableModels(): void
    {
        $directory = $this->directory;
        self::assertTrue(mkdir($directory, 0777, true));
        $database = $directory . '/generator.sqlite';
        $pdo = new PDO('sqlite:' . $database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE pc_cli_item (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT 'hello', active BOOLEAN NOT NULL DEFAULT 1)");
        $pdo = null;
        $bin = dirname(__DIR__) . '/bin/pixiaoka-orm';
        $output = $this->runProcess([
            PHP_BINARY, '-d', 'extension=pdo_sqlite', $bin, 'generate',
            '--dsn', 'sqlite:' . $database,
            '--dir', $directory . '/models',
            '--namespace', 'Cli\\Generated',
            '--prefix', 'pc_',
            'pc_cli_item',
        ]);
        self::assertSame(0, $output['status'], $output['stdout'] . $output['stderr']);
        self::assertStringContainsString('Generated pc_cli_item', $output['stdout']);
        self::assertStringContainsString('Done: 1 generated, 0 skipped.', $output['stdout']);
        $file = $directory . '/models/CliItem.php';
        self::assertFileExists($file);
        $this->lint($file);
        $code = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Cli\\Generated;', $code);
        self::assertStringContainsString("public string \$title = 'hello';", $code);
        self::assertStringContainsString('public bool $active = true;', $code);

        $again = $this->runProcess([
            PHP_BINARY, '-d', 'extension=pdo_sqlite', $bin, 'generate',
            '--dsn', 'sqlite:' . $database,
            '--dir', $directory . '/models',
            '--namespace', 'Cli\\Generated',
            '--prefix', 'pc_',
            'pc_cli_item',
        ]);
        self::assertSame(0, $again['status']);
        self::assertStringContainsString('Done: 0 generated, 1 skipped.', $again['stdout']);

        $forced = $this->runProcess([
            PHP_BINARY, '-d', 'extension=pdo_sqlite', $bin, 'generate',
            '--dsn', 'sqlite:' . $database,
            '--dir', $directory . '/models',
            '--namespace', 'Cli\\Generated',
            '--prefix', 'pc_', '--force', 'pc_cli_item',
        ]);
        self::assertSame(0, $forced['status'], $forced['stderr']);
        self::assertStringContainsString('Done: 1 generated, 0 skipped.', $forced['stdout']);

        $invalid = $this->runProcess([
            PHP_BINARY, '-d', 'extension=pdo_sqlite', $bin, 'generate', '--dsn=sqlite::memory:', '--dir=' . $directory, '--namespace=Bad Namespace',
        ]);
        self::assertSame(1, $invalid['status']);
        self::assertStringContainsString('Invalid PHP namespace', $invalid['stderr']);

        $missing = $this->runProcess([
            PHP_BINARY, $bin, 'generate', '--dsn', '--dir', $directory . '/models',
            '--namespace', 'Cli\\Generated',
        ]);
        self::assertSame(1, $missing['status']);
        self::assertStringContainsString('Option --dsn requires a value', $missing['stderr']);
    }

    public function testJsonAndObjectDefaultsNeverProduceInvalidPhpInitializers(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(<<<'SQL'
            CREATE TABLE setting (
                id INTEGER PRIMARY KEY,
                options JSON NOT NULL DEFAULT '{"flags":[1,2]}',
                price DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                created_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        $file = (new ModelGenerator($pdo))->generate($this->directory, 'App\\Config', tables: ['setting'])['generated']['setting'];
        $code = (string) file_get_contents($file);
        self::assertStringContainsString('public array $options = ', $code);
        self::assertStringContainsString("'flags' =>", $code);
        self::assertStringContainsString('public BcDecimal $price;', $code);
        self::assertStringContainsString('public DateTimeImmutable $created_on;', $code);
        self::assertStringNotContainsString('new ', $code);
        self::assertStringNotContainsString('CURRENT_TIMESTAMP', $code);
        $this->lint($file);
        require $file;
        $setting = new \App\Config\Setting();
        self::assertSame(['flags' => [1, 2]], $setting->options);
        self::assertFalse($setting->propSet('price'));
        self::assertFalse($setting->propSet('created_on'));
    }

    public function testTableWithoutPrimaryKeyIsRejectedBeforeCreatingDirectory(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE audit_log (message TEXT NOT NULL)');
        $generator = new ModelGenerator($pdo);
        $table = (new SchemaInspector($pdo))->table('audit_log');
        self::assertSame([], $table->primaryKeys);
        $reflection = new \ReflectionClass($generator);
        $render = $reflection->getMethod('render');
        self::assertTrue($render->isPublic());

        try {
            $generator->generate($this->directory, 'App\\Model', tables: ['audit_log']);
            self::fail('无主键表不应生成不可用的 Active Record');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('has no primary key', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->directory);

        try {
            $generator->render($table, 'App\\Model');
            self::fail('直接 render 无主键表也必须失败');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Table audit_log has no primary key', $error->getMessage());
        }

        // 已有目录存在时也不能因为跳过文件而绕开模型可用性校验。
        self::assertTrue(mkdir($this->directory));
        file_put_contents($this->directory . '/AuditLog.php', 'do not overwrite');
        try {
            $generator->generate($this->directory, 'App\\Model', tables: ['audit_log']);
            self::fail('跳过目标文件也不能生成无主键模型');
        } catch (InvalidArgumentException) {
            self::assertSame('do not overwrite', file_get_contents($this->directory . '/AuditLog.php'));
        }
    }

    public function testDefaultStringBackslashesAndQuotesAreBytePreserved(): void
    {
        $pdo = $this->sqlite();
        $single = 'C:' . chr(92) . 'temp';
        $double = 'C:' . chr(92) . chr(92) . 'temp';
        $quote = "it's " . chr(92) . 'safe';
        $pdo->exec('CREATE TABLE path_setting (id INTEGER PRIMARY KEY, single_path TEXT NOT NULL DEFAULT '
            . $pdo->quote($single) . ', double_path TEXT NOT NULL DEFAULT ' . $pdo->quote($double)
            . ', quoted_text TEXT NOT NULL DEFAULT ' . $pdo->quote($quote) . ')');
        $file = (new ModelGenerator($pdo))->generate($this->directory, 'DefaultSmoke\\Model', tables: ['path_setting'])['generated']['path_setting'];
        require $file;
        $model = new \DefaultSmoke\Model\PathSetting();
        self::assertSame($single, $model->single_path, 'SQLite 单反斜杠必须保留');
        self::assertSame($double, $model->double_path, 'SQLite 双反斜杠不能被折叠');
        self::assertSame($quote, $model->quoted_text);
        self::assertSame('single_path', (new MetadataReader())->read($model::class)->field('single_path')->property);
        $this->lint($file);

        $mapper = new TypeMapper();
        $postgresSingle = new ColumnSchema('path', 'text', false, "'C:" . chr(92) . "temp'::text", 0, false, 'pgsql');
        $postgresDouble = new ColumnSchema('path', 'text', false, "'C:" . chr(92) . chr(92) . "temp'::text", 0, false, 'pgsql');
        self::assertSame(' = ' . var_export($single, true), $mapper->defaultInitializer($postgresSingle));
        self::assertSame(' = ' . var_export($double, true), $mapper->defaultInitializer($postgresDouble));

        $mysqlDefault = 'C:' . chr(92) . 'temp' . chr(92);
        $mysql = new ColumnSchema('path', 'varchar', false, $mysqlDefault, 0, false, 'mysql');
        self::assertSame(' = ' . var_export($mysqlDefault, true), $mapper->defaultInitializer($mysql));
        $mysqlQuoted = new ColumnSchema('path', 'varchar', false, "'literal quotes'", 0, false, 'mysql');
        self::assertSame(' = ' . var_export("'literal quotes'", true), $mapper->defaultInitializer($mysqlQuoted));

        $escaped = new ColumnSchema('path', 'text', false, "E'C:" . chr(92) . chr(92) . "temp'", 0, false, 'pgsql');
        self::assertSame(' = ' . var_export($single, true), $mapper->defaultInitializer($escaped));
    }

    public function testPostgresEscapeDefaultDecodesNumericAndUnicodeForms(): void
    {
        $mapper = new TypeMapper();
        $cases = [
            "E'\\101'" => 'A',
            "E'\\x41'" => 'A',
            "E'\\u0041'" => 'A',
            "E'\\U0001F600'" => "\u{1F600}",
            "E'\\u20AC'" => "\u{20AC}",
            "E'\\12'" => "\n",
            "E'\\x'" => 'x',
            "E'\\x41Z'" => 'AZ',
            "E'\\101Z'" => 'AZ',
            "E'\\u0041Z'" => 'AZ',
            "E'\\U0001F600Z'" => "\u{1F600}Z",
        ];
        foreach ($cases as $literal => $expected) {
            $column = new ColumnSchema('value', 'text', false, $literal, 0, false, 'pgsql', false, 'UTF8');
            self::assertSame(' = ' . var_export($expected, true), $mapper->defaultInitializer($column), $literal);
        }
        foreach (["E'\\u12'", "E'\\0'", "E'\\uD800'", "E'\\U00110000'", "E'\\377'", "E'\\777'"] as $literal) {
            $column = new ColumnSchema('value', 'text', false, $literal, 0, false, 'pgsql');
            self::assertSame(" = ''", $mapper->defaultInitializer($column), $literal);
        }
        $utf8 = new ColumnSchema('value', 'text', false, "E'\\303\\277'", 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(' = ' . var_export('ÿ', true), $mapper->defaultInitializer($utf8));
        $ascii = new ColumnSchema('value', 'text', false, "E'\\377'", 0, false, 'pgsql', false, 'SQL_ASCII');
        self::assertSame(" = ''", $mapper->defaultInitializer($ascii));
        $invalidUtf8 = new ColumnSchema('value', 'text', false, "E'\\377'", 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(" = ''", $mapper->defaultInitializer($invalidUtf8));
        $mixedUtf8 = new ColumnSchema('value', 'text', false, "E'\\303\\277\\u0041Z'", 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(' = ' . var_export('ÿAZ', true), $mapper->defaultInitializer($mixedUtf8));
        $invalidMixedUtf8 = new ColumnSchema('value', 'text', false, "E'\\377\\u0041'", 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(" = ''", $mapper->defaultInitializer($invalidMixedUtf8));
        foreach (['SQL_ASCII', 'UNKNOWN_ENCODING', 'LATIN1'] as $encoding) {
            $mixed = new ColumnSchema('value', 'text', false, "E'A\\351\\u0041Z'", 0, false, 'pgsql', false, $encoding);
            self::assertSame(" = ''", $mapper->defaultInitializer($mixed), "混合 Unicode 与非 UTF8 编码 $encoding 不得错误渲染");
        }
        $latin1 = new ColumnSchema('value', 'text', false, "E'A\\351Z'", 0, false, 'pgsql', false, 'LATIN1');
        self::assertSame(' = ' . var_export('AéZ', true), $mapper->defaultInitializer($latin1));
    }

    public function testPostgresArrayTypeCastIsRemovedFromStaticDefaults(): void
    {
        $mapper = new TypeMapper();
        $options = new ColumnSchema('options', 'jsonb', false, "'[\"x\"]'::jsonb[]", 0, false, 'pgsql');
        self::assertSame(' = ' . var_export(['x'], true), $mapper->defaultInitializer($options));
        $escapedOptions = new ColumnSchema('options', 'jsonb', false, 'E' . var_export('["x"]', true) . '::jsonb[]', 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(' = ' . var_export(['x'], true), $mapper->defaultInitializer($escapedOptions));
        $label = new ColumnSchema('label', 'text', false, "'ready'::text[]", 0, false, 'pgsql');
        self::assertSame(" = 'ready'", $mapper->defaultInitializer($label));
        $escapedLabel = new ColumnSchema('label', 'text', false, "E'ready'::text[]", 0, false, 'pgsql', false, 'UTF8');
        self::assertSame(" = 'ready'", $mapper->defaultInitializer($escapedLabel));
    }

    public function testMysqlDefaultExpressionsAreNotConfusedWithStaticText(): void
    {
        $mapper = new TypeMapper();
        foreach (['CURRENT_TIMESTAMP', '(ready)', 'now()'] as $literal) {
            $column = new ColumnSchema('value', 'varchar', false, $literal, 0, false, 'mysql');
            self::assertSame(' = ' . var_export($literal, true), $mapper->defaultInitializer($column));
        }
        $expression = new ColumnSchema('value', 'varchar', false, 'CURRENT_TIMESTAMP', 0, false, 'mysql', true);
        self::assertSame(" = ''", $mapper->defaultInitializer($expression));
        $array = new ColumnSchema('options', 'json', false, '["x"]', 0, false, 'mysql');
        self::assertStringContainsString("'x'", $mapper->defaultInitializer($array));
    }

    public function testTemporalMappingOnlyUsesLosslessSecondPrecisionText(): void
    {
        $mapper = new TypeMapper();
        $cases = [
            'date' => 'string',
            'time' => 'string',
            'time with time zone' => 'string',
            'timetz' => 'string',
            'timestamptz' => 'string',
            'timestamp with time zone' => 'string',
            'timestamp(6) without time zone' => 'string',
            'datetime(6)' => 'string',
            'timestamp without time zone' => \DateTimeImmutable::class,
            'datetime' => \DateTimeImmutable::class,
        ];
        foreach ($cases as $databaseType => $expected) {
            $column = new ColumnSchema('at', $databaseType, false, null, 0, false);
            self::assertSame($expected, $mapper->phpType($column), $databaseType);
        }
        self::assertSame('string', $mapper->phpType(new ColumnSchema('at', 'timestamp', false, null, 0, false, 'pgsql')));
        self::assertSame('string', $mapper->phpType(new ColumnSchema('at', 'datetime', false, null, 0, false, 'mysql')));
        self::assertSame('string', $mapper->phpType(new ColumnSchema('at', 'timestamp', false, null, 0, false, 'mysql')));

        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE schedule (id INTEGER PRIMARY KEY, run_date DATE NOT NULL, run_time TIME NOT NULL, at DATETIME NOT NULL)');
        $file = (new ModelGenerator($pdo))->generate($this->directory, 'TemporalSmoke\\Model', tables: ['schedule'])['generated']['schedule'];
        require $file;
        $fields = (new MetadataReader())->read(\TemporalSmoke\Model\Schedule::class)->fields;
        self::assertSame('string', $fields['run_date']->typeName());
        self::assertSame('string', $fields['run_time']->typeName());
        self::assertSame(\DateTimeImmutable::class, $fields['at']->typeName());
        $this->lint($file);
    }

    public function testSqliteWithoutRowidIsNotAGeneratedKey(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE without_rowid_item (id INTEGER PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID');
        $table = (new SchemaInspector($pdo))->table('without_rowid_item');
        self::assertSame(['id'], array_map(static fn ($column): string => $column->name, $table->primaryKeys));
        self::assertFalse($table->hasGeneratedKey());
        $code = (new ModelGenerator($pdo))->render($table, 'SqliteWithoutRowid\\Model');
        self::assertStringContainsString('#[PrimaryKey(order: 1)]', $code);
        self::assertStringNotContainsString('generated: true', $code);
        $pdo->exec("INSERT INTO without_rowid_item (id, value) VALUES (1, 'set')");
        self::assertSame(1, $pdo->query('SELECT COUNT(*) FROM without_rowid_item')->fetchColumn());
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('NOT NULL constraint failed');
        $pdo->exec("INSERT INTO without_rowid_item (value) VALUES ('missing id')");
    }

    public function testExternalSchemaQueriesUseDistinctParameterNames(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/src/Schema/SchemaInspector.php');
        self::assertIsString($schema);
        preg_match_all('/:([a-z_][a-z0-9_]*)/i', $schema, $matches);
        foreach (['primary_table', 'mysql_table', 'pgsql_table'] as $parameter) {
            self::assertContains($parameter, $matches[1]);
        }
        self::assertStringContainsString("['primary_table' => \$table, 'mysql_table' => \$table]", $schema);
        self::assertStringContainsString("['primary_table' => \$table, 'pgsql_table' => \$table]", $schema);
        self::assertStringContainsString("primaryOrder: (int) \$row['pk_order']", $schema);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** @param list<string> $command */
    private function runProcess(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => proc_close($process)];
    }

    private function lint(string $file): void
    {
        $result = $this->runProcess([PHP_BINARY, '-l', $file]);
        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('No syntax errors detected', $result['stdout']);
    }

    private function removeTemporaryTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
