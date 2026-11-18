<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\BelongsTo as BelongsToAttribute;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\HasMany as HasManyAttribute;
use Pixiaoka\Orm\Annotation\HasOne as HasOneAttribute;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Relation\HasOne;
use Pixiaoka\Orm\Session;

final class OrmRelationMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        Session::discard();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE duplicate_relation_record (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');
        Session::use(new EntityGateway(new PdoConnection($pdo)));
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    /** @return array<string, array{class-string<Entity>, string, string}> */
    public static function duplicateRelations(): array
    {
        return [
            'HasOne' => [DuplicateHasOneRecord::class, 'children', 'hasOne'],
            'HasMany' => [DuplicateHasManyRecord::class, 'children', 'hasMany'],
            'BelongsTo' => [DuplicateBelongsToRecord::class, 'children', 'belongsTo'],
        ];
    }

    #[DataProvider('duplicateRelations')]
    public function testSameKindDuplicateIsMetadataException(
        string $class,
        string $method,
        string $kind,
    ): void {
        try {
            (new MetadataReader())->relations($class);
            self::fail('同类重复关系必须在实例化注解前拒绝');
        } catch (MetadataException $exception) {
            self::assertStringContainsString($class . '::' . $method, $exception->getMessage());
            self::assertStringContainsString($kind, $exception->getMessage());
        }
    }

    public function testMixedRelationAttributesAreMetadataException(): void
    {
        try {
            (new MetadataReader())->relations(MixedRelationRecord::class);
            self::fail('混合关系注解必须拒绝');
        } catch (MetadataException $exception) {
            self::assertStringContainsString(MixedRelationRecord::class . '::children', $exception->getMessage());
            self::assertStringContainsString('hasOne', $exception->getMessage());
            self::assertStringContainsString('hasMany', $exception->getMessage());
            self::assertStringContainsString('belongsTo', $exception->getMessage());
        }
    }

    public function testCrudDoesNotScanInvalidRelationDeclarations(): void
    {
        $record = new DuplicateHasManyRecord();
        $record->name = 'before';
        $record->save();

        self::assertSame(1, $record->id);
        $found = DuplicateHasManyRecord::find(['id' => $record->id]);
        self::assertInstanceOf(DuplicateHasManyRecord::class, $found);

        $found->name = 'after';
        $found->save();
        self::assertSame('after', $found->refresh()->name);
        self::assertSame('after', DuplicateHasManyRecord::query()->where('id', $record->id)->value('name'));

        $found->delete();
        self::assertNull(DuplicateHasManyRecord::find(['id' => $record->id]));
    }
}

final class RelationMetadataTarget extends Entity
{
    #[Column, PrimaryKey]
    public int $id;

    public static function table(): string
    {
        return 'relation_metadata_target';
    }
}

final class DuplicateHasOneRecord extends Entity
{
    use DuplicateRelationRecord;

    #[HasOneAttribute(RelationMetadataTarget::class)]
    #[HasOneAttribute(RelationMetadataTarget::class)]
    public function children(): HasOne
    {
        return $this->makeRelation();
    }
}

final class DuplicateHasManyRecord extends Entity
{
    use DuplicateRelationRecord;

    #[HasManyAttribute(RelationMetadataTarget::class)]
    #[HasManyAttribute(RelationMetadataTarget::class)]
    public function children(): HasMany
    {
        return $this->makeRelation();
    }
}

final class DuplicateBelongsToRecord extends Entity
{
    use DuplicateRelationRecord;

    #[BelongsToAttribute(RelationMetadataTarget::class)]
    #[BelongsToAttribute(RelationMetadataTarget::class)]
    public function children(): BelongsTo
    {
        return $this->makeRelation();
    }
}

final class MixedRelationRecord extends Entity
{
    use DuplicateRelationRecord;

    #[HasOneAttribute(RelationMetadataTarget::class)]
    #[HasManyAttribute(RelationMetadataTarget::class)]
    #[BelongsToAttribute(RelationMetadataTarget::class)]
    public function children(): HasOne
    {
        return $this->makeRelation();
    }
}

trait DuplicateRelationRecord
{
    #[Column, PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $name;

    public static function table(): string
    {
        return 'duplicate_relation_record';
    }
}
