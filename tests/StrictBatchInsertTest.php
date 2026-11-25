<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use Pixiaoka\Orm\Gateway\Connection;
use Pixiaoka\Orm\Gateway\DriverAwareConnection;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OrmBatchInsertTest.php';

final class StrictBatchInsertTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE batch_record (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE batch_generated (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->gateway = new EntityGateway(new \Pixiaoka\Orm\Gateway\PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    #[DataProvider('invalidRows')]
    public function testWholeBatchRejectsInvalidArrayRowsBeforeAnySql(array $invalid, string $message): void
    {
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['id' => $i, 'body' => 'row-' . $i];
        }
        $invalid['id'] ??= 501;
        $rows[] = $invalid;
        try {
            BatchRecord::insertAll($rows);
            self::fail('Invalid batch row must be rejected before SQL');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame(0, $this->rowCount('batch_record'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidRows(): array
    {
        return [
            'unknown field' => [['unknown' => 'must not ignore'], 'unknown'],
        ];
    }

    public function testDuplicateAliasedPropertyAndColumnSourcesAreRejected(): void
    {
        $this->pdo->exec('CREATE TABLE batch_alias (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)');
        try {
            BatchAliasRecord::insertAll([['id' => 1, 'display_name' => 'column', 'name' => 'property']]);
            self::fail('Same database column cannot be assigned twice');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('provided more than once', $e->getMessage());
        }
        self::assertSame(0, $this->rowCount('batch_alias'));
        BatchAliasRecord::insertAll([['id' => 1, 'name' => 'valid']]);
        self::assertSame('valid', $this->pdo->query('SELECT display_name FROM batch_alias')->fetchColumn());
    }

    public function testGeneratedPrimaryKeyProvidedAsArrayOrEntityIsRejected(): void
    {
        try {
            BatchGeneratedRecord::insertAll([['id' => 100, 'body' => 'array']]);
            self::fail('Generated array id must not be ignored');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('generated', $e->getMessage());
        }
        $row = new BatchGeneratedRecord();
        $row->id = 200;
        $row->body = 'entity';
        try {
            BatchGeneratedRecord::insertAll([$row]);
            self::fail('Generated entity id must not be ignored');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('generated', $e->getMessage());
        }
        self::assertTrue($row->isNew());
        self::assertFalse($row->hasGatewayBinding());
        self::assertSame(0, $this->rowCount('batch_generated'));
    }

    public function testLaterChunkExecutionFailureRollsBackAllRowsAndEntityState(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_batch_body BEFORE INSERT ON batch_record WHEN NEW.body = 'fail' BEGIN SELECT RAISE(ABORT, 'injected batch failure'); END");
        $connection = new BatchRecordingConnection(new \Pixiaoka\Orm\Gateway\PdoConnection($this->pdo));
        $gateway = new EntityGateway($connection);
        Session::use($gateway);
        $rows = [];
        $entities = [];
        for ($i = 1; $i <= 501; $i++) {
            $row = new BatchRecord();
            $row->id = $i;
            $row->body = $i === 501 ? 'fail' : 'row-' . $i;
            $rows[] = $row;
            $entities[] = $row;
        }
        try {
            BatchRecord::insertAll($rows);
            self::fail('Injected second-chunk failure must propagate');
        } catch (\PDOException $e) {
            self::assertStringContainsString('injected batch failure', $e->getMessage());
        }
        self::assertCount(2, $connection->writes, 'This test must reach the second chunk');
        self::assertSame(0, $this->rowCount('batch_record'), 'The first successful chunk must be rolled back');
        foreach ($entities as $row) {
            self::assertTrue($row->isNew());
            self::assertFalse($row->hasGatewayBinding());
            self::assertSame(0, $row->afterSaves);
            self::assertSame(0, $row->afterInserts);
        }
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}

final class BatchAliasRecord extends \Pixiaoka\Orm\Entity
{
    #[\Pixiaoka\Orm\Annotation\Column]
    #[\Pixiaoka\Orm\Annotation\PrimaryKey]
    public int $id;

    #[\Pixiaoka\Orm\Annotation\Column('display_name')]
    public string $name;

    public static function table(): string
    {
        return 'batch_alias';
    }
}
