<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use InvalidArgumentException;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Schema\SchemaInspector;
use Pixiaoka\Orm\Schema\TableSchema;
use Pixiaoka\Orm\Schema\TypeMapper;
use RuntimeException;

/**
 * 从 PDO Schema 生成零框架依赖的 Active Record 模型。
 * 文件只写入显式指定的目录，默认绝不覆盖已有文件。
 */
final readonly class ModelGenerator
{
    private SchemaInspector $schema;
    private TypeMapper $types;

    public function __construct(\PDO $pdo, ?TypeMapper $types = null)
    {
        $this->schema = new SchemaInspector($pdo);
        $this->types = $types ?? new TypeMapper();
    }

    /**
     * @param list<string> $tables 空列表生成全部用户表。
     * @return array{generated: array<string, string>, skipped: array<string, string>}
     *         generated/skipped 均以表名为键、目标文件路径为值。
     */
    public function generate(
        string $directory,
        string $namespace,
        string $prefix = '',
        bool $force = false,
        array $tables = [],
    ): array {
        $directory = $this->validateDirectory($directory);
        $namespace = $this->validateNamespace($namespace);
        $this->validatePrefix($prefix);

        $requested = $tables === [] ? $this->schema->tables() : array_values($tables);
        $seen = [];
        $targets = [];
        $schemas = [];
        foreach ($requested as $table) {
            if (!is_string($table)) {
                throw new InvalidArgumentException('表名必须是字符串');
            }
            if (isset($seen[$table])) {
                throw new InvalidArgumentException("表名重复: $table");
            }
            $seen[$table] = true;
            $schemas[$table] = $this->schema->table($table);
            $class = $this->className($table, $prefix);
            if (isset($targets[$class])) {
                throw new InvalidArgumentException("表 {$targets[$class]} 与 $table 会生成同一个类 {$class}；请调整 --prefix 或分别指定表");
            }
            $targets[$class] = $table;
        }

        // 在创建目录或覆盖文件前完成全部模型校验，避免批量生成留下部分输出。
        $rendered = [];
        foreach ($schemas as $table => $schema) {
            $rendered[$table] = $this->render($schema, $namespace, $this->className($table, $prefix));
        }

        $result = ['generated' => [], 'skipped' => []];
        $directory = $this->prepareDirectory($directory);
        foreach ($schemas as $table => $schema) {
            $class = $this->className($table, $prefix);
            $file = $directory . DIRECTORY_SEPARATOR . $class . '.php';
            if (file_exists($file) && !$force) {
                $result['skipped'][$table] = $file;
                continue;
            }
            if (file_exists($file) && !is_file($file)) {
                throw new RuntimeException("目标不是普通文件: $file");
            }
            if (file_exists($file) && $force && !is_writable($file)) {
                throw new RuntimeException("目标文件不可写: $file");
            }

            $this->write($file, $rendered[$table]);
            $result['generated'][$table] = $file;
        }

        return $result;
    }

    public function render(TableSchema $table, string $namespace, ?string $class = null): string
    {
        $namespace = $this->validateNamespace($namespace);
        if ($table->primaryKeys === []) {
            throw new InvalidArgumentException("表 {$table->name} 没有主键，无法生成可用的 Active Record 模型");
        }
        $class ??= $this->className($table->name);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class) !== 1 || $this->reserved($class)) {
            throw new InvalidArgumentException("非法 PHP 类名: $class");
        }

        $uses = [Column::class, Entity::class, PrimaryKey::class];
        foreach ($table->columns as $column) {
            $uses = [...$uses, ...$this->types->imports($column)];
        }
        $uses = array_values(array_unique($uses));
        sort($uses);

        $body = '';
        foreach ($table->columns as $column) {
            $property = $column->name;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $property) !== 1 || $property === 'this') {
                throw new InvalidArgumentException("表 {$table->name} 的字段 $property 不能作为 PHP 属性名");
            }
            $body .= "    #[Column]\n";
            if ($column->primary()) {
                $body .= $table->hasGeneratedKey()
                    ? "    #[PrimaryKey(generated: true)]\n"
                    : "    #[PrimaryKey(order: {$column->primaryOrder})]\n";
            }
            if ($this->types->phpType($column) === \DateTimeImmutable::class) {
                $body .= "    #[Temporal]\n";
            }
            $type = $this->types->nullableType($column);
            $body .= "    public $type \$$property" . $this->types->defaultInitializer($column) . ";\n\n";
        }
        $body .= "    public static function table(): string\n    {\n        return " . var_export($table->name, true) . ";\n    }\n";

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n"
            . implode('', array_map(static fn (string $use): string => "use $use;\n", $uses))
            . "\nfinal class $class extends Entity\n{\n$body}\n";
    }

    private function validateDirectory(string $directory): string
    {
        $directory = rtrim(trim($directory));
        if ($directory === '' || $directory === '.' || $directory === '..' || str_contains($directory, "\0")) {
            throw new InvalidArgumentException('--dir 必须是明确的模型输出目录，不能是当前目录或父目录');
        }
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]?|[\\\\\/]+)$/D', $directory) === 1) {
            throw new InvalidArgumentException('--dir 不能指向文件系统根目录: ' . $directory);
        }
        if (file_exists($directory) && !is_dir($directory)) {
            throw new InvalidArgumentException('输出路径不是目录: ' . $directory);
        }

        return $directory;
    }

    private function prepareDirectory(string $directory): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建输出目录: ' . $directory);
        }
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new RuntimeException('输出目录不可写: ' . $directory);
        }

        return $real;
    }

    private function validateNamespace(string $namespace): string
    {
        $namespace = trim($namespace, " \\t\n\r\0\x0B\\");
        if ($namespace === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('非法 PHP 命名空间: ' . $namespace);
        }
        foreach (explode('\\', $namespace) as $part) {
            if ($this->reserved($part)) {
                throw new InvalidArgumentException('PHP 命名空间包含保留字: ' . $part);
            }
        }

        return $namespace;
    }

    private function validatePrefix(string $prefix): void
    {
        if ($prefix !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('非法表名前缀: ' . $prefix);
        }
    }

    private function className(string $table, string $prefix = ''): string
    {
        $base = $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
        if ($base === '') {
            throw new InvalidArgumentException("表 $table 去掉前缀后为空，无法生成类名");
        }
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $base)));
        if ($this->reserved($class) && $class !== 'Final') {
            $class .= 'Model';
        }

        return $class;
    }

    private function reserved(string $name): bool
    {
        static $reserved = [
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
            'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
            'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
            'extends', 'false', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
            'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
            'list', 'match', 'namespace', 'new', 'null', 'or', 'print', 'private', 'protected', 'public',
            'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'true',
            'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'int', 'float', 'bool', 'string',
            'void', 'iterable', 'object', 'mixed', 'never', 'parent', 'self',
        ];

        return in_array(strtolower($name), $reserved, true);
    }

    private function write(string $file, string $code): void
    {
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            // force 已由 generate() 判断，这里加锁原子替换，避免留下半截 PHP 文件。
            $temporary = tempnam(dirname($file), '.pixiaoka-orm-');
            if ($temporary === false) {
                throw new RuntimeException('无法创建模型临时文件: ' . dirname($file));
            }
            try {
                if (file_put_contents($temporary, $code, LOCK_EX) !== strlen($code) || !rename($temporary, $file)) {
                    throw new RuntimeException('无法覆盖模型文件: ' . $file);
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return;
        }

        try {
            $length = 0;
            while ($length < strlen($code)) {
                $written = fwrite($handle, substr($code, $length));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('无法写入模型文件: ' . $file);
                }
                $length += $written;
            }
        } finally {
            fclose($handle);
        }
    }
}
