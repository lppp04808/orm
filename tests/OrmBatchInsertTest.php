<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

final class OrmBatchInsertTest extends TestCase
{
    private PDO $pdo;
    private BatchRecordingConnection $connection;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE batch_record (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE batch_generated (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE batch_cast (id INTEGER PRIMARY KEY, body TEXT NOT NULL, payload TEXT NOT NULL)');
        $this->connection = new BatchRecordingConnection(new PdoConnection($this->pdo));
        $this->gateway = new EntityGateway($this->connection);
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testLateInvalidArrayRowDoesNotExecuteAnEarlierChunk(): void
    {
        $rows = [];
        for ($id = 1; $id <= 500; $id++) {
            $rows[] = ['id' => $id, 'body' => 'row-' . $id];
        }
        $rows[] = ['id' => 501];

        try {
            BatchRecord::insertAll($rows);
            self::fail('第 501 行缺少必填字段必须拒绝');
        } catch (IdentityException $e) {
            self::assertStringContainsString('body', $e->getMessage());
        }

        self::assertSame([], $this->connection->writes);
        self::assertSame(0, $this->rowCount('batch_record'));
    }

    public function testLateInvalidArrayTypeDoesNotExecuteAnEarlierChunk(): void
    {
        $rows = [];
        for ($id = 1; $id <= 500; $id++) {
            $rows[] = ['id' => $id, 'body' => 'row-' . $id];
        }
        $rows[] = ['id' => 'not-an-int', 'body' => 'bad'];

        $this->expectException(CastException::class);
        try {
            BatchRecord::insertAll($rows);
        } finally {
            self::assertSame([], $this->connection->writes);
            self::assertSame(0, $this->rowCount('batch_record'));
        }
    }

    public function testLateInvalidEntityDoesNotWriteBindOrRunAfterHooks(): void
    {
        $rows = [];
        for ($id = 1; $id <= 500; $id++) {
            $row = new BatchRecord();
            $row->id = $id;
            $row->body = 'row-' . $id;
            $rows[] = $row;
        }
        $invalid = new BatchPlainRecord();
        $invalid->id = 501;
        $rows[] = $invalid;

        try {
            BatchRecord::insertAll($rows);
            self::fail('第 501 个实体缺少必填字段必须拒绝');
        } catch (IdentityException $e) {
            self::assertStringContainsString('body', $e->getMessage());
        }

        self::assertSame([], $this->connection->writes);
        self::assertSame(0, $this->rowCount('batch_record'));
        foreach ($rows as $row) {
            self::assertTrue($row->isNew());
            self::assertFalse($row->hasGatewayBinding(), '预检失败不能留下 Gateway 绑定');
            self::assertSame(1, $row->beforeSaves, '前置钩子只运行一次，副作用不由预检失败撤销');
            self::assertSame(1, $row->beforeInserts);
            self::assertSame(0, $row->afterSaves);
            self::assertSame(0, $row->afterInserts);
        }
    }

    public function testLateInvalidEntityCastDoesNotWriteOrBindAnyEntity(): void
    {
        $rows = [];
        for ($id = 1; $id <= 500; $id++) {
            $row = new BatchCastRecord();
            $row->id = $id;
            $row->body = 'row-' . $id;
            $row->payload = ['id' => $id];
            $rows[] = $row;
        }
        $invalid = new BatchCastRecord();
        $invalid->id = 501;
        $invalid->body = 'invalid';
        $invalid->payload = [INF];
        $rows[] = $invalid;

        $this->expectException(CastException::class);
        try {
            BatchCastRecord::insertAll($rows);
        } finally {
            self::assertSame([], $this->connection->writes);
            self::assertSame(0, $this->rowCount('batch_cast'));
            foreach ($rows as $row) {
                self::assertTrue($row->isNew());
                self::assertFalse($row->hasGatewayBinding());
                self::assertSame(1, $row->beforeSaves);
                self::assertSame(1, $row->beforeInserts);
                self::assertSame(0, $row->afterSaves);
                self::assertSame(0, $row->afterInserts);
            }
        }
    }

    public function testHooksCanSupplyRequiredFieldsAndRunOnlyOnce(): void
    {
        $first = new BatchRecord();
        $first->id = 1;
        $second = new BatchRecord();
        $second->id = 2;

        BatchRecord::insertAll([$first, $second]);

        self::assertSame(['hooked', 'hooked'], $this->pdo
            ->query('SELECT body FROM batch_record ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN));
        foreach ([$first, $second] as $row) {
            self::assertFalse($row->isNew());
            self::assertSame($this->gateway, $row->boundGateway());
            self::assertSame(1, $row->beforeSaves);
            self::assertSame(1, $row->beforeInserts);
            self::assertSame(1, $row->afterSaves);
            self::assertSame(1, $row->afterInserts);
        }
    }

    public function testRepeatedGeneratedEntityIsRejectedBeforeHooksAndSql(): void
    {
        $row = new BatchGeneratedRecord();

        try {
            BatchGeneratedRecord::insertAll([$row, ['body' => 'array'], $row]);
            self::fail('同一实体不能对应批次内的两行');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('same entity instance', $e->getMessage());
        }

        self::assertSame([], $this->connection->writes);
        self::assertSame(0, $this->rowCount('batch_generated'));
        self::assertTrue($row->isNew());
        self::assertFalse($row->hasGatewayBinding());
        self::assertFalse($row->propSet('id'));
        self::assertSame(0, $row->beforeSaves);
        self::assertSame(0, $row->beforeInserts);
    }

    public function testMixedRowsKeepOriginalOrderAndGeneratedIdsAcrossChunks(): void
    {
        $rows = [];
        $entities = [];
        $expected = [];
        for ($index = 0; $index < 501; $index++) {
            $body = 'row-' . $index;
            $expected[] = $body;
            if ($index % 2 === 0) {
                $row = new BatchGeneratedRecord();
                $row->body = $body;
                $rows[] = $row;
                $entities[$index] = $row;
            } else {
                $rows[] = ['body' => $body];
            }
        }

        BatchGeneratedRecord::insertAll($rows);

        self::assertSame($expected, $this->pdo
            ->query('SELECT body FROM batch_generated ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(2, $this->connection->writes);
        foreach ($entities as $index => $row) {
            self::assertSame($index + 1, $row->id);
            self::assertSame(1, $row->revision);
            self::assertFalse($row->isNew());
            self::assertSame(1, $row->afterInserts);
        }
    }

    public function testRollbackRestoresNewEntityBeforeHooksAndGeneratedValues(): void
    {
        $row = new BatchGeneratedRecord();

        try {
            BatchGeneratedRecord::transaction(function () use ($row): void {
                BatchGeneratedRecord::insertAll([$row]);
                self::assertFalse($row->isNew());
                self::assertSame(1, $row->id);
                self::assertSame(1, $row->revision);
                self::assertSame('hooked', $row->body);
                throw new \RuntimeException('rollback batch');
            });
            self::fail('事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback batch', $e->getMessage());
        }

        self::assertTrue($row->isNew());
        self::assertFalse($row->propSet('id'));
        self::assertFalse($row->propSet('body'), '回滚应恢复钩子补字段之前的状态');
        self::assertFalse($row->propSet('revision'), '回滚应恢复自动版本之前的状态');
        self::assertSame([], $row->takeOriginal());
        self::assertSame(0, $this->rowCount('batch_generated'));

        $row->body = 'retry';
        $row->save();
        self::assertFalse($row->isNew());
        self::assertSame('retry', $row->body);
        self::assertSame(1, $row->revision);
    }

    public function testNonReturningConnectionRejectsMixedGeneratedRowsBeforeHooksAndSql(): void
    {
        $connection = new BatchRecordingConnection(new PdoConnection($this->pdo), false);
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $row = new BatchGeneratedRecord();
        $row->body = 'entity';

        try {
            BatchGeneratedRecord::insertAll([['body' => 'array'], $row]);
            self::fail('多行混批含实体时必须拒绝不可靠的生成键回填');
        } catch (IdentityException $e) {
            self::assertStringContainsString('RETURNING', $e->getMessage());
        }

        self::assertSame([], $connection->writes);
        self::assertSame(0, $row->beforeSaves);
        self::assertSame(0, $row->beforeInserts);
        self::assertFalse($row->hasGatewayBinding());
        self::assertSame(0, $this->rowCount('batch_generated'));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}

class BatchRecord extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    #[Column]
    public string $body;

    public int $beforeSaves = 0;
    public int $beforeInserts = 0;
    public int $afterSaves = 0;
    public int $afterInserts = 0;

    public static function table(): string { return 'batch_record'; }

    public function beforeSave(): void { $this->beforeSaves++; }

    public function beforeInsert(): void
    {
        $this->beforeInserts++;
        if (!$this->propSet('body')) {
            $this->body = 'hooked';
        }
    }

    public function afterSave(): void { $this->afterSaves++; }

    public function afterInsert(): void { $this->afterInserts++; }
}

final class BatchCastRecord extends BatchRecord
{
    #[Column]
    public array $payload;

    public static function table(): string { return 'batch_cast'; }
}

final class BatchPlainRecord extends BatchRecord
{
    public function beforeInsert(): void
    {
        $this->beforeInserts++;
    }
}

final class BatchGeneratedRecord extends BatchRecord
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    #[Version]
    public int $revision;

    public static function table(): string { return 'batch_generated'; }
}

final class BatchRecordingConnection implements Connection
{
    /** @var list<array{sql: string, params: array<string, int|float|string|null>}> */
    public array $writes = [];

    public function __construct(
        private readonly Connection $inner,
        private readonly ?bool $returning = null,
    ) {}

    public function execute(string $sql, array $params): int
    {
        $this->writes[] = ['sql' => $sql, 'params' => $params];

        return $this->inner->execute($sql, $params);
    }

    public function queryOne(string $sql, array $params): ?array
    {
        return $this->inner->queryOne($sql, $params);
    }

    public function queryAll(string $sql, array $params): array
    {
        if (str_starts_with(ltrim($sql), 'INSERT')) {
            $this->writes[] = ['sql' => $sql, 'params' => $params];
        }

        return $this->inner->queryAll($sql, $params);
    }

    public function lastInsertId(): string { return $this->inner->lastInsertId(); }

    public function quoteIdentifier(string $identifier): string { return $this->inner->quoteIdentifier($identifier); }

    public function supportsReturning(): bool { return $this->returning ?? $this->inner->supportsReturning(); }

    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }

    public function commit(): bool { return $this->inner->commit(); }

    public function rollback(): bool { return $this->inner->rollback(); }
}
