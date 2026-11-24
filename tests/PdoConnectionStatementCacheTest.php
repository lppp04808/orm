<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Gateway\PdoConnection;

/** 记录真实 SQLite prepare 次数；每次父调用仍产生独立的 PDOStatement。 */
final class StatementCachePdo extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;

        return parent::prepare($query, $options);
    }
}

final class PdoConnectionStatementCacheTest extends TestCase
{
    private StatementCachePdo $pdo;

    protected function setUp(): void
    {
        $this->pdo = new StatementCachePdo('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE statement_cache_item (id INTEGER PRIMARY KEY, value TEXT NULL)');
        $this->pdo->exec("INSERT INTO statement_cache_item (id, value) VALUES (1, 'one'), (2, 'two')");
    }

    public function testQueryAndExecuteReuseTheSameStatementInstances(): void
    {
        $connection = new PdoConnection($this->pdo);
        $select = 'SELECT id, value FROM statement_cache_item WHERE id = :id ORDER BY id';

        self::assertSame(['id' => 1, 'value' => 'one'], $connection->queryOne($select, ['id' => 1]));
        $statement = $this->cachedStatements($connection)[$select];
        self::assertSame([
            ['id' => 2, 'value' => 'two'],
        ], $connection->queryAll($select, ['id' => 2]));
        self::assertSame($statement, $this->cachedStatements($connection)[$select]);
        self::assertSame(1, $this->prepareCount($select));

        $update = 'UPDATE statement_cache_item SET value = :value WHERE id = :id';
        self::assertSame(1, $connection->execute($update, ['id' => 1, 'value' => 'updated']));
        $updateStatement = $this->cachedStatements($connection)[$update];
        self::assertSame(1, $connection->execute($update, ['id' => 1, 'value' => 'again']));
        self::assertSame($updateStatement, $this->cachedStatements($connection)[$update]);
        self::assertSame(1, $this->prepareCount($update));
        self::assertSame('again', $connection->queryOne(
            'SELECT value FROM statement_cache_item WHERE id = 1',
            [],
        )['value']);
    }

    public function testQueryOneClosesPartiallyReadResultsBeforeReuse(): void
    {
        $connection = new PdoConnection($this->pdo);
        $sql = 'SELECT id FROM statement_cache_item ORDER BY id';

        self::assertSame(['id' => 1], $connection->queryOne($sql, []));
        $statement = $this->cachedStatements($connection)[$sql];
        self::assertSame(['id' => 1], $connection->queryOne($sql, []));
        self::assertSame([['id' => 1], ['id' => 2]], $connection->queryAll($sql, []));
        self::assertSame($statement, $this->cachedStatements($connection)[$sql]);
        self::assertSame(1, $this->prepareCount($sql));
    }

    public function testReusedStatementRebindsNullAndScalarTypes(): void
    {
        $connection = new PdoConnection($this->pdo);
        $sql = 'SELECT :value AS value, typeof(:value) AS type';

        self::assertSame(['value' => null, 'type' => 'null'], $connection->queryOne($sql, ['value' => null]));
        self::assertSame(['value' => '7', 'type' => 'text'], $connection->queryOne($sql, ['value' => '7']));
        self::assertSame(['value' => 7, 'type' => 'integer'], $connection->queryOne($sql, ['value' => 7]));
        self::assertSame(['value' => null, 'type' => 'null'], $connection->queryOne($sql, ['value' => null]));
        self::assertSame(['value' => '8', 'type' => 'text'], $connection->queryOne($sql, ['value' => '8']));
        self::assertSame(1, $this->prepareCount($sql));
    }

    public function testLeastRecentlyUsedStatementIsEvicted(): void
    {
        $connection = new PdoConnection($this->pdo, statementCacheSize: 2);
        $first = 'SELECT 1 AS value';
        $second = 'SELECT 2 AS value';
        $third = 'SELECT 3 AS value';

        $connection->queryOne($first, []);
        $connection->queryOne($second, []);
        $connection->queryOne($first, []);
        self::assertSame([$second, $first], array_keys($this->cachedStatements($connection)));

        $connection->queryOne($third, []);
        self::assertSame([$first, $third], array_keys($this->cachedStatements($connection)));
        $connection->queryOne($second, []);
        self::assertSame([$third, $second], array_keys($this->cachedStatements($connection)));
        self::assertSame(1, $this->prepareCount($first));
        self::assertSame(2, $this->prepareCount($second));
        self::assertSame(1, $this->prepareCount($third));

        $connection->queryOne($first, []);
        self::assertSame([$second, $first], array_keys($this->cachedStatements($connection)));
        self::assertSame(2, $this->prepareCount($first));
    }

    public function testCacheCanBeDisabledAndRejectsNegativeCapacity(): void
    {
        $connection = new PdoConnection($this->pdo, statementCacheSize: 0);
        $sql = 'SELECT 1 AS value';

        self::assertSame(['value' => 1], $connection->queryOne($sql, []));
        self::assertSame(['value' => 1], $connection->queryOne($sql, []));
        self::assertSame([], $this->cachedStatements($connection));
        self::assertSame(2, $this->prepareCount($sql));

        $withInsertId = new PdoConnection($this->pdo, static fn (): string => '17', 0);
        self::assertSame('17', $withInsertId->lastInsertId());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Statement cache size must not be negative');
        new PdoConnection($this->pdo, statementCacheSize: -1);
    }

    public function testLongSqlAndSavepointsNeverEnterTheCache(): void
    {
        $connection = new PdoConnection($this->pdo);
        $longSql = 'SELECT 1 AS value /* ' . str_repeat('x', 4096) . ' */';
        self::assertGreaterThan(4096, strlen($longSql));

        self::assertSame(['value' => 1], $connection->queryOne($longSql, []));
        self::assertSame(['value' => 1], $connection->queryOne($longSql, []));
        self::assertSame([], $this->cachedStatements($connection));
        self::assertSame(2, $this->prepareCount($longSql));

        self::assertTrue($connection->beginTransaction());
        self::assertTrue($connection->beginTransaction());
        self::assertTrue($connection->rollback());
        self::assertTrue($connection->beginTransaction());
        self::assertTrue($connection->commit());
        self::assertTrue($connection->commit());
        self::assertSame([
            'SAVEPOINT sp_2',
            'ROLLBACK TO SAVEPOINT sp_2',
            'RELEASE SAVEPOINT sp_2',
            'SAVEPOINT sp_2',
            'RELEASE SAVEPOINT sp_2',
        ], array_values(array_filter(
            $this->pdo->preparedSql,
            static fn (string $sql): bool => str_starts_with($sql, 'SAVEPOINT')
                || str_contains($sql, 'SAVEPOINT sp_'),
        )));
        self::assertSame([], $this->cachedStatements($connection));
    }

    public function testExecutionFailureEvictsCachedStatementAndAllowsCleanRetry(): void
    {
        $connection = new PdoConnection($this->pdo);
        $sql = 'INSERT INTO statement_cache_item (id, value) VALUES (:id, :value)';
        $connection->execute($sql, ['id' => 3, 'value' => 'three']);
        self::assertArrayHasKey($sql, $this->cachedStatements($connection));

        try {
            $connection->execute($sql, ['id' => 3, 'value' => 'duplicate']);
            self::fail('A duplicate primary key must reject the second insert');
        } catch (\PDOException) {
        }
        self::assertSame([], $this->cachedStatements($connection));

        self::assertSame(1, $connection->execute($sql, ['id' => 4, 'value' => 'four']));
        self::assertSame(2, $this->prepareCount($sql));
        self::assertSame([
            ['id' => 3, 'value' => 'three'],
            ['id' => 4, 'value' => 'four'],
        ], $connection->queryAll(
            'SELECT id, value FROM statement_cache_item WHERE id >= 3 ORDER BY id',
            [],
        ));
    }

    public function testBindFailureEvictsAnExistingCachedStatement(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $statement = $this->createMock(PDOStatement::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $binds = 0;
        $statement->expects(self::exactly(2))->method('bindValue')->willReturnCallback(
            static function (string|int $parameter, mixed $value, int $type) use (&$binds): bool {
                self::assertSame(':value', $parameter);
                self::assertSame(1, $value);
                self::assertSame(PDO::PARAM_INT, $type);
                if (++$binds === 2) {
                    throw new \Error('injected bind failure');
                }

                return true;
            },
        );
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(['value' => 1]);
        $connection = new PdoConnection($pdo);
        $sql = 'SELECT :value AS value';

        self::assertSame(['value' => 1], $connection->queryOne($sql, ['value' => 1]));
        self::assertArrayHasKey($sql, $this->cachedStatements($connection));
        try {
            $connection->queryOne($sql, ['value' => 1]);
            self::fail('The injected bind failure must be propagated');
        } catch (\Error $error) {
            self::assertSame('injected bind failure', $error->getMessage());
        }
        self::assertSame([], $this->cachedStatements($connection));
    }

    public function testAllExecutionPathsCloseTheirCursor(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $statement = $this->createMock(PDOStatement::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $statement->expects(self::exactly(3))->method('execute')->willReturn(true);
        $statement->expects(self::exactly(3))->method('closeCursor')->willReturn(true);
        $statement->method('fetch')->willReturn(['value' => 1]);
        $statement->method('fetchAll')->willReturn([['value' => 1]]);
        $statement->method('rowCount')->willReturn(1);
        $connection = new PdoConnection($pdo);
        $sql = 'SELECT 1 AS value';

        self::assertSame(['value' => 1], $connection->queryOne($sql, []));
        self::assertSame([['value' => 1]], $connection->queryAll($sql, []));
        self::assertSame(1, $connection->execute($sql, []));
    }

    public function testExplicitClearAndForceRollbackReleaseCachedStatements(): void
    {
        $connection = new PdoConnection($this->pdo);
        $sql = 'SELECT 1 AS value';
        $connection->queryOne($sql, []);
        $statement = $this->cachedStatements($connection)[$sql];

        $connection->clearStatementCache();
        self::assertSame([], $this->cachedStatements($connection));
        $connection->queryOne($sql, []);
        self::assertNotSame($statement, $this->cachedStatements($connection)[$sql]);
        self::assertSame(2, $this->prepareCount($sql));

        self::assertTrue($connection->beginTransaction());
        self::assertTrue($connection->forceRollback());
        self::assertSame([], $this->cachedStatements($connection));
        self::assertFalse($this->pdo->inTransaction());

        $connection->queryOne($sql, []);
        self::assertTrue($connection->forceRollback(), 'Even an idle forced rollback releases statement handles');
        self::assertSame([], $this->cachedStatements($connection));
    }

    /** @return array<string, PDOStatement> */
    private function cachedStatements(PdoConnection $connection): array
    {
        $property = new \ReflectionProperty(PdoConnection::class, 'statementCache');

        return $property->getValue($connection);
    }

    private function prepareCount(string $sql): int
    {
        return count(array_keys($this->pdo->preparedSql, $sql, true));
    }
}
