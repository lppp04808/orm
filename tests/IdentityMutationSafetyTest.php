<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Session;

final class IdentityMutationSafetyTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;
    private int $writes = 0;
    private int $selects = 0;

    protected function setUp(): void
    {
        Session::discard();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE identity_mutation (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE identity_composite (tenant_id INTEGER NOT NULL, code TEXT NOT NULL, body TEXT NOT NULL, PRIMARY KEY (tenant_id, code))');
        $this->pdo->exec('CREATE TABLE identity_reference (id INTEGER PRIMARY KEY, target_id INTEGER NOT NULL)');
        $this->useGateway();
    }

    private function useGateway(?int $capacity = null): void
    {
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        if ($capacity !== null) {
            $property = new \ReflectionProperty(EntityGateway::class, 'cache');
            $cacheCapacity = new \ReflectionProperty(\Pixiaoka\Orm\Cache\RowCache::class, 'maxSize');
            $cacheCapacity->setValue($property->getValue($this->gateway), $capacity);
        }
        Session::use($this->gateway);
        $this->gateway->subscribe(function (Event $event): void {
            if ($event instanceof SqlEvent && in_array($event->operation, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $this->writes++;
            }
            if ($event instanceof SqlEvent && $event->operation === 'SELECT') {
                $this->selects++;
            }
        });
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testUpdateRejectsChangedGeneratedKeyBeforeSqlAndLeavesOtherRowUntouched(): void
    {
        [$first, $second] = [$this->row('first'), $this->row('second')];
        $first->id = $second->id;
        $first->body = 'corrupt';

        $this->assertRejectedBeforeWrite(static fn () => $first->save());
        self::assertSame(['first', 'second'], $this->bodies());
        self::assertSame(1, $this->gateway->knownIdentity($first)['id']);
    }

    #[DataProvider('destructiveOperations')]
    public function testDeleteRestoreAndForceDeleteRejectChangedKeyBeforeSql(string $operation, bool $softDeleted): void
    {
        $row = $this->row('target');
        if ($softDeleted) {
            $row->delete();
        }
        $row->id = 999;
        $before = $this->writes;

        $this->assertRejectedBeforeWrite(static fn () => $row->{$operation}());
        self::assertSame($before, $this->writes);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM identity_mutation')->fetchColumn());
        self::assertSame($softDeleted, $this->pdo->query('SELECT deleted_at IS NOT NULL FROM identity_mutation')->fetchColumn() === 1);
    }

    /** @return array<string, array{string, bool}> */
    public static function destructiveOperations(): array
    {
        return [
            'delete' => ['delete', false],
            'force delete' => ['forceDelete', false],
            'restore' => ['restore', true],
            'refresh' => ['refresh', false],
        ];
    }

    #[DataProvider('cacheCapacities')]
    public function testFindSkipsMutatedIdentityAndPreservesDirtyState(int $capacity): void
    {
        $this->useGateway($capacity);
        [$first, $second] = [$this->row('first'), $this->row('second')];
        $first->id = $second->id;
        $first->body = 'local draft';

        if ($capacity > 0) {
            self::assertSame($second, IdentityMutationRow::find(['id' => 2]));
        }
        $before = $this->selects;
        $found = IdentityMutationRow::find(['id' => 1]);
        self::assertNotNull($found);
        self::assertNotSame($first, $found);
        self::assertSame(1, $found->id);
        self::assertSame('first', $found->body);
        self::assertSame($before + 1, $this->selects);
        if ($capacity > 0) {
            self::assertSame($found, IdentityMutationRow::find(['id' => 1]));
            self::assertSame($before + 1, $this->selects);
        }
        self::assertSame('local draft', $first->body);
        self::assertSame(['id' => 1], $this->gateway->knownIdentity($first));
        self::assertSame(2, $first->id);
        self::assertSame(0, $this->writes);
    }

    /** @return array<string, array{int}> */
    public static function cacheCapacities(): array
    {
        return ['warm' => [2], 'evicted' => [1], 'disabled' => [0]];
    }

    public function testFindSkipsUnsetCompositeKeyComponent(): void
    {
        $row = $this->compositeRow('database');
        unset($row->code);
        $row->body = 'draft';
        $before = $this->selects;

        $found = IdentityCompositeRow::find(['code' => 'a', 'tenant_id' => 1]);
        self::assertNotSame($row, $found);
        self::assertSame('a', $found?->code);
        self::assertSame('database', $found?->body);
        self::assertSame($before + 1, $this->selects);
        self::assertFalse($row->propSet('code'));
        self::assertSame('draft', $row->body);
        self::assertSame(['tenant_id' => 1, 'code' => 'a'], $this->gateway->knownIdentity($row));
    }

    public function testRelationTargetSkipsTamperedCacheEntry(): void
    {
        $row = $this->row('database');
        $this->pdo->exec('INSERT INTO identity_reference VALUES (1, 1)');
        $row->id = 99;
        $row->body = 'uncommitted draft';
        $before = $this->selects;

        $reference = IdentityReferenceRow::query()->with('target')->first();
        $target = $reference?->loadedRelation('target');
        self::assertInstanceOf(IdentityMutationRow::class, $target);
        self::assertNotSame($row, $target);
        self::assertSame(1, $target->id);
        self::assertSame('database', $target->body);
        self::assertSame($target, IdentityMutationRow::find(['id' => 1]));
        self::assertSame($before + 2, $this->selects);
        self::assertSame(99, $row->id);
        self::assertSame('uncommitted draft', $row->body);
        self::assertSame(0, $this->writes);
    }

    public function testRollbackAfterLookupRestoresTheOriginalCachedObject(): void
    {
        $row = $this->row('committed');
        try {
            IdentityMutationRow::transaction(static function () use ($row): void {
                unset($row->id);
                $row->body = 'draft';
                $found = IdentityMutationRow::find(['id' => 1]);
                self::assertNotSame($row, $found);
                self::assertSame(1, $found?->id);
                throw new \RuntimeException('rollback lookup');
            });
            self::fail('The transaction must roll back');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback lookup', $e->getMessage());
        }

        $before = $this->selects;
        self::assertSame(1, $row->id);
        self::assertSame('committed', $row->body);
        self::assertSame($row, IdentityMutationRow::find(['id' => 1]));
        self::assertSame($before, $this->selects);
    }

    #[DataProvider('smallCacheCapacities')]
    public function testRollbackDoesNotAliasAnEvictedMutatedObject(int $capacity): void
    {
        $this->useGateway($capacity);
        [$first, $second] = [$this->row('first'), $this->row('second')];
        $first->id = 2;
        try {
            IdentityMutationRow::transaction(static function (): void {
                IdentityMutationRow::find(['id' => 2]);
                throw new \LogicException('rollback unchanged write state');
            });
            self::fail('The transaction must roll back');
        } catch (\LogicException $e) {
            self::assertSame('rollback unchanged write state', $e->getMessage());
        }

        self::assertSame(['id' => 1], $this->gateway->knownIdentity($first));
        self::assertSame(['id' => 1], $first->takeOriginalPrimary());
        self::assertSame('first', $first->body);
        self::assertSame(['id' => 2], $this->gateway->knownIdentity($second));
        self::assertSame('second', IdentityMutationRow::find(['id' => 2])?->body);
    }

    /** @return array<string, array{int}> */
    public static function smallCacheCapacities(): array
    {
        return ['evicted' => [1], 'disabled' => [0]];
    }

    public function testRollbackToleratesAnEvictedUnsetCompositeKey(): void
    {
        $this->useGateway(1);
        $row = $this->compositeRow('database');
        $this->row('newer');
        unset($row->code);
        try {
            IdentityCompositeRow::transaction(static function (): void {
                throw new \LogicException('rollback unset identity');
            });
            self::fail('The transaction must roll back');
        } catch (\LogicException $e) {
            self::assertSame('rollback unset identity', $e->getMessage());
        }

        self::assertSame(['tenant_id' => 1, 'code' => 'a'], $this->gateway->knownIdentity($row));
        self::assertSame('a', IdentityCompositeRow::find(['tenant_id' => 1, 'code' => 'a'])?->code);
    }

    public function testRollbackCannotCopyTamperedPrimaryKeyToASecondInstance(): void
    {
        $row = $this->row('committed');
        $row->id = 2;
        $read = null;
        try {
            IdentityMutationRow::transaction(static function () use (&$read): void {
                $read = IdentityMutationRow::find(['id' => 1]);
                self::assertSame(1, $read?->id);
                throw new \LogicException('rollback before-tampered identity');
            });
            self::fail('The transaction must roll back');
        } catch (\LogicException $e) {
            self::assertSame('rollback before-tampered identity', $e->getMessage());
        }

        self::assertNotSame($row, $read);
        self::assertSame(1, $read?->id);
        self::assertSame(1, $row->id);
        self::assertSame($row, IdentityMutationRow::find(['id' => 1]));
    }

    public function testRefreshAfterLookupKeepsOriginalIdentityValidation(): void
    {
        [$first, $second] = [$this->row('first'), $this->row('second')];
        $first->id = $second->id;
        $first->body = 'local draft';
        self::assertNotSame($first, IdentityMutationRow::find(['id' => 1]));
        $before = $this->selects;

        $this->assertRejectedBeforeWrite(static fn () => $first->refresh());
        self::assertSame($before, $this->selects);
        self::assertSame('local draft', $first->body);
        self::assertSame($second, IdentityMutationRow::find(['id' => 2]));

        $first->id = 1;
        self::assertSame($first, $first->refresh());
        self::assertSame('first', $first->body);
        self::assertSame($first, IdentityMutationRow::find(['id' => 1]));
        self::assertSame($before + 1, $this->selects);
    }

    #[DataProvider('cacheCapacities')]
    public function testDeleteAndReinsertWithDifferentKeyRestoresOriginalIdentityOnRollback(int $capacity): void
    {
        $this->useGateway($capacity);
        $row = $this->row('committed');
        try {
            IdentityMutationRow::transaction(function () use ($row, $capacity): void {
                $row->forceDelete();
                unset($row->id);
                $row->body = 'replacement';
                $row->save();
                self::assertSame(['id' => 2], $this->gateway->knownIdentity($row));
                self::assertNull(IdentityMutationRow::find(['id' => 1]));
                $found = IdentityMutationRow::find(['id' => 2]);
                self::assertSame('replacement', $found?->body);
                if ($capacity > 0) {
                    self::assertSame($row, $found);
                }
                throw new \LogicException('rollback new identity');
            });
            self::fail('The transaction must roll back');
        } catch (\LogicException $e) {
            self::assertSame('rollback new identity', $e->getMessage());
        }

        self::assertSame(['id' => 1], $this->gateway->knownIdentity($row));
        self::assertSame(1, $row->id);
        self::assertSame('committed', $row->body);
        $found = IdentityMutationRow::find(['id' => 1]);
        self::assertSame('committed', $found?->body);
        if ($capacity > 0) {
            self::assertSame($row, $found);
        }
        self::assertNull(IdentityMutationRow::find(['id' => 2]));
    }

    public function testCompositeKeyMutationIsRejectedBeforeSql(): void
    {
        IdentityCompositeRow::insertAll([
            ['tenant_id' => 1, 'code' => 'a', 'body' => 'first'],
            ['tenant_id' => 1, 'code' => 'b', 'body' => 'second'],
        ]);
        $row = IdentityCompositeRow::find(['tenant_id' => 1, 'code' => 'a']);
        self::assertNotNull($row);
        $row->code = 'b';
        $row->body = 'corrupt';

        $this->assertRejectedBeforeWrite(static fn () => $row->save());
        self::assertSame(['first', 'second'], $this->pdo->query('SELECT body FROM identity_composite ORDER BY code')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testBeforeSaveAndBeforeUpdateCannotMutateKeyAfterInitialValidation(): void
    {
        $row = $this->row('hooked');
        $row->mutateKeyOnSave = true;

        $this->assertRejectedBeforeWrite(static fn () => $row->save());
        self::assertSame(['hooked'], $this->bodies());
    }

    public function testBeforeDeleteCannotMutateKeyAfterInitialValidation(): void
    {
        $row = $this->row('hooked');
        $row->mutateKeyOnDelete = true;

        $this->assertRejectedBeforeWrite(static fn () => $row->forceDelete());
        self::assertSame(['hooked'], $this->bodies());
    }

    public function testTransactionRollbackRestoresRejectedMutationAndPersistedIdentitySnapshot(): void
    {
        $row = $this->row('rollback');
        try {
            IdentityMutationRow::transaction(static function () use ($row): void {
                $row->id = 999;
                $row->save();
            });
            self::fail('Changed key must reject the transaction');
        } catch (IdentityException) {
        }

        self::assertSame(1, $row->id);
        self::assertSame(1, $this->gateway->knownIdentity($row)['id']);
        $row->body = 'retry';
        $row->save();
        self::assertSame(['retry'], $this->bodies());
        self::assertSame(1, $this->gateway->knownIdentity($row)['id']);
    }

    public function testAfterInsertCannotPublishGeneratedKeyMutationAsCacheIdentity(): void
    {
        $row = new IdentityMutationRow();
        $row->body = 'inserted';
        $row->mutateKeyAfterInsert = true;

        try {
            $row->save();
            self::fail('An after-insert primary-key mutation must not become the persisted cache identity');
        } catch (IdentityException $e) {
            self::assertStringContainsString('primary key', $e->getMessage());
        }

        self::assertSame(1, $row->takeOriginalPrimary()['id']);
        self::assertSame(1, $this->gateway->knownIdentity($row)['id']);
        self::assertSame(['inserted'], $this->bodies());
    }

    public function testDeletedEntityCanBeReinsertedWithItsNextGeneratedIdentity(): void
    {
        $row = $this->row('reinsert');
        $row->forceDelete();
        unset($row->id);
        $row->save();

        self::assertSame(2, $row->id);
        self::assertSame(['id' => 2], $this->gateway->knownIdentity($row));
        self::assertSame($row, IdentityMutationRow::find(['id' => 2]));
        self::assertSame(['reinsert'], $this->bodies());
    }

    public function testAfterUpdateCannotPublishNaturalKeyMutationAsCacheIdentity(): void
    {
        $row = $this->compositeRow('original');
        $row->body = 'updated';
        $row->mutateKeyAfterUpdate = true;

        try {
            $row->save();
            self::fail('An after-update primary-key mutation must not become the persisted cache identity');
        } catch (IdentityException $e) {
            self::assertStringContainsString('primary key', $e->getMessage());
        }

        self::assertSame('a', $row->takeOriginalPrimary()['code']);
        self::assertSame('a', $this->gateway->knownIdentity($row)['code']);
        self::assertSame(['updated'], $this->pdo->query('SELECT body FROM identity_composite')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAfterDeleteKeyMutationIsRejectedWithoutCachingDeletedEntityUnderAnotherIdentity(): void
    {
        $row = $this->row('delete-hook');
        $row->mutateKeyAfterDelete = true;

        try {
            $row->forceDelete();
            self::fail('An after-delete primary-key mutation must be rejected');
        } catch (IdentityException $e) {
            self::assertStringContainsString('primary key', $e->getMessage());
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM identity_mutation')->fetchColumn());
        // 已交付对象的弱身份历史可保留供事务恢复，但绝不能被改成钩子伪造的新主键。
        self::assertSame(['id' => 1], $this->gateway->knownIdentity($row));
        self::assertNull(IdentityMutationRow::find(['id' => 999]));
        self::assertTrue($row->isNew());
    }

    public function testOuterRollbackRestoresOriginalIdentityAfterRejectedAfterUpdateMutation(): void
    {
        $row = $this->compositeRow('before');
        $row->mutateKeyAfterUpdate = true;

        try {
            IdentityCompositeRow::transaction(static function () use ($row): void {
                $row->body = 'after';
                $row->save();
            });
            self::fail('The after-update mutation must roll back the transaction');
        } catch (IdentityException) {
        }

        self::assertSame(['before'], $this->pdo->query('SELECT body FROM identity_composite')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('a', $row->code);
        self::assertSame('a', $row->takeOriginalPrimary()['code']);
        self::assertSame('a', $this->gateway->knownIdentity($row)['code']);
        $row->mutateKeyAfterUpdate = false;
        $row->body = 'retry';
        $row->save();
        self::assertSame(['retry'], $this->pdo->query('SELECT body FROM identity_composite')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testValidUnchangedPrimaryKeyStillUpdatesNormally(): void
    {
        $row = $this->row('before');
        $row->save();
        $row->body = 'after';
        $row->save();
        self::assertSame(['after'], $this->bodies());
    }

    private function compositeRow(string $body): IdentityCompositeRow
    {
        IdentityCompositeRow::insertAll([['tenant_id' => 1, 'code' => 'a', 'body' => $body]]);
        $row = IdentityCompositeRow::find(['tenant_id' => 1, 'code' => 'a']);
        self::assertNotNull($row);

        return $row;
    }

    private function assertRejectedBeforeWrite(callable $operation): void
    {
        $before = $this->writes;
        try {
            $operation();
            self::fail('A persisted primary-key mutation must be rejected');
        } catch (IdentityException $e) {
            self::assertStringContainsString('primary key', $e->getMessage());
        }
        self::assertSame($before, $this->writes, 'Primary-key rejection must precede SQL');
    }

    private function row(string $body): IdentityMutationRow
    {
        $row = new IdentityMutationRow();
        $row->body = $body;
        $row->save();
        $this->writes = 0;

        return $row;
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return $this->pdo->query('SELECT body FROM identity_mutation ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}

final class IdentityMutationRow extends Entity
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $body;

    #[Column, SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public bool $mutateKeyOnSave = false;
    public bool $mutateKeyOnDelete = false;
    public bool $mutateKeyAfterInsert = false;
    public bool $mutateKeyAfterDelete = false;

    public static function table(): string { return 'identity_mutation'; }

    public function beforeUpdate(): void
    {
        if ($this->mutateKeyOnSave) {
            $this->id = 999;
        }
    }

    public function beforeDelete(): void
    {
        if ($this->mutateKeyOnDelete) {
            $this->id = 999;
        }
    }

    public function afterInsert(): void
    {
        if ($this->mutateKeyAfterInsert) {
            $this->id = 999;
        }
    }

    public function afterDelete(): void
    {
        if ($this->mutateKeyAfterDelete) {
            $this->id = 999;
        }
    }
}

final class IdentityReferenceRow extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    #[Column]
    public int $target_id;

    public static function table(): string { return 'identity_reference'; }

    #[BelongsToAttribute(target: IdentityMutationRow::class, localKey: ['target_id'])]
    public function target(): BelongsTo { return $this->makeRelation(); }
}

final class IdentityCompositeRow extends Entity
{
    #[Column, PrimaryKey(order: 1)]
    public int $tenant_id;

    #[Column, PrimaryKey(order: 2)]
    public string $code;

    #[Column]
    public string $body;

    public bool $mutateKeyAfterUpdate = false;

    public static function table(): string { return 'identity_composite'; }

    public function afterUpdate(): void
    {
        if ($this->mutateKeyAfterUpdate) {
            $this->code = 'b';
        }
    }
}
