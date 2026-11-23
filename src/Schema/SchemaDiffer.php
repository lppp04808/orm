<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Cast\Castable;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Metadata\FieldMeta;
use Pixiaoka\Orm\Metadata\ModelMeta;

/**
 * 将模型期望结构与数据库实时结构作保守比较。只生成预览，不自动执行 DDL。
 * 类型以语义归一化比较；无明确跨方言映射的自定义对象须显式指定 databaseTypes。
 *
 * @phpstan-type Change array{kind: string, name: string, expected: mixed, actual: mixed, sql: string, executable: bool, note: string|null}
 * @phpstan-type Report array{table: string, driver: string, changes: list<Change>, hasChanges: bool, hasUnsupported: bool}
 */
final class SchemaDiffer
{
    /** @var array<string, array<string, string>> */
    private const array TYPE_MAP = [
        'int' => ['mysql' => 'bigint', 'pgsql' => 'bigint', 'sqlite' => 'integer'],
        'float' => ['mysql' => 'double', 'pgsql' => 'double precision', 'sqlite' => 'real'],
        'string' => ['mysql' => 'text', 'pgsql' => 'text', 'sqlite' => 'text'],
        'bool' => ['mysql' => 'tinyint(1)', 'pgsql' => 'boolean', 'sqlite' => 'integer'],
        'array' => ['mysql' => 'json', 'pgsql' => 'jsonb', 'sqlite' => 'text'],
    ];

    /** @var array<string, string> */
    private const array INT_TYPES = [
        'tinyint' => 'int', 'smallint' => 'int', 'mediumint' => 'int', 'int' => 'int',
        'integer' => 'int', 'bigint' => 'int', 'int2' => 'int', 'int4' => 'int', 'int8' => 'int',
        'serial' => 'int', 'smallserial' => 'int', 'bigserial' => 'int', 'year' => 'int',
    ];

    /** @var array<string, string> */
    private const array FLOAT_TYPES = [
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'double precision' => 'float', 'float4' => 'float', 'float8' => 'float',
    ];

    /** @var array<string, string> */
    private const array STRING_TYPES = [
        'char' => 'string', 'varchar' => 'string', 'character varying' => 'string',
        'character' => 'string', 'text' => 'string', 'tinytext' => 'string',
        'mediumtext' => 'string', 'longtext' => 'string', 'uuid' => 'string',
        'date' => 'string', 'time' => 'string', 'time without time zone' => 'string',
        'timestamp with time zone' => 'string', 'timestamptz' => 'string',
    ];

    /** @param array<string, array<string, string>> $databaseTypes 完整 PHP 类名 => 方言 => 受控类型标识符 */
    public function __construct(private readonly array $databaseTypes = [])
    {
        foreach ($databaseTypes as $class => $types) {
            if (!is_string($class) || !class_exists($class)) {
                throw new InvalidArgumentException('自定义 Schema 类型的 PHP 类不存在: ' . $class);
            }
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                if (!isset($types[$driver]) || !is_string($types[$driver])
                    || preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\s+[A-Za-z][A-Za-z0-9_]*)*(?:\(\d+(?:,\d+)?\))?(?:\s+UNSIGNED)?$/i', $types[$driver]) !== 1) {
                    throw new InvalidArgumentException("$class 的 $driver 数据库类型无效");
                }
            }
        }
    }

    /** @return Report */
    public function compare(ModelMeta $model, TableSchema $table, string $driver): array
    {
        $driver = strtolower($driver);
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException('SchemaDiffer 仅支持 mysql、pgsql、sqlite');
        }
        $this->assertIdentifier($model->table, '表');
        if ($table->name !== $model->table) {
            throw new InvalidArgumentException('SchemaDiffer 的模型表名与实时结构不一致: ' . $model->table . ' / ' . $table->name);
        }
        $actual = [];
        foreach ($table->columns as $column) {
            $actual[$column->name] = $column;
        }
        $changes = [];
        $expectedColumns = [];
        foreach ($model->fields as $field) {
            $expectedColumns[$field->column] = true;
            $type = $this->databaseType($field, $driver);
            $column = $actual[$field->column] ?? null;
            if ($column === null) {
                $changes[] = $this->change('add_column', $field->column, $type, null,
                    'ADD COLUMN ' . $this->columnDefinition($field, $type, $driver), true);
                continue;
            }
            if ($this->semanticType($type, $driver) !== $this->semanticType($column->databaseType, $driver)) {
                $definition = $this->columnDefinition($field, $type, $driver, false);
                $changes[] = match ($driver) {
                    'mysql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'MODIFY COLUMN ' . $definition, true),
                    'pgsql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'ALTER COLUMN ' . $this->quote($field->column, $driver) . ' TYPE ' . $type, true,
                        '隐式转换失败时需人工补充 USING 子句'),
                    default => $this->unsupported($field, 'change_type', $type, $column->databaseType),
                };
            }
            if ($column->nullable !== $field->nullable()) {
                $null = $field->nullable() ? 'NULL' : 'NOT NULL';
                $changes[] = match ($driver) {
                    'mysql' => $this->change('change_nullable', $field->column, $field->nullable(), $column->nullable,
                        'MODIFY COLUMN ' . $this->columnDefinition($field, $type, $driver, false), true),
                    'pgsql' => $this->change('change_nullable', $field->column, $field->nullable(), $column->nullable,
                        'ALTER COLUMN ' . $this->quote($field->column, $driver) . ($field->nullable() ? ' DROP NOT NULL' : ' SET NOT NULL'), true),
                    default => $this->unsupported($field, 'change_nullable', $field->nullable(), $column->nullable),
                };
            }
        }
        foreach ($actual as $name => $column) {
            if (!isset($expectedColumns[$name])) {
                $changes[] = match ($driver) {
                    'sqlite' => $this->change('drop_column', $name, null, $column->databaseType,
                        'DROP COLUMN ' . $this->quote($name, $driver), true,
                        '需要 SQLite 3.35+；字段被索引或约束引用时执行会失败，需人工重建表'),
                    default => $this->change('drop_column', $name, null, $column->databaseType,
                        'DROP COLUMN ' . $this->quote($name, $driver), true),
                };
            }
        }
        foreach ($this->compareIndexes($model, $table, $driver) as $change) {
            $changes[] = $change;
        }

        $tableName = $this->quote($model->table, $driver);
        foreach ($changes as &$change) {
            if ($change['kind'] === 'add_index' && $driver !== 'mysql') {
                continue;
            }
            if ($change['kind'] === 'drop_index' && $driver !== 'mysql') {
                continue;
            }
            $change['sql'] = 'ALTER TABLE ' . $tableName . ' ' . $change['sql'] . ';';
        }
        unset($change);
        usort($changes, static function (array $a, array $b): int {
            $order = static fn (array $change): int => match ($change['kind']) {
                'drop_index' => 0,
                'add_column', 'change_type', 'change_nullable', 'drop_column' => 1,
                default => 2,
            };

            return $order($a) <=> $order($b);
        });

        return [
            'table' => $model->table,
            'driver' => $driver,
            'changes' => $changes,
            'hasChanges' => $changes !== [],
            'hasUnsupported' => count(array_filter($changes, static fn (array $change): bool => !$change['executable'])) > 0,
        ];
    }

    /** @return list<string> 可执行预览 SQL；含不支持变更时拒绝返回残缺迁移计划。 */
    public function previewSql(ModelMeta $model, TableSchema $table, string $driver): array
    {
        $report = $this->compare($model, $table, $driver);
        if ($report['hasUnsupported']) {
            throw new InvalidArgumentException($model->table . ' 存在不能安全自动执行的 SQLite 变更，请按报告人工重建表');
        }

        return array_map(static fn (array $change): string => $change['sql'], $report['changes']);
    }

    /** @return list<Change> */
    private function compareIndexes(ModelMeta $model, TableSchema $table, string $driver): array
    {
        $expected = [];
        foreach ($model->indexes as $index) {
            $expected[$index->name] = $index;
        }
        $actual = [];
        foreach ($table->indexes as $index) {
            $actual[$index->name] = $index;
        }
        $changes = [];
        foreach ($expected as $name => $index) {
            $current = $actual[$name] ?? null;
            $kind = $current === null ? 'add_index' : 'change_index';
            if ($current !== null && $current->columns === $index->columns && $current->unique === $index->unique) {
                continue;
            }
            if ($current !== null) {
                $changes[] = $this->change('drop_index', $name, null, $this->indexValue($current),
                    $this->dropIndex($name, $model->table, $driver), true, '索引定义变化，先删除旧索引');
            }
            $changes[] = $this->change($kind, $name, $this->indexValue($index),
                $current === null ? null : $this->indexValue($current),
                $this->addIndex($index, $model->table, $driver),
                true);
        }
        foreach ($actual as $name => $index) {
            if (!isset($expected[$name])) {
                $changes[] = $this->change('drop_index', $name, null, $this->indexValue($index),
                    $this->dropIndex($name, $model->table, $driver), true);
            }
        }

        return $changes;
    }

    /** @return array{name: string, columns: list<string>, unique: bool} */
    private function indexValue(IndexSchema $index): array
    {
        return ['name' => $index->name, 'columns' => $index->columns, 'unique' => $index->unique];
    }

    private function addIndex(IndexSchema $index, string $table, string $driver): string
    {
        $name = $this->quote($index->name, $driver);
        $columns = implode(', ', array_map(fn (string $column): string => $this->quote($column, $driver), $index->columns));
        if ($driver === 'mysql') {
            return 'ADD ' . ($index->unique ? 'UNIQUE INDEX ' : 'INDEX ') . $name . ' (' . $columns . ')';
        }

        return 'CREATE ' . ($index->unique ? 'UNIQUE INDEX ' : 'INDEX ') . $name
            . ' ON ' . $this->quote($table, $driver) . ' (' . $columns . ');';
    }

    private function dropIndex(string $name, string $table, string $driver): string
    {
        if ($driver === 'mysql') {
            return 'DROP INDEX ' . $this->quote($name, $driver);
        }

        return 'DROP INDEX ' . $this->quote($name, $driver) . ';';
    }

    /** @return Change */
    private function unsupported(FieldMeta $field, string $kind, mixed $expected, mixed $actual): array
    {
        return $this->change($kind, $field->column, $expected, $actual,
            '/* 需要重建表：' . $this->columnDefinition($field, $this->databaseType($field, 'sqlite'), 'sqlite', false) . ' */',
            false, 'SQLite 不支持直接修改列类型或可空性，请创建新表、复制数据并原子替换');
    }

    /** @return Change */
    private function change(
        string $kind,
        string $name,
        mixed $expected,
        mixed $actual,
        string $sql,
        bool $executable,
        ?string $note = null,
    ): array {
        return ['kind' => $kind, 'name' => $name, 'expected' => $expected, 'actual' => $actual,
            'sql' => $sql, 'executable' => $executable, 'note' => $note];
    }

    private function columnDefinition(FieldMeta $field, string $type, string $driver, bool $includeGenerated = true): string
    {
        $sql = $this->quote($field->column, $driver) . ' ' . $type . ($field->nullable() ? ' NULL' : ' NOT NULL');
        if ($includeGenerated && $field->generated) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT',
                'pgsql' => ' GENERATED BY DEFAULT AS IDENTITY',
                default => '',
            };
            // 新增代理主键预览可直接附带 PRIMARY KEY；普通新增字段不假定主键。
            $sql .= ' PRIMARY KEY';
        }

        return $sql;
    }

    private function databaseType(FieldMeta $field, string $driver): string
    {
        $name = $field->typeName();
        if (isset(self::TYPE_MAP[$name])) {
            return self::TYPE_MAP[$name][$driver];
        }
        if ($name === DateTimeImmutable::class) {
            if ($field->temporal === Temporal::UNIX) {
                return self::TYPE_MAP['int'][$driver];
            }

            return match ($driver) {
                'mysql' => 'datetime',
                'pgsql' => 'timestamp without time zone',
                default => 'text',
            };
        }
        if (is_a($name, Decimal::class, true)) {
            return match ($driver) {
                'mysql', 'pgsql' => 'decimal(65,30)',
                default => 'text',
            };
        }
        if (is_a($name, BackedEnum::class, true)) {
            $backing = (string) (new \ReflectionEnum($name))->getBackingType();

            return self::TYPE_MAP[$backing][$driver];
        }
        if (isset($this->databaseTypes[$name][$driver])) {
            return $this->databaseTypes[$name][$driver];
        }
        if (is_a($name, Castable::class, true)) {
            throw new InvalidArgumentException("$name 是 Castable，请在 SchemaDiffer 构造器中显式提供三种数据库类型");
        }
        throw new InvalidArgumentException("没有 $name 在 $driver 下的 Schema 类型映射");
    }

    private function semanticType(string $type, string $driver): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;
        $base = trim(explode('(', $type, 2)[0]);
        if ($base === 'tinyint' && preg_match('/^tinyint\s*\(\s*1\s*\)/', $type) === 1) {
            return 'bool';
        }
        if (isset(self::INT_TYPES[$base])) {
            return 'int';
        }
        if (isset(self::FLOAT_TYPES[$base])) {
            return 'float';
        }
        if (isset(self::STRING_TYPES[$base])) {
            return 'string';
        }
        if (in_array($base, ['bool', 'boolean'], true)) {
            return 'bool';
        }
        if (in_array($base, ['json', 'jsonb'], true)) {
            return 'array';
        }
        if (in_array($base, ['numeric', 'decimal'], true)) {
            return 'decimal';
        }
        if ($driver === 'sqlite' && $base === 'text') {
            return 'string';
        }
        if ($driver !== 'sqlite' && in_array($base, ['datetime', 'timestamp', 'timestamp without time zone'], true)) {
            return 'datetime';
        }

        return $type;
    }

    private function quote(string $identifier, string $driver): string
    {
        $this->assertIdentifier($identifier, '标识符');
        $quote = $driver === 'mysql' ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function assertIdentifier(string $identifier, string $what): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $identifier) !== 1) {
            throw new InvalidArgumentException($what . '名称不是受控标识符: ' . $identifier);
        }
    }
}
