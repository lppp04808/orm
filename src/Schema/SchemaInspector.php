<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * 通过 PDO 获取 SQLite、MySQL、PostgreSQL 的表和字段元数据。
 * 不接受任意 SQL 片段作为表名，避免 introspection 查询注入。
 */
final class SchemaInspector
{
    private string $driver;

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException("Unsupported database driver: $driver; only sqlite, mysql, and pgsql are supported");
        }
        $this->driver = $driver;
    }

    /** @return list<string> 排序后的用户表名。 */
    public function tables(): array
    {
        $sql = match ($this->driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'mysql' => 'SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'',
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
        };
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to read the database table list');
        }

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $table = (string) $row[0];
            $this->assertIdentifier($table);
            $tables[] = $table;
        }

        return $tables;
    }

    public function table(string $table): TableSchema
    {
        $this->assertIdentifier($table);
        $columns = match ($this->driver) {
            'sqlite' => $this->sqliteColumns($table),
            'mysql' => $this->mysqlColumns($table),
            'pgsql' => $this->pgsqlColumns($table),
        };
        if ($columns === []) {
            throw new RuntimeException("Table does not exist or has no fields: $table");
        }

        $indexes = match ($this->driver) {
            'sqlite' => $this->sqliteIndexes($table),
            'mysql' => $this->mysqlIndexes($table),
            'pgsql' => $this->pgsqlIndexes($table),
        };

        [$columnDependencies, $indexDependencies] = match ($this->driver) {
            'sqlite' => $this->sqliteDependencies($table, $columns, $indexes),
            'mysql' => $this->mysqlDependencies($table, $indexes),
            'pgsql' => $this->pgsqlDependencies($table),
        };

        return new TableSchema($table, $columns, $indexes, $columnDependencies, $indexDependencies);
    }

    private function assertIdentifier(string $identifier): void
    {
        $candidate = $identifier;
        if ($this->driver === 'pgsql') {
            // PostgreSQL 会把未加引号的输入转为小写；只对简单、未加引号的两段表名兼容该行为。
            if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/D', $identifier) === 1) {
                $candidate = substr($identifier, strpos($identifier, '.') + 1);
            }
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $candidate) !== 1) {
            throw new InvalidArgumentException('Invalid table name: ' . $identifier . '; only letters, digits, and underscores are allowed, and the name cannot start with a digit');
        }
    }

    /** @return list<ColumnSchema> */
    private function sqliteColumns(string $table): array
    {
        $statement = $this->pdo->prepare('PRAGMA table_info(' . $this->quote($table, '"') . ')');
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $primaryCount = count(array_filter($rows, static fn (array $row): bool => (int) $row['pk'] > 0));
        // WITHOUT ROWID 和行内 INTEGER PRIMARY KEY DESC 都有独立的主键索引。
        // 真正的 rowid 别名没有该索引，包含表级 PRIMARY KEY (id DESC) 的情形。
        $hasPrimaryIndex = false;
        if ($primaryCount === 1) {
            $indexStatement = $this->pdo->prepare('PRAGMA index_list(' . $this->quote($table, '"') . ')');
            $indexStatement->execute();
            foreach ($indexStatement->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if ($index['origin'] === 'pk') {
                    $hasPrimaryIndex = true;
                    break;
                }
            }
        }

        $columns = [];
        foreach ($rows as $row) {
            $this->assertIdentifier((string) $row['name']);
            $primaryOrder = (int) $row['pk'];
            $columns[] = new ColumnSchema(
                name: (string) $row['name'],
                databaseType: (string) $row['type'],
                nullable: (int) $row['notnull'] === 0 && $primaryOrder === 0,
                default: $row['dflt_value'] === null ? null : (string) $row['dflt_value'],
                defaultDialect: 'sqlite',
                primaryOrder: $primaryOrder,
                generated: !$hasPrimaryIndex
                    && $primaryCount === 1
                    && $primaryOrder === 1
                    && strcasecmp(trim((string) $row['type']), 'INTEGER') === 0,
            );
        }

        return $columns;
    }

    /** @return list<ColumnSchema> */
    private function mysqlColumns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, c.EXTRA, c.ORDINAL_POSITION, k.PK_ORDER ' .
            'FROM information_schema.COLUMNS c LEFT JOIN (' .
            'SELECT COLUMN_NAME, ORDINAL_POSITION AS PK_ORDER FROM information_schema.KEY_COLUMN_USAGE ' .
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :primary_table AND CONSTRAINT_NAME = \'PRIMARY\'' .
            ') k ON k.COLUMN_NAME = c.COLUMN_NAME ' .
            'WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = :mysql_table ORDER BY c.ORDINAL_POSITION',
        );
        $statement->execute(['primary_table' => $table, 'mysql_table' => $table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['COLUMN_NAME'];
            $this->assertIdentifier($name);
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            $generated = str_contains($extra, 'auto_increment');
            // MySQL 的 NULL 默认对未显式 DEFAULT 的 nullable 列也会返回 NULL；
            // 它表示无 SQL 默认表达式，不能误渲染成字符串 "NULL"。
            $default = $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'];
            $columns[] = new ColumnSchema(
                name: $name,
                databaseType: (string) $row['COLUMN_TYPE'],
                nullable: strtoupper((string) $row['IS_NULLABLE']) === 'YES',
                default: $default,
                primaryOrder: isset($row['PK_ORDER']) ? (int) $row['PK_ORDER'] : 0,
                generated: $generated,
                defaultDialect: 'mysql',
                // MySQL 8.0.13+ 用 EXTRA 区分表达式与内容相同的静态字符串。
                defaultExpression: str_contains($extra, 'default_generated'),
            );
        }

        return $columns;
    }

    /** @return list<ColumnSchema> */
    private function pgsqlColumns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.column_name, c.udt_name, c.data_type, c.datetime_precision, c.numeric_precision, c.numeric_scale, c.character_maximum_length, c.is_nullable, c.column_default, c.is_identity, ' .
            'COALESCE(k.pk_order, 0) AS pk_order, ' .
            "(SELECT setting FROM pg_catalog.pg_settings WHERE name = 'server_encoding') AS server_encoding " .
            'FROM information_schema.columns c ' .
            'LEFT JOIN (SELECT kcu.column_name, kcu.ordinal_position AS pk_order ' .
            'FROM information_schema.table_constraints tc ' .
            'JOIN information_schema.key_column_usage kcu ON kcu.constraint_catalog = tc.constraint_catalog ' .
            'AND kcu.constraint_schema = tc.constraint_schema AND kcu.constraint_name = tc.constraint_name ' .
            'AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name ' .
            "WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema() AND tc.table_name = :primary_table" .
            ') k ON k.column_name = c.column_name ' .
            'WHERE c.table_schema = current_schema() AND c.table_name = :pgsql_table ORDER BY c.ordinal_position',
        );
        $statement->execute(['primary_table' => $table, 'pgsql_table' => $table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['column_name'];
            $this->assertIdentifier($name);
            $default = $row['column_default'] === null ? null : (string) $row['column_default'];
            $generated = strtoupper((string) $row['is_identity']) === 'YES'
                || ($default !== null && str_starts_with(strtolower($default), 'nextval('));
            $dataType = (string) $row['data_type'];
            if ($dataType === 'USER-DEFINED' || $dataType === 'ARRAY') {
                $dataType = (string) $row['udt_name'];
            }
            $precision = $row['datetime_precision'] ?? null;
            if (is_numeric($precision) && preg_match('/^(timestamp|time)( with(?:out)? time zone)?$/i', $dataType, $matches) === 1) {
                $dataType = $matches[1] . '(' . (int) $precision . ')' . ($matches[2] ?? '');
            } elseif ($dataType === 'numeric' && is_numeric($row['numeric_precision'] ?? null)) {
                $dataType .= '(' . (int) $row['numeric_precision'] . ',' . (int) ($row['numeric_scale'] ?? 0) . ')';
            } elseif (in_array($dataType, ['character varying', 'character'], true)
                && is_numeric($row['character_maximum_length'] ?? null)) {
                $dataType .= '(' . (int) $row['character_maximum_length'] . ')';
            }
            $columns[] = new ColumnSchema(
                name: $name,
                databaseType: $dataType,
                nullable: strtoupper((string) $row['is_nullable']) === 'YES',
                default: $default,
                defaultDialect: 'pgsql',
                primaryOrder: (int) $row['pk_order'],
                generated: $generated,
                serverEncoding: strtoupper((string) $row['server_encoding']),
            );
        }

        return $columns;
    }

    /** @return list<IndexSchema> */
    private function sqliteIndexes(string $table): array
    {
        $statement = $this->pdo->prepare('PRAGMA index_list(' . $this->quote($table, '"') . ')');
        $statement->execute();
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['origin'] ?? '') === 'pk' || str_starts_with((string) $row['name'], 'sqlite_autoindex_')) {
                continue;
            }
            $name = (string) $row['name'];
            if ((int) $row['partial'] === 1) {
                throw new RuntimeException("Index $name is partial and cannot be safely compared with model field indexes");
            }
            $this->assertIdentifier($name);
            $info = $this->pdo->prepare('PRAGMA index_info(' . $this->quote($name, '"') . ')');
            $info->execute();
            $columns = [];
            foreach ($info->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ($column['name'] === null) {
                    throw new RuntimeException("Index $name contains an expression and cannot be safely compared with model field indexes");
                }
                $this->assertIdentifier((string) $column['name']);
                $columns[(int) $column['seqno']] = (string) $column['name'];
            }
            ksort($columns);
            $indexes[] = new IndexSchema($name, array_values($columns), (int) $row['unique'] === 1);
        }
        usort($indexes, static fn (IndexSchema $a, IndexSchema $b): int => $a->name <=> $b->name);

        return $indexes;
    }

    /** @return list<IndexSchema> */
    private function mysqlIndexes(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.INDEX_NAME, s.NON_UNIQUE, s.SEQ_IN_INDEX, s.COLUMN_NAME, s.SUB_PART, s.INDEX_TYPE FROM information_schema.STATISTICS s ' .
            'LEFT JOIN information_schema.TABLE_CONSTRAINTS c ON c.TABLE_SCHEMA = s.TABLE_SCHEMA ' .
            'AND c.TABLE_NAME = s.TABLE_NAME AND c.CONSTRAINT_NAME = s.INDEX_NAME ' .
            'WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = :table AND s.INDEX_NAME <> \'PRIMARY\' ' .
            'AND c.CONSTRAINT_NAME IS NULL ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX',
        );
        $statement->execute(['table' => $table]);
        $definitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['INDEX_NAME'];
            $column = $row['COLUMN_NAME'];
            if ($column === null) {
                throw new RuntimeException("Index $name contains a functional expression and cannot be safely compared with model field indexes");
            }
            if ($row['SUB_PART'] !== null || strtoupper((string) $row['INDEX_TYPE']) === 'FULLTEXT') {
                throw new RuntimeException("Index $name contains a prefix length or full-text definition and cannot be safely compared with model field indexes");
            }
            $this->assertIdentifier($name);
            $this->assertIdentifier((string) $column);
            $definitions[$name]['columns'][(int) $row['SEQ_IN_INDEX']] = (string) $column;
            $definitions[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
        }
        $indexes = [];
        foreach ($definitions as $name => $definition) {
            ksort($definition['columns']);
            $indexes[] = new IndexSchema($name, array_values($definition['columns']), $definition['unique']);
        }

        return $indexes;
    }

    /** @return list<IndexSchema> */
    private function pgsqlIndexes(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.relname AS index_name, ix.indisunique AS is_unique, a.attname AS column_name, ' .
            'CASE WHEN ix.indpred IS NULL THEN 0 ELSE 1 END AS is_partial, ' .
            'k.ordinal_position FROM pg_catalog.pg_class t ' .
            'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace ' .
            'JOIN pg_catalog.pg_index ix ON ix.indrelid = t.oid ' .
            'JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid ' .
            'CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ordinal_position) ' .
            'LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum ' .
            'WHERE n.nspname = current_schema() AND t.relname = :table AND NOT ix.indisprimary ' .
            'AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c WHERE c.conindid = i.oid AND c.conrelid = t.oid AND c.contype IN (\'p\', \'u\', \'x\')) ' .
            'ORDER BY i.relname, k.ordinal_position',
        );
        $statement->execute(['table' => $table]);
        $definitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['index_name'];
            if ((int) $row['is_partial'] === 1) {
                throw new RuntimeException("Index $name is partial and cannot be safely compared with model field indexes");
            }
            if ($row['column_name'] === null) {
                throw new RuntimeException("Index $name contains an expression or included column and cannot be safely compared with model field indexes");
            }
            $column = (string) $row['column_name'];
            $this->assertIdentifier($name);
            $this->assertIdentifier($column);
            $definitions[$name]['columns'][(int) $row['ordinal_position']] = $column;
            $definitions[$name]['unique'] = in_array($row['is_unique'], [true, 1, '1', 't'], true);
        }
        $indexes = [];
        foreach ($definitions as $name => $definition) {
            ksort($definition['columns']);
            $indexes[] = new IndexSchema($name, array_values($definition['columns']), $definition['unique']);
        }

        return $indexes;
    }

    /**
     * @param list<ColumnSchema> $columns
     * @param list<IndexSchema> $indexes
     * @return array{array<string, string>, array<string, string>}
     */
    private function sqliteDependencies(string $table, array $columns, array $indexes): array
    {
        $lookup = $this->pdo->prepare(
            'SELECT f."from" AS column_name FROM pragma_foreign_key_list(:source_table, \'main\') f '
            . 'UNION SELECT f."to" FROM sqlite_master t JOIN pragma_foreign_key_list(t.name, \'main\') f '
            . 'WHERE t.type = \'table\' AND f."table" = :target_table COLLATE NOCASE',
        );
        $lookup->execute(['source_table' => $table, 'target_table' => $table]);
        $foreign = [];
        foreach ($lookup->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if ($name !== null) {
                $foreign[strtolower((string) $name)] = true;
            }
        }
        $lookup = $this->pdo->prepare(
            'SELECT c.name FROM pragma_index_list(:table_name, \'main\') i '
            . 'JOIN pragma_index_info(i.name, \'main\') c WHERE i.origin = \'u\'',
        );
        $lookup->execute(['table_name' => $table]);
        $unique = array_map('strtolower', $lookup->fetchAll(PDO::FETCH_COLUMN));
        $lookup = $this->pdo->prepare('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = :name COLLATE NOCASE');
        $lookup->execute(['name' => $table]);
        // SQLite 不公开 CHECK 或生成表达式的依赖。有这些定义时保守地禁止自动删列。
        $hasComplexConstraints = preg_match('/\b(?:check|generated|as)\s*(?:\(|always\b)/i', (string) $lookup->fetchColumn()) === 1;
        $dependencies = [];
        foreach ($columns as $column) {
            $name = strtolower($column->name);
            if (isset($foreign[$name])) {
                $dependencies[$column->name] = 'Remove or migrate the foreign key before changing this column';
            } elseif (in_array($name, $unique, true) || $hasComplexConstraints) {
                $dependencies[$column->name] = 'Review or rebuild SQLite table constraints before changing this column';
            }
        }
        $indexDependencies = [];
        foreach ($indexes as $index) {
            if ($index->unique && isset($foreign[strtolower($index->columns[0])])) {
                $indexDependencies[$index->name] = 'This unique index may support a foreign key; migrate the constraint before dropping the index';
            }
        }

        return [$dependencies, $indexDependencies];
    }

    /** @param list<IndexSchema> $indexes @return array{array<string, string>, array<string, string>} */
    private function mysqlDependencies(string $table, array $indexes): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME IS NOT NULL AS is_foreign '
            . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :local_table '
            . 'UNION ALL SELECT REFERENCED_COLUMN_NAME, CONSTRAINT_NAME, 1 FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = :referenced_table',
        );
        $statement->execute(['local_table' => $table, 'referenced_table' => $table]);
        $columns = [];
        $foreign = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $constraint, $isForeign]) {
            if ($constraint !== 'PRIMARY') {
                $columns[(string) $name] = 'Remove or migrate the dependent constraint before changing this column';
            }
            if ((int) $isForeign === 1) {
                $foreign[(string) $name] = true;
            }
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_TYPE = 'CHECK' LIMIT 1",
        );
        $statement->execute(['table_name' => $table]);
        // Older MySQL versions have no CHECK_CONSTRAINTS catalog.
        if ($statement->fetchColumn() !== false) {
            $statement = $this->pdo->prepare(
                'SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc '
                . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
                . 'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = :table_name',
            );
            $statement->execute(['table_name' => $table]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $check) {
                preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)`/', (string) $check, $names);
                foreach ($names[1] as $name) {
                    $columns[$name] = 'Remove or migrate the CHECK constraint before changing this column';
                }
            }
        }
        $indexDependencies = [];
        foreach ($indexes as $index) {
            // MySQL 的外键目录不提供子表使用的索引名，保守保护匹配首列的索引。
            if (isset($foreign[$index->columns[0]])) {
                $indexDependencies[$index->name] = 'This index may support a foreign key; migrate the constraint before dropping the index';
            }
        }

        return [$columns, $indexDependencies];
    }

    /** @return array{array<string, string>, array<string, string>} */
    private function pgsqlDependencies(string $table): array
    {
        $statement = $this->pdo->prepare(
            'WITH target AS (SELECT c.oid FROM pg_catalog.pg_class c '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = :name) '
            . 'SELECT a.attname AS name, 0 AS is_index FROM target t JOIN pg_catalog.pg_constraint c ON c.conrelid = t.oid '
            . 'JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey) WHERE c.contype <> \'p\' '
            . 'UNION SELECT a.attname, 0 FROM target t JOIN pg_catalog.pg_constraint c ON c.confrelid = t.oid '
            . 'JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.confkey) WHERE c.contype = \'f\' '
            . 'UNION SELECT i.relname, 1 FROM target t JOIN pg_catalog.pg_constraint c ON c.confrelid = t.oid '
            . 'JOIN pg_catalog.pg_class i ON i.oid = c.conindid WHERE c.contype = \'f\'',
        );
        $statement->execute(['name' => $table]);
        $columns = [];
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $isIndex]) {
            if ((int) $isIndex === 1) {
                $indexes[(string) $name] = 'Remove or migrate the dependent foreign key before dropping this index';
            } else {
                $columns[(string) $name] = 'Remove or migrate the dependent constraint before changing this column';
            }
        }

        return [$columns, $indexes];
    }

    private function quote(string $identifier, string $quote): string
    {
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }
}
