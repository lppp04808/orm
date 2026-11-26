<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\HasOne as HasOneAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Relation\HasOne;
use Pixiaoka\Orm\Session;

final class BoundaryParent extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    #[Column]
    public int $tenant_id;
    #[Column]
    public int $enabled;
    #[Column, SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string { return 'orm_batch_boundary_parent'; }

    #[HasOneAttribute(BoundaryChild::class, foreignKey: ['parent_id'])]
    public function preferred(): HasOne { return $this->makeRelation(); }

    #[HasManyAttribute(BoundaryChild::class, foreignKey: ['parent_id'])]
    public function children(): HasMany { return $this->makeRelation(); }

    #[HasOneAttribute(BoundaryChild::class, localKey: ['tenant_id', 'id'], foreignKey: ['tenant_id', 'parent_id'])]
    public function tenantPreferred(): HasOne { return $this->makeRelation(); }

    #[HasManyAttribute(BoundaryChild::class, localKey: ['tenant_id', 'id'], foreignKey: ['tenant_id', 'parent_id'])]
    public function tenantChildren(): HasMany { return $this->makeRelation(); }
}

final class BoundaryChild extends Entity
{
    #[Column, PrimaryKey]
    public int $id;
    #[Column]
    public int $tenant_id;
    #[Column]
    public ?int $parent_id = null;
    #[Column]
    public int $enabled;
    #[Column, SoftDelete]
    public ?DateTimeImmutable $deleted_at = null;

    public static function table(): string { return 'orm_batch_boundary_child'; }

    #[BelongsToAttribute(BoundaryParent::class, localKey: ['parent_id'])]
    public function parent(): BelongsTo { return $this->makeRelation(); }

    #[BelongsToAttribute(BoundaryParent::class, localKey: ['tenant_id', 'parent_id'], foreignKey: ['tenant_id', 'id'])]
    public function tenantParent(): BelongsTo { return $this->makeRelation(); }
}

final class RelationBatchBoundaryTest extends TestCase
{
    public static function limits(): iterable
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            foreach ([500, 900, 999, 1000, 1001, 2000] as $size) {
                yield "$driver-$size" => [$driver, $size];
            }
        }
    }

    #[DataProvider('limits')]
    public function testAllRelationKindsRespectBatchBoundaries(string $driver, int $size): void
    {
        $pdo = $this->connect($driver);
        $pdo->exec('CREATE TEMPORARY TABLE orm_batch_boundary_parent (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, enabled INTEGER NOT NULL, deleted_at VARCHAR(30))');
        $pdo->exec('CREATE TEMPORARY TABLE orm_batch_boundary_child (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, parent_id INTEGER, enabled INTEGER NOT NULL, deleted_at VARCHAR(30))');
        $pdo->exec('CREATE INDEX orm_batch_boundary_fk ON orm_batch_boundary_child (parent_id, tenant_id)');
        foreach (array_chunk(range(1, $size), 100) as $chunk) {
            $parents = [];
            $children = [];
            foreach ($chunk as $id) {
                $tenant = 1 + ($id % 2);
                $parents[] = "($id, $tenant, 1, NULL)";
                $base = $id * 10;
                $children[] = "($base, $tenant, $id, 1, NULL)";
                $children[] = '(' . ($base + 1) . ", $tenant, $id, 1, NULL)";
            }
            $pdo->exec('INSERT INTO orm_batch_boundary_parent VALUES ' . implode(', ', $parents));
            $pdo->exec('INSERT INTO orm_batch_boundary_child VALUES ' . implode(', ', $children));
        }
        // Purposely put wrong-tenant, filtered, deleted and NULL-key targets first in the sort.
        $pdo->exec("INSERT INTO orm_batch_boundary_child VALUES (12, 1, 1, 1, NULL), (13, 2, 1, 0, NULL), (14, 2, 1, 1, '2026-01-01 00:00:00'), (15, 2, NULL, 1, NULL)");
        $pdo->exec("INSERT INTO orm_batch_boundary_parent VALUES (9100, 2, 1, '2026-01-01 00:00:00'), (9200, 2, 0, NULL)");
        $pdo->exec('INSERT INTO orm_batch_boundary_child VALUES (91000, 2, 9100, 1, NULL), (92000, 2, 9200, 1, NULL)');

        $gateway = new EntityGateway(new PdoConnection($pdo));
        $selects = [];
        $gateway->subscribe(static function (Event $event) use (&$selects): void {
            if ($event instanceof SqlEvent && $event->operation === 'SELECT') {
                $selects[] = $event;
            }
        });
        Session::scope($gateway, static function () use ($size, &$selects): void {
            foreach ([false, true] as $composite) {
                $many = $composite ? 'tenantChildren' : 'children';
                $one = $composite ? 'tenantPreferred' : 'preferred';
                $owner = $composite ? 'tenantParent' : 'parent';
                $batchSize = $composite ? 500 : 1000;
                $batches = intdiv($size + $batchSize - 1, $batchSize);
                $constraint = static fn (Builder $query): Builder => $query->where('enabled', 1)->orderBy('id', 'DESC');
                $selects = [];
                $parents = BoundaryParent::query()->whereOp('id', '<=', $size)->orderBy('id')->with([
                    $many => $constraint,
                    $one => $constraint,
                    "$many.$owner" => $constraint,
                ])->get();
                self::assertCount(1 + 3 * $batches, $selects);
                self::assertCount($size, $parents);
                foreach ($selects as $select) {
                    // Each key value is bound once in the filter and once in the match projection.
                    self::assertLessThanOrEqual(2001, count($select->parameterNames));
                }
                foreach ($parents as $index => $parent) {
                    $id = $index + 1;
                    $children = $parent->loadedRelation($many);
                    $expected = [$id * 10 + 1, $id * 10];
                    if ($id === 1 && !$composite) {
                        array_unshift($expected, 12);
                        // The out-of-tenant child adds no new single-column parent key.
                    }
                    self::assertSame($id, $parent->id);
                    self::assertSame($expected, array_column($children, 'id'));
                    self::assertSame($children[0], $parent->loadedRelation($one));
                    foreach ($children as $child) {
                        self::assertSame($parent, $child->loadedRelation($owner));
                    }
                }
                $orphans = BoundaryChild::query()->whereIn('id', [15, 91000, 92000])->with([$owner => $constraint])->get();
                self::assertCount(3, $orphans);
                foreach ($orphans as $orphan) {
                    self::assertNull($orphan->loadedRelation($owner));
                }
            }
        });
    }

    private function connect(string $driver): PDO
    {
        if ($driver === 'sqlite') {
            return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        $prefix = 'PIXIAOKA_ORM_LIVE_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('未配置 ' . $prefix . '_DSN');
        }

        return new PDO($dsn, getenv($prefix . '_USER') ?: null, getenv($prefix . '_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
