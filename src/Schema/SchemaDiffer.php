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
        'time' => 'string', 'time without time zone' => 'string',
    ];

    /** @param array<string, array<string, string>> $databaseTypes 完整 PHP 类名 => 方言 => 受控类型标识符 */
    public function __construct(private readonly array $databaseTypes = [])
    {
        foreach ($databaseTypes as $class => $types) {
            if (!is_string($class) || !class_exists($class)) {
                throw new InvalidArgumentException('Custom Schema PHP class does not exist: ' . $class);
            }
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                if (!isset($types[$driver]) || !is_string($types[$driver])
                    || preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\s+[A-Za-z][A-Za-z0-9_]*)*(?:\(\d+(?:,\d+)?\))?(?:\s+UNSIGNED)?$/i', $types[$driver]) !== 1) {
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
        $expectedColumns = [];
        foreach ($model->fields as $field) {
            $expectedColumns[$field->column] = true;
            $type = $this->databaseType($field, $driver);
            $column = $actual[$field->column] ?? null;
            if ($column === null) {
                if ($driver === 'sqlite' && $field->generated) {
                    $changes[] = $this->unsupported($field, 'add_column', $type, null);
                    continue;
                }
                $changes[] = $this->change('add_column', $field->column, $type, null,
                    'ADD COLUMN ' . $this->columnDefinition($field, $type, $driver), true);
                continue;
            }
            if (!$this->compatibleTypes($type, $column->databaseType, $driver)) {
                $definition = $this->columnDefinition($field, $type, $driver, false);
                $changes[] = match ($driver) {
                    'mysql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'MODIFY COLUMN ' . $definition, true),
                    'pgsql' => $this->change('change_type', $field->column, $type, $column->databaseType,
                        'ALTER COLUMN ' . $this->quote($field->column, $driver) . ' TYPE ' . $type, true,
                        'Add a USING clause manually if implicit conversion fails'),
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
                        'Requires SQLite 3.35+; execution fails if the column is referenced by an index or constraint, requiring a manual table rebuild'),
                    default => $this->change('drop_column', $name, null, $column->databaseType,
                        'DROP COLUMN ' . $this->quote($name, $driver), true),
                };
            }
        }
        foreach ($this->compareIndexes($model, $table, $driver) as $change) {
            if ($driver === 'mysql' && in_array($change['kind'], ['add_index', 'change_index'], true)
                && !$this->mysqlIndexSupported($model, $change['expected']['columns'] ?? [])) {
                $change['executable'] = false;
                $change['note'] = 'MySQL TEXT/BLOB/JSON indexes require an explicit prefix length or a generated-column index; no silent truncation is generated';
            }
            $changes[] = $change;
        }

        $tableName = $this->quote($model->table, $driver);
        foreach ($changes as &$change) {
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
            '/* Table rebuild required: ' . $this->columnDefinition($field, $this->databaseType($field, 'sqlite'), 'sqlite', false) . ' */',
            false, 'SQLite cannot directly change column types or nullability; create a new table, copy the data, and atomically replace the original table');
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
        $type = preg_replace('/^(timestamp|time)\((\d+)\)( with(?:out)? time zone)$/', '$1$3($2)', $type) ?? $type;
        $type = preg_replace('/^(timestamp|time)( with(?:out)? time zone)\((\d+)\)$/', '$1($3)$2', $type) ?? $type;
        if (preg_match('/^(timestamp|time)\((\d+)\) with time zone$/', $type, $matches) === 1) {
            return $driver === 'sqlite' ? $type : 'datetime_tz(' . $matches[2] . ')';
        }
        $base = trim(explode('(', $type, 2)[0]);
        if ($base === 'date') {
            return 'date';
        }
        if ($driver !== 'sqlite' && in_array($base, ['datetime', 'timestamp', 'timestamp without time zone'], true)) {
            if (preg_match('/\((\d+)\)/', $type, $matches) === 1) {
                return 'datetime(' . $matches[1] . ')';
            }

            return 'datetime';
        }
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
        if ($driver === 'sqlite' && $base === 'text') {
            return 'string';
        }
        if ($driver !== 'sqlite' && in_array($base, ['timestamp with time zone', 'timestamptz'], true)) {
            if (preg_match('/\((\d+)\)/', $type, $matches) === 1) {
                return 'datetime_tz(' . $matches[1] . ')';
            }

            return 'datetime_tz';
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
