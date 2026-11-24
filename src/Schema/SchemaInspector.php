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

        return new TableSchema($table, $columns, $indexes);
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
        // INTEGER PRIMARY KEY 只有在 rowid 表中才隐式成为 rowid 别名。
        // table_list 是 SQLite 3.37+ 提供的；当前支持环境可直接读取 wr 标志。
        $tableRows = $this->pdo->query('PRAGMA table_list')->fetchAll(PDO::FETCH_ASSOC);
        $withoutRowid = false;
        foreach ($tableRows as $tableRow) {
            if (($tableRow['name'] ?? '') === $table) {
                $withoutRowid = (int) ($tableRow['wr'] ?? 0) === 1;
                break;
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
                // SQLite 只有 rowid 表的单列 INTEGER PRIMARY KEY 是 rowid 别名。
                generated: !$withoutRowid
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
            'SELECT c.column_name, c.udt_name, c.data_type, c.is_nullable, c.column_default, c.is_identity, ' .
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
            'k.ordinal_position FROM pg_catalog.pg_class t ' .
            'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace ' .
            'JOIN pg_catalog.pg_index ix ON ix.indrelid = t.oid ' .
            'JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid ' .
            'CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ordinal_position) ' .
            'LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum ' .
            'WHERE n.nspname = current_schema() AND t.relname = :table AND NOT ix.indisprimary ' .
            'AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c WHERE c.conindid = i.oid) ' .
            'ORDER BY i.relname, k.ordinal_position',
        );
        $statement->execute(['table' => $table]);
        $definitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['index_name'];
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

    private function quote(string $identifier, string $quote): string
    {
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }
}
