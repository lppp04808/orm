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

    /** @var array<string, int> */
    private const array MYSQL_INTEGER_BITS = [
        'tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'integer' => 32, 'bigint' => 64,
    ];

    /** @var array<string, int> */
    private const array PGSQL_INTEGER_BITS = [
        'smallint' => 16, 'int2' => 16, 'smallserial' => 16, 'serial2' => 16,
        'integer' => 32, 'int' => 32, 'int4' => 32, 'serial' => 32, 'serial4' => 32,
        'bigint' => 64, 'int8' => 64, 'bigserial' => 64, 'serial8' => 64,
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
        'time' => 'string', 'time without time zone' => 'string',
    ];

    /**
     * @param array<string, array<string, string>> $databaseTypes 完整 PHP 类名 => 方言 => 受控类型标识符
     * @param array<string, array<string, int|bool|string>> $columnDefaults 按表名与列名指定新增 int、bool、string 字段的同类型数据库常量默认值。
     */
    public function __construct(
        private readonly array $databaseTypes = [],
        private readonly array $columnDefaults = [],
    ) {
        foreach ($databaseTypes as $class => $types) {
            if (!is_string($class) || !class_exists($class)) {
                throw new InvalidArgumentException('Custom Schema PHP class does not exist: ' . $class);
            }
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                if (!isset($types[$driver]) || !is_string($types[$driver])
                    || preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*(?:\s+[A-Za-z][A-Za-z0-9_]*)*(?:\(\d+(?:,\d+)?\))?(?:\s+UNSIGNED)?|(?:timestamp|time)\(\d+\) with(?:out)? time zone)$/iD', $types[$driver]) !== 1) {
                    throw new InvalidArgumentException("Invalid $driver database type for $class");
                }
            }
        }
    }

    /** @return Report */
    public function compare(ModelMeta $model, TableSchema $table, string $driver): array
    {
        $driver = strtolower($driver);
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException('SchemaDiffer only supports mysql, pgsql, and sqlite');
        }
        $this->assertIdentifier($model->table, 'Table');
        if ($table->name !== $model->table) {
            throw new InvalidArgumentException('SchemaDiffer model table name does not match the actual schema: ' . $model->table . ' / ' . $table->name);
        }
        $actual = [];
        foreach ($table->columns as $column) {
            $actual[$column->name] = $column;
        }
        $changes = [];
        $expectedPrimary = $model->primaryColumns();
        $actualPrimary = array_map(static fn (ColumnSchema $column): string => $column->name, $table->primaryKeys);
        if ($expectedPrimary !== $actualPrimary) {
            $changes[] = $this->manualChange('change_primary_key', 'PRIMARY KEY', $expectedPrimary, $actualPrimary,
                'Migrate the primary key manually after checking uniqueness, nullability, and foreign key dependencies');
        } elseif ($table->hasGeneratedKey() !== ($model->generatedField() !== null)) {
            $changes[] = $this->manualChange('change_primary_key', 'PRIMARY KEY', $model->generatedField() !== null, $table->hasGeneratedKey(),
                'Migrate primary key generation manually and verify existing key values');
        }
        $expectedColumns = [];
        $defaults = $this->columnDefaults[$model->table] ?? [];
        foreach ($model->fields as $field) {
            $expectedColumns[$field->column] = true;
            $type = $this->databaseType($field, $driver);
            $column = $actual[$field->column] ?? null;
            if ($column === null) {
                $hasDefault = array_key_exists($field->column, $defaults);
                if (in_array($field->column, $expectedPrimary, true) || (!$field->nullable() && !$hasDefault)) {
                    $note = in_array($field->column, $expectedPrimary, true)
                        ? 'Add primary key columns manually and populate valid key values before creating the constraint'
                        : 'Add the required column with a database default or backfill it before imposing NOT NULL';
                    $changes[] = $this->manualChange('add_column', $field->column, $type, null, $note);
                    continue;
                }
                $definition = $this->columnDefinition($field, $type, $driver);
                if ($hasDefault) {
                    $literal = $this->columnDefaultSql($field, $driver, $defaults[$field->column]);
                    if ($literal === null) {
                        $changes[] = $this->manualChange('add_column', $field->column, $type, null,
                            'Provide a constant default matching the int, bool, or UTF-8 string field type, or add the column manually');
                        continue;
                    }
                    $definition .= ' DEFAULT ' . $literal;
                }
                $changes[] = $this->change('add_column', $field->column, $type, null, 'ADD COLUMN ' . $definition, true);
                continue;
            }
            if (!$this->compatibleTypes($type, $column->databaseType, $driver)) {
                $definition = $this->columnDefinition($field, $type, $driver);
                $changes[] = match (true) {
                    $column->primary() || in_array($field->column, $expectedPrimary, true) =>
                        $this->manualChange('change_type', $field->column, $type, $column->databaseType, 'Change the primary key type manually and review foreign key dependencies'),
                    isset($table->columnDependencies[$field->column]) =>
                        $this->manualChange('change_type', $field->column, $type, $column->databaseType, $table->columnDependencies[$field->column]),
                    $driver === 'mysql' && !$field->nullable() && $column->nullable =>
                        $this->manualChange('change_type', $field->column, $type, $column->databaseType, 'Backfill existing NULL values before combining the type change with NOT NULL'),
                    $driver === 'mysql' && ($column->default !== null || $column->generated) =>
                        $this->manualChange('change_type', $field->column, $type, $column->databaseType, 'Preserve the existing database default or generation when modifying the column'),
                    $driver === 'mysql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'MODIFY COLUMN ' . $definition, true),
                    $driver === 'pgsql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'ALTER COLUMN ' . $this->quote($field->column, $driver) . ' TYPE ' . $type, true,
                        'Add a USING clause manually if implicit conversion fails'),
                    default => $this->unsupported($field, 'change_type', $type, $column->databaseType),
                };
            }
            if ($column->nullable !== $field->nullable()) {
                $changes[] = match (true) {
                    !$field->nullable() =>
                        $this->manualChange('change_nullable', $field->column, false, true, 'Backfill existing NULL values and validate the data before imposing NOT NULL'),
                    $column->primary() =>
                        $this->manualChange('change_nullable', $field->column, true, false, 'Primary key columns cannot become nullable while the constraint exists'),
                    $driver === 'mysql' && isset($table->columnDependencies[$field->column]) =>
                        $this->manualChange('change_nullable', $field->column, true, false, $table->columnDependencies[$field->column]),
                    $driver === 'mysql' && ($column->default !== null || $column->generated) =>
                        $this->manualChange('change_nullable', $field->column, true, false, 'Preserve the existing database default or generation when modifying the column'),
                    $driver === 'mysql' => $this->change('change_nullable', $field->column, true, false,
                        'MODIFY COLUMN ' . $this->columnDefinition($field, $type, $driver), true),
                    $driver === 'pgsql' => $this->change('change_nullable', $field->column, true, false,
                        'ALTER COLUMN ' . $this->quote($field->column, $driver) . ' DROP NOT NULL', true),
                    default => $this->unsupported($field, 'change_nullable', true, false),
                };
            }
        }
        foreach ($actual as $name => $column) {
            if (!isset($expectedColumns[$name])) {
                $changes[] = match (true) {
                    $column->primary() =>
                        $this->manualChange('drop_column', $name, null, $column->databaseType, 'Remove the primary key and review its dependencies before dropping this column'),
                    isset($table->columnDependencies[$name]) =>
                        $this->manualChange('drop_column', $name, null, $column->databaseType, $table->columnDependencies[$name]),
                    $driver === 'sqlite' => $this->change('drop_column', $name, null, $column->databaseType,
                        'DROP COLUMN ' . $this->quote($name, $driver), true,
                        'Requires SQLite 3.35+; execution fails if the column is referenced by an index or constraint, requiring a manual table rebuild'),
                    default => $this->change('drop_column', $name, null, $column->databaseType,
                        'DROP COLUMN ' . $this->quote($name, $driver), true),
                };
            }
        }
        foreach ($this->compareIndexes($model, $table, $driver) as $change) {
            if (isset($table->indexDependencies[$change['name']])) {
                $change = $this->manualChange($change['kind'], $change['name'], $change['expected'], $change['actual'], $table->indexDependencies[$change['name']]);
            }
            if ($driver === 'mysql' && in_array($change['kind'], ['add_index', 'change_index'], true)
                && !$this->mysqlIndexSupported($model, $change['expected']['columns'] ?? [])) {
                $change['executable'] = false;
                $change['note'] = 'MySQL TEXT/BLOB/JSON indexes require an explicit prefix length or a generated-column index; no silent truncation is generated';
            }
            $changes[] = $change;
        }

        $tableName = $this->quote($model->table, $driver);
        foreach ($changes as &$change) {
            if (!$change['executable'] && str_starts_with($change['sql'], '/*')) {
                continue;
            }
            if ($driver !== 'mysql' && in_array($change['kind'], ['add_index', 'change_index', 'drop_index'], true)) {
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

    /** @param list<string> $columns */
    private function mysqlIndexSupported(ModelMeta $model, array $columns): bool
    {
        foreach ($columns as $name) {
            foreach ($model->fields as $field) {
                if ($field->column !== $name) {
                    continue;
                }
                $type = strtolower($this->databaseType($field, 'mysql'));
                if (preg_match('/^(?:tiny|medium|long)?text$|^blob$|^json$/', $type) === 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<string> 可执行预览 SQL；含不支持变更时拒绝返回残缺迁移计划。 */
    public function previewSql(ModelMeta $model, TableSchema $table, string $driver): array
    {
        $report = $this->compare($model, $table, $driver);
        if ($report['hasUnsupported']) {
            throw new InvalidArgumentException($driver === 'sqlite'
                ? $model->table . ' has SQLite changes that cannot be executed safely; rebuild the table manually according to the report'
                : $model->table . ' has schema changes that cannot be executed safely; adjust the model or migration manually according to the report');
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
                    $this->dropIndex($name, $model->table, $driver), true, 'Index definition changed; drop the old index first');
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
            '/* Table rebuild required: ' . $this->columnDefinition($field, $this->databaseType($field, 'sqlite'), 'sqlite') . ' */',
            false, 'SQLite cannot directly change column types or nullability; create a new table, copy the data, and atomically replace the original table');
    }

    /** @return Change */
    private function manualChange(string $kind, string $name, mixed $expected, mixed $actual, string $note): array
    {
        return $this->change($kind, $name, $expected, $actual, '/* Manual migration required */', false, $note);
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

    private function columnDefinition(FieldMeta $field, string $type, string $driver): string
    {
        return $this->quote($field->column, $driver) . ' ' . $type . ($field->nullable() ? ' NULL' : ' NOT NULL');
    }

    private function columnDefaultSql(FieldMeta $field, string $driver, mixed $value): ?string
    {
        if ($field->typeName() === 'int' && is_int($value)) {
            return (string) $value;
        }
        if ($field->typeName() === 'bool' && is_bool($value)) {
            return $driver === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }
        if ($field->typeName() !== 'string' || !is_string($value) || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            return null;
        }

        // MySQL TEXT defaults require expression syntax. Hex avoids dependence on sql_mode.
        // PostgreSQL E strings likewise work with either standard_conforming_strings setting.
        return match ($driver) {
            'mysql' => "(CONVERT(X'" . bin2hex($value) . "' USING utf8mb4))",
            'pgsql' => "E'" . strtr($value, ["'" => "''", "\\" => "\\\\"]) . "'",
            default => "'" . str_replace("'", "''", $value) . "'",
        };
    }

    private function databaseType(FieldMeta $field, string $driver): string
    {
        $name = $field->typeName();
        if (isset(self::TYPE_MAP[$name])) {
            return self::TYPE_MAP[$name][$driver];
        }
        if ($name === DateTimeImmutable::class) {
            if (in_array($field->temporal, [Temporal::UNIX, Temporal::UNIX_MS], true)) {
                return self::TYPE_MAP['int'][$driver];
            }
            if ($field->temporal === Temporal::DATE) {
                return match ($driver) {
                    'mysql', 'pgsql' => 'date',
                    default => 'text',
                };
            }
            if ($field->temporal === Temporal::TEXT_US) {
                return match ($driver) {
                    'mysql' => 'datetime(6)',
                    'pgsql' => 'timestamp(6)',
                    default => 'text',
                };
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
            throw new InvalidArgumentException("$name is Castable; explicitly provide all three database types in the SchemaDiffer constructor");
        }
        throw new InvalidArgumentException("No Schema type mapping for $name on $driver");
    }

    private function compatibleTypes(string $expected, string $actual, string $driver): bool
    {
        $left = $this->semanticType($expected, $driver);
        $right = $this->semanticType($actual, $driver);
        if ($left === $right) {
            return true;
        }
        if ($left !== 'string' || !str_starts_with($right, 'string(')) {
            return false;
        }

        // 现有模型 string 默认映射 TEXT，保持向后兼容；显式 VARCHAR(n) 仍严格比较长度。
        return match ($driver) {
            'mysql' => $right === 'string(65535)',
            'pgsql' => $right === 'string(*)',
            default => true,
        };
    }

    private function semanticType(string $type, string $driver): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;
        if ($driver === 'sqlite') {
            return match (true) {
                str_contains($type, 'int') => 'integer',
                str_contains($type, 'char'), str_contains($type, 'clob'), str_contains($type, 'text') => 'text',
                $type === '', str_contains($type, 'blob') => 'blob',
                str_contains($type, 'real'), str_contains($type, 'floa'), str_contains($type, 'doub') => 'real',
                default => 'numeric',
            };
        }
        if ($driver === 'pgsql') {
            $type = preg_replace('/^(timestamp|time)( with(?:out)? time zone)\((\d+)\)$/', '$1($3)$2', $type) ?? $type;
            if (preg_match('/^(timestamp|timestamptz|time|timetz)(?:\(\s*(\d+)\s*\))?(?: (with(?:out)? time zone))?$/', $type, $matches) === 1) {
                $kind = str_starts_with($matches[1], 'timestamp') ? 'timestamp' : 'time';
                $withZone = in_array($matches[1], ['timestamptz', 'timetz'], true) || ($matches[3] ?? '') === 'with time zone';
                $precision = ($matches[2] ?? '') === '' ? 6 : (int) $matches[2];

                return $kind . ($withZone ? '_tz' : '') . '(' . $precision . ')';
            }
            if (isset(self::PGSQL_INTEGER_BITS[$type])) {
                return 'int' . self::PGSQL_INTEGER_BITS[$type];
            }
        }
        if ($driver === 'mysql') {
            if (preg_match('/^(datetime|timestamp)(?:\(\s*(\d+)\s*\))?$/', $type, $matches) === 1) {
                return $matches[1] . '(' . (int) ($matches[2] ?? 0) . ')';
            }
            if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)(?:\s*\(\s*(\d+)\s*\))?(?: (unsigned))?(?: (zerofill))?$/', $type, $matches) === 1) {
                $unsigned = ($matches[3] ?? '') !== '' || ($matches[4] ?? '') !== '';
                if ($matches[1] === 'tinyint' && ($matches[2] ?? '') === '1' && !$unsigned) {
                    return 'bool';
                }

                return ($unsigned ? 'uint' : 'int') . self::MYSQL_INTEGER_BITS[$matches[1]];
            }
        }
        $base = trim(explode('(', $type, 2)[0]);
        if ($base === 'date') {
            return 'date';
        }
        if (isset(self::FLOAT_TYPES[$base])) {
            return 'float';
        }
        if (isset(self::STRING_TYPES[$base])) {
            if (in_array($base, ['char', 'varchar', 'character varying', 'character'], true)) {
                if (preg_match('/\(\s*(\d+)\s*\)/', $type, $matches) === 1) {
                    return 'string(' . $matches[1] . ')';
                }
                // 无长度声明按驱动默认值处理，而非与任意有限长度静默等价。
                return match ($driver) {
                    'mysql' => 'string(1)',
                    'pgsql' => 'string(*)',
                    default => 'string',
                };
            }

            return 'string';
        }
        if (in_array($base, ['bool', 'boolean'], true)) {
            return 'bool';
        }
        if (in_array($base, ['json', 'jsonb'], true)) {
            return 'array';
        }
        if (in_array($base, ['numeric', 'decimal'], true)) {
            if (preg_match('/\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $type, $matches) === 1) {
                return 'decimal(' . $matches[1] . ',' . $matches[2] . ')';
            }
            if (preg_match('/\(\s*(\d+)\s*\)/', $type, $matches) === 1) {
                return 'decimal(' . $matches[1] . ',0)';
            }

            return 'decimal';
        }
        return $type;
    }

    private function quote(string $identifier, string $driver): string
    {
        $this->assertIdentifier($identifier, 'Identifier');
        $quote = $driver === 'mysql' ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function assertIdentifier(string $identifier, string $what): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $identifier) !== 1) {
            throw new InvalidArgumentException($what . ' is not an allowed identifier: ' . $identifier);
        }
    }
}
