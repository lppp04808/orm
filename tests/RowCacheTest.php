<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Entity;

class RowCacheFirstEntity extends Entity
{
    public static function table(): string
    {
        return 'cache_first';
    }
}

final class RowCacheSecondEntity extends Entity
{
    public static function table(): string
    {
        return 'cache_second';
    }
}

final class RowCacheInheritedEntity extends RowCacheFirstEntity
{
}

final class RowCacheTest extends TestCase
{
    public function testIdentityOperationsAreIsolatedByExactModelClass(): void
    {
        $cache = new RowCache();
        $first = $this->persisted(new RowCacheFirstEntity());
        $second = $this->persisted(new RowCacheSecondEntity());
        $cache->set($first, ['id' => 1]);
        $cache->set($second, ['id' => 1]);

        self::assertSame([['id' => 1]], $cache->identities(RowCacheFirstEntity::class));
        self::assertSame([['id' => 1]], $cache->identities(RowCacheSecondEntity::class));

        $cache->clearDeleted(RowCacheFirstEntity::class, [['id' => 1]]);

        self::assertTrue($first->isNew());
        self::assertFalse($second->isNew(), '相同主键的其他模型不能被误标为已删除');
        self::assertSame([['id' => 1]], $cache->identities(RowCacheSecondEntity::class));
    }

    public function testIdentitiesDeduplicatesMultipleInstancesOfSameRow(): void
    {
        $cache = new RowCache();
        $first = $this->persisted(new RowCacheFirstEntity());
        $duplicate = $this->persisted(new RowCacheFirstEntity());
        $cache->set($first, ['tenant_id' => 7, 'id' => 1]);
        $cache->set($duplicate, ['id' => 1, 'tenant_id' => 7]);

        self::assertSame([['id' => 1, 'tenant_id' => 7]], $cache->identities(RowCacheFirstEntity::class));
    }

    public function testReadRefreshesLruAndDuplicateWriteDoesNotDuplicateNodes(): void
    {
        $cache = new RowCache(2);
        $first = $this->persisted(new RowCacheFirstEntity());
        $second = $this->persisted(new RowCacheFirstEntity());
        $third = $this->persisted(new RowCacheFirstEntity());
        $cache->set($first, ['id' => 1]);
        $cache->set($second, ['id' => 2]);

        self::assertSame($first, $cache->get(RowCacheFirstEntity::class, ['id' => 1]));
        $cache->set($third, ['id' => 3]);

        self::assertNull($cache->get(RowCacheFirstEntity::class, ['id' => 2]));
        self::assertSame([$first, $third], array_values($cache->snapshot()));

        $replacement = $this->persisted(new RowCacheFirstEntity());
        $cache->set($replacement, ['id' => 3]);
        self::assertSame([$first, $replacement], array_values($cache->snapshot()));

        $snapshot = $cache->transactionSnapshot();
        self::assertSame(['items', 'identities'], array_keys($snapshot));
        $cache->set($this->persisted(new RowCacheFirstEntity()), ['id' => 4]);
        $cache->restoreSnapshot($snapshot);
        self::assertSame([$first, $replacement], array_values($cache->snapshot()));
        self::assertSame($replacement, $cache->get(RowCacheFirstEntity::class, ['id' => 3]));
    }

    public function testZeroCapacityStillTracksKnownIdentitiesWithoutLruEntries(): void
    {
        $cache = new RowCache(0);
        $first = $this->persisted(new RowCacheFirstEntity());
        $second = $this->persisted(new RowCacheSecondEntity());
        $cache->set($first, ['id' => 1]);
        $cache->set($second, ['id' => 1]);

        self::assertSame([], $cache->snapshot());
        self::assertNull($cache->get(RowCacheFirstEntity::class, ['id' => 1]));
        self::assertSame([['id' => 1]], $cache->identities(RowCacheFirstEntity::class));
        self::assertSame(['id' => 1], $cache->knownIdentity($first));

        $cache->clearDeleted(RowCacheFirstEntity::class, [['id' => 1]]);
        self::assertTrue($first->isNew());
        self::assertFalse($second->isNew());
    }

    public function testKnownEntitiesUsesExactModelClassAndEmptyDeleteIsNoOp(): void
    {
        $cache = new RowCache();
        $base = $this->persisted(new RowCacheFirstEntity());
        $inherited = $this->persisted(new RowCacheInheritedEntity());
        $cache->set($base, ['id' => 1]);
        $cache->set($inherited, ['id' => 2]);

        self::assertSame([$base], $cache->knownEntities(RowCacheFirstEntity::class));
        self::assertSame([$inherited], $cache->knownEntities(RowCacheInheritedEntity::class));
        $cache->clearDeleted(RowCacheFirstEntity::class, []);
        self::assertFalse($base->isNew());
        self::assertFalse($inherited->isNew());
    }

    public function testNegativeCapacityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RowCache(-1);
    }

    private function persisted(Entity $entity): Entity
    {
        $state = new \ReflectionProperty(Entity::class, 'ormNew');
        $state->setValue($entity, false);

        return $entity;
    }
}
