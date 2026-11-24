<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PHPUnit\Framework\TestCase;
use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Cast\Castable;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Metadata\MetadataReader;

enum CachedStringStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final readonly class CachedMoney implements Castable
{
    public function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('金额不能为负数');
        }
    }

    public function toDatabase(): int
    {
        return $this->cents;
    }

    public static function fromDatabase(int|float|string $value): static
    {
        if (!is_int($value) && preg_match('/^\d+$/D', (string) $value) !== 1) {
            throw new \InvalidArgumentException('金额格式错误');
        }

        return new self((int) $value);
    }
}

final class CachedCastEntity extends Entity
{
    #[Column]
    #[PrimaryKey(generated: true)]
    public int $id;

    #[Column(name: 'state')]
    public CachedStringStatus $status = CachedStringStatus::Draft;

    #[Column]
    public CachedMoney $price;

    #[Column]
    public array $payload = [];

    #[Column]
    #[UpdatedAt]
    public int $changed_at = 0;

    public static function table(): string
    {
        return 'cached_cast';
    }
}

final class MetadataCacheCastableTest extends TestCase
{
    public function testArrayCacheRoundTripWithoutSerializingReflectionObjects(): void
    {
        $reader = new MetadataReader();
        $reader->read(CachedCastEntity::class);
        $cache = $reader->export();
        self::assertSame(1, $cache['version']);
        $php = var_export($cache, true);
        $restored = eval('return ' . $php . ';');
        self::assertIsArray($restored);

        $meta = (new MetadataReader($restored))->read(CachedCastEntity::class);
        self::assertSame('state', $meta->field('status')->column);
        self::assertSame(CachedMoney::class, $meta->field('price')->typeName());
        self::assertSame('int', $meta->field('id')->typeName());
        self::assertSame(['changed_at'], array_values($meta->timestamps));
        self::assertSame(['id'], $meta->primaryColumns());
        self::assertNotSame($reader->read(CachedCastEntity::class), $meta);
        self::assertSame($cache, (new MetadataReader($restored))->export());
    }

    public function testCacheConstructorCanBeUsedWithOpcachedArray(): void
    {
        $cache = ['version' => 1, 'models' => []];
        $reader = new MetadataReader($cache);
        self::assertSame($cache, $reader->export());
        $reader->read(CachedCastEntity::class);
        self::assertArrayHasKey(CachedCastEntity::class, $reader->export()['models']);
    }

    public function testImportRejectsUnsupportedCacheVersion(): void
    {
        $this->expectException(MetadataException::class);
        new MetadataReader(['version' => 999, 'models' => []]);
    }

    public function testImportRejectsStalePropertyType(): void
    {
        $reader = new MetadataReader();
        $reader->read(CachedCastEntity::class);
        $cache = $reader->export();
        $cache['models'][CachedCastEntity::class]['fields']['status']['type'] = 'string';
        $imported = new MetadataReader($cache);
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('does not match the property type');
        $imported->read(CachedCastEntity::class);
    }

    public function testImportRejectsMalformedPrimaryKey(): void
    {
        $reader = new MetadataReader();
        $reader->read(CachedCastEntity::class);
        $cache = $reader->export();
        $cache['models'][CachedCastEntity::class]['primaryKey'] = ['missing'];
        $imported = new MetadataReader($cache);
        $this->expectException(MetadataException::class);
        $imported->read(CachedCastEntity::class);
    }

    public function testStringBackedEnumJsonAndCastableRoundTrip(): void
    {
        $meta = (new MetadataReader())->read(CachedCastEntity::class);
        $caster = new Caster();
        $status = $meta->field('status');
        $price = $meta->field('price');
        $payload = $meta->field('payload');
        $money = new CachedMoney(1299);

        self::assertSame('draft', $caster->toDb(CachedCastEntity::class, $status, CachedStringStatus::Draft));
        self::assertSame(CachedStringStatus::Published, $caster->toPhp(CachedCastEntity::class, $status, 'published'));
        self::assertSame(1299, $caster->toDb(CachedCastEntity::class, $price, $money));
        self::assertSame($money, $caster->toPhp(CachedCastEntity::class, $price, $money));
        self::assertEquals($money, $caster->toPhp(CachedCastEntity::class, $price, '1299'));
        self::assertSame(['nested' => ['ok' => true]], $caster->toPhp(
            CachedCastEntity::class,
            $payload,
            $caster->toDb(CachedCastEntity::class, $payload, ['nested' => ['ok' => true]]),
        ));
    }

    public function testCastableFailureBecomesCastException(): void
    {
        $field = (new MetadataReader())->read(CachedCastEntity::class)->field('price');
        $this->expectException(CastException::class);
        (new Caster())->toPhp(CachedCastEntity::class, $field, '-10');
    }

    public function testCastableRejectsWrongRuntimeValue(): void
    {
        $field = (new MetadataReader())->read(CachedCastEntity::class)->field('price');
        $this->expectException(CastException::class);
        (new Caster())->toDb(CachedCastEntity::class, $field, 1299);
    }

    public function testStringEnumRejectsUnknownValue(): void
    {
        $field = (new MetadataReader())->read(CachedCastEntity::class)->field('status');
        $this->expectException(CastException::class);
        (new Caster())->toPhp(CachedCastEntity::class, $field, 'deleted');
    }
}
