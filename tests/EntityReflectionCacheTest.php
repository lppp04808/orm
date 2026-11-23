<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Session;

class ReflectionStateRecord extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column]
    public string $body;

    #[Column]
    public ?string $note = null;

    public static function table(): string
    {
        return 'entity_reflection_state';
    }
}

final class ReflectionInheritedStateRecord extends ReflectionStateRecord
{
}

final class EntityReflectionCacheTest extends TestCase
{
    private EntityGateway $gateway;

    protected function setUp(): void
    {
        Session::discard();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE entity_reflection_state (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, note TEXT NULL)');
        $this->gateway = new EntityGateway(new PdoConnection($pdo));
        Session::use($this->gateway);
    }

    protected function tearDown(): void
    {
        Session::discard();
    }

    public function testPropertyStatesAndCachedReflectionAreIndependentOfEntityInstances(): void
    {
        $first = new ReflectionStateRecord();
        $second = new ReflectionStateRecord();
        self::assertFalse($first->propSet('id'));
        self::assertFalse($first->propSet('body'));
        self::assertTrue($first->propSet('note'));
        self::assertNull($first->note);

        $first->body = 'first';
        $first->note = 'assigned';
        $second->body = 'second';
        self::assertTrue($first->propSet('body'));
        self::assertSame('first', $first->body);
        self::assertSame('assigned', $first->note);
        self::assertSame('second', $second->body);
        self::assertNull($second->note);

        $method = new \ReflectionMethod(Entity::class, 'reflectionProperty');
        $cached = $method->invoke($first, 'body');
        self::assertSame($cached, $method->invoke($first, 'body'));
        self::assertSame($cached, $method->invoke($second, 'body'));
        self::assertSame('second', $second->body);
    }

    public function testRollbackRestoresUninitializedPropertiesAcrossRepeatedEntityStates(): void
    {
        $first = new ReflectionStateRecord();
        $first->body = 'first';
        $second = new ReflectionStateRecord();
        $second->body = 'second';
        $second->note = 'second note';
        $method = new \ReflectionMethod(Entity::class, 'reflectionProperty');
        $cached = $method->invoke($first, 'id');
        self::assertFalse($first->propSet('id'));
        self::assertFalse($second->propSet('id'));

        try {
            ReflectionStateRecord::transaction(static function () use ($first, $second): void {
                $first->save();
                $second->save();
                self::assertTrue($first->propSet('id'));
                self::assertTrue($second->propSet('id'));
                throw new \RuntimeException('rollback reflection state');
            });
            self::fail('事务必须回滚');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback reflection state', $e->getMessage());
        }

        self::assertFalse($first->propSet('id'));
        self::assertFalse($second->propSet('id'));
        self::assertSame('first', $first->body);
        self::assertSame('second', $second->body);
        self::assertNull($first->note);
        self::assertSame('second note', $second->note);
        self::assertSame($cached, $method->invoke($first, 'id'));
        self::assertSame($cached, $method->invoke($second, 'id'));
    }

    public function testInheritedEntitiesUseTheirActualClassCacheKey(): void
    {
        $parent = new ReflectionStateRecord();
        $child = new ReflectionInheritedStateRecord();
        $parent->body = 'parent';
        $child->body = 'child';
        self::assertTrue($parent->propSet('body'));
        self::assertTrue($child->propSet('body'));

        $method = new \ReflectionMethod(Entity::class, 'reflectionProperty');
        $parentReflection = $method->invoke($parent, 'body');
        $childReflection = $method->invoke($child, 'body');
        self::assertNotSame($parentReflection, $childReflection);
        self::assertSame($childReflection, $method->invoke($child, 'body'));
        self::assertSame('child', $child->body);
        self::assertSame('parent', $parent->body);
    }

    public function testMetadataArrayExportImportRemainsSerializableAfterReflectionCacheUse(): void
    {
        $entity = new ReflectionStateRecord();
        self::assertFalse($entity->propSet('id'));
        $reader = new MetadataReader();
        $reader->read(ReflectionStateRecord::class);
        $reader->read(ReflectionInheritedStateRecord::class);
        $exported = $reader->export();

        $serialized = serialize($exported);
        self::assertStringNotContainsString('ReflectionProperty', $serialized);
        $restored = unserialize($serialized, ['allowed_classes' => false]);
        self::assertSame($exported, $restored);
        $php = var_export($exported, true);
        $evaluated = eval('return ' . $php . ';');
        self::assertSame($exported, $evaluated);

        $imported = new MetadataReader($restored);
        $meta = $imported->read(ReflectionStateRecord::class);
        self::assertInstanceOf(\ReflectionNamedType::class, $meta->field('id')->type);
        self::assertTrue($meta->field('note')->nullable());
        self::assertSame($exported, $imported->export());
    }
}
