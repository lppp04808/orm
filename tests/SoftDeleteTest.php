<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Relation\HasMany as HasManyRelation;
use Pixiaoka\Orm\Session;

final class SoftDeleteEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string
    {
        return 'soft_rows';
    }

    #[HasMany(target: SoftDeleteChild::class, localKey: ['id'], foreignKey: ['parent_id'])]
    public function children(): HasManyRelation
    {
        return $this->makeRelation();
    }
}

final class SoftDeleteChild extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public int $parent_id;

    #[Column]
    public string $name;

    #[Column]
    #[SoftDelete]
    public ?DateTimeImmutable $removed_at = null;

    public static function table(): string
    {
        return 'soft_children';
    }

    #[BelongsTo(target: SoftDeleteEntity::class, localKey: ['parent_id'], foreignKey: ['id'])]
    public function parent(): \Pixiaoka\Orm\Relation\BelongsTo
    {
        return $this->makeRelation();
    }
}

final class InvalidSoftDeleteEntity extends Entity
{
    #[Column]
    #[PrimaryKey]
    public int $id;

    #[Column]
    #[SoftDelete]
    public ?int $deleted_at = null;

    public static function table(): string
    {
        return 'invalid_soft';
    }
}

final class SoftDeleteTest extends TestCase
{
    private PDO $pdo;
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE soft_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE soft_children (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, name TEXT NOT NULL, removed_at TEXT NULL)');
        $this->gateway = new EntityGateway(new PdoConnection($this->pdo));
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testEntitySoftDeleteFindRestoreAndForceDelete(): void
    {
        $row = $this->row('first');
        $row->delete();

        self::assertFalse($row->isNew());
        self::assertInstanceOf(DateTimeImmutable::class, $row->deleted_at);
        self::assertNull(SoftDeleteEntity::find(['id' => $row->id]));
        self::assertSame([], SoftDeleteEntity::query()->get());
        self::assertSame(0, SoftDeleteEntity::query()->count());
        self::assertSame(1, SoftDeleteEntity::query()->withTrashed()->count());
        self::assertSame(1, SoftDeleteEntity::query()->onlyTrashed()->count());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM soft_rows')->fetchColumn());

        $trashed = SoftDeleteEntity::query()->onlyTrashed()->first();
        self::assertNotNull($trashed);
        $trashed->restore();
        self::assertNull($trashed->deleted_at);
        self::assertSame($trashed, SoftDeleteEntity::find(['id' => $row->id]));
        self::assertSame(1, SoftDeleteEntity::query()->count());

        $trashed->delete();
        $trashed->forceDelete();
        self::assertTrue($trashed->isNew());
        self::assertSame(0, SoftDeleteEntity::query()->withTrashed()->count());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM soft_rows')->fetchColumn());
    }

    public function testBulkDeleteRestoreAndForceDeleteRespectScope(): void
    {
        $active = $this->row('active');
        $other = $this->row('other');
        self::assertSame(1, SoftDeleteEntity::query()->where('name', 'active')->delete());
        self::assertSame(0, SoftDeleteEntity::query()->where('name', 'active')->delete());
        self::assertNull(SoftDeleteEntity::find(['id' => $active->id]));
        self::assertSame($other, SoftDeleteEntity::find(['id' => $other->id]));
        self::assertSame(1, SoftDeleteEntity::query()->onlyTrashed()->count());

        self::assertSame(1, SoftDeleteEntity::query()->where('name', 'active')->restore());
        self::assertNull($active->deleted_at);
        self::assertSame($active, SoftDeleteEntity::find(['id' => $active->id]));
        self::assertNull($other->deleted_at);
        self::assertSame(2, SoftDeleteEntity::query()->count());
        self::assertSame(0, SoftDeleteEntity::query()->where('name', 'active')->restore());

        $active = SoftDeleteEntity::find(['id' => $active->id]);
        self::assertNotNull($active);
        $active->delete();
        self::assertSame(1, SoftDeleteEntity::query()->onlyTrashed()->forceDelete());
        self::assertSame(1, SoftDeleteEntity::query()->withTrashed()->count());
    }

    public function testSoftDeleteTimestampIsSecondPrecisionAndMatchesDatabaseRefresh(): void
    {
        $single = $this->row('single-precision');
        $single->delete();
        $singleDeletedAt = $single->deleted_at;
        self::assertInstanceOf(DateTimeImmutable::class, $singleDeletedAt);
        self::assertSame('000000', $singleDeletedAt->format('u'));
        self::assertSame('UTC', $singleDeletedAt->getTimezone()->getName());
        $single->refresh();
        self::assertSame($singleDeletedAt->format('Y-m-d H:i:s.u e'), $single->deleted_at->format('Y-m-d H:i:s.u e'));

        $bulk = $this->row('bulk-precision');
        self::assertSame(1, SoftDeleteEntity::query()->where('id', $bulk->id)->delete());
        $bulkDeletedAt = $bulk->deleted_at;
        self::assertInstanceOf(DateTimeImmutable::class, $bulkDeletedAt);
        self::assertSame('000000', $bulkDeletedAt->format('u'));
        self::assertSame('UTC', $bulkDeletedAt->getTimezone()->getName());
        $bulk->refresh();
        self::assertSame($bulkDeletedAt->format('Y-m-d H:i:s.u e'), $bulk->deleted_at->format('Y-m-d H:i:s.u e'));
    }

    public function testDefaultFilterIsInsideWhereSubqueriesAndRelations(): void
    {
        $parent = $this->row('parent');
        $child = new SoftDeleteChild();
        $child->parent_id = $parent->id;
        $child->name = 'child';
        $child->save();
        $child->delete();

        $default = SoftDeleteEntity::query()->where('id', $parent->id)->build();
        self::assertStringContainsString('"deleted_at" IS NULL', $default['sql']);
        self::assertSame([], SoftDeleteEntity::query()->with('children')->first()?->loadedRelation('children'));
        self::assertSame(0, SoftDeleteEntity::query()->whereHas('children')->count());
        self::assertSame(0, SoftDeleteChild::query()->whereRelation('parent', 'id', $parent->id)->count());

        $parent->delete();
        self::assertSame(0, SoftDeleteChild::query()->whereHas('parent')->count());
        self::assertSame(1, SoftDeleteChild::query()->onlyTrashed()->whereHas(
            'parent',
            static fn ($query) => $query->withTrashed(),
        )->count());
    }

    public function testSoftDeleteTransactionRollbackRestoresEntityAndVisibility(): void
    {
        $row = $this->row('rollback');
        try {
            SoftDeleteEntity::transaction(static function () use ($row): void {
                $row->delete();
                self::assertNotNull($row->deleted_at);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertNull($row->deleted_at);
        self::assertSame($row, SoftDeleteEntity::find(['id' => $row->id]));
        self::assertSame(1, SoftDeleteEntity::query()->count());
    }

    public function testSoftDeletedEntityCannotBeDeletedAgain(): void
    {
        $row = $this->row('duplicate');
        $row->delete();
        $this->expectException(IdentityException::class);
        $row->delete();
    }

    public function testMetadataRejectsNonTemporalSoftDelete(): void
    {
        $this->expectException(MetadataException::class);
        (new MetadataReader())->read(InvalidSoftDeleteEntity::class);
    }

    private function row(string $name): SoftDeleteEntity
    {
        $row = new SoftDeleteEntity();
        $row->name = $name;
        $row->save();

        return $row;
    }
}
