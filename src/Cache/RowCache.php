<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cache;

use InvalidArgumentException;
use Pixiaoka\Orm\Entity;
use WeakMap;

/**
 * 进程内行缓存。key = class + 序列化的主键值。
 */
final class RowCache
{
    /** @var array<string, Entity> */
    private array $items = [];

    private int $maxSize;

    /** @var array<string, array<string, int|float|string|bool|null>> */
    private array $identities = [];

    /**
     * 已交给调用方的实体 => 主键身份。WeakMap 不延长实体生命周期。
     * LRU/批量写清退缓存后，存活对象仍须能被条件 DELETE 精确失效。
     *
     * @var WeakMap<Entity, array<string, int|float|string|bool|null>>|null
     */
    private ?WeakMap $knownIdentities = null;

    public function __construct(int $maxSize = 1024)
    {
        if ($maxSize < 0) {
            throw new InvalidArgumentException('RowCache capacity must not be negative');
        }
        $this->maxSize = $maxSize;
    }

    public function get(string $class, array $identity): ?Entity
    {
        $key = $this->makeKey($class, $identity);
        $item = $this->items[$key] ?? null;
        if ($item !== null && !$item->isNew()) {
            // PHP 关联数组保持插入顺序：删除后回写即以 O(1) 移至队尾。
            unset($this->items[$key]);
            $this->items[$key] = $item;

            return $item;
        }
        $this->clearKey($key);

        return null;
    }

    /** @param array<string, int|float|string|bool|null> $identity */
    public function set(Entity $entity, array $identity): void
    {
        $this->knownIdentities ??= new WeakMap();
        $this->knownIdentities[$entity] = $identity;
        if ($this->maxSize === 0) {
            return;
        }

        $key = $this->makeKey($entity::class, $identity);
        unset($this->items[$key]);
        $this->items[$key] = $entity;
        $this->identities[$key] = $identity;
        if (count($this->items) > $this->maxSize) {
            $old = array_key_first($this->items);
            if ($old !== null) {
                unset($this->items[$old], $this->identities[$old]);
            }
        }
    }

    /** @return string 与 set() 相同规则生成的缓存 key，供外部精确清除 */
    public function setKey(Entity $entity, array $identity): string
    {
        return $this->makeKey($entity::class, $identity);
    }

    /** @return array<string, Entity> 当前 LRU 中的存活对象，供最外层事务保存状态。 */
    public function snapshot(): array
    {
        return $this->items;
    }

    /** 事务回滚时按 key 精确清除，不清整个缓存。 */
    public function clearKey(string $key): void
    {
        unset($this->items[$key], $this->identities[$key]);
    }

    /** @return list<Entity> */
    public function known(): array
    {
        $entities = [];
        foreach ($this->knownIdentities ?? [] as $entity => $_identity) {
            $entities[] = $entity;
        }

        return $entities;
    }

    /** 返回已交给调用方的存活实体，包括已从 LRU 清退的对象。 @return list<Entity> */
    public function knownEntities(string $class): array
    {
        $entities = [];
        foreach ($this->knownIdentities ?? [] as $entity => $_identity) {
            if ($entity::class === $class && !$entity->isNew()) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /** @return array<string, int|float|string|bool|null>|null */
    public function knownIdentity(Entity $entity): ?array
    {
        return $this->knownIdentities[$entity] ?? null;
    }

    /** @return array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>} */
    public function transactionSnapshot(): array
    {
        return ['items' => $this->items, 'identities' => $this->identities];
    }

    /** @param array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>} $snapshot */
    public function restoreSnapshot(array $snapshot): void
    {
        $known = $this->knownIdentities ?? new WeakMap();
        // items 自身的插入顺序就是 LRU 顺序。
        $this->items = $snapshot['items'];
        $this->identities = $snapshot['identities'];
        $this->knownIdentities = $known;
        foreach ($snapshot['identities'] as $key => $identity) {
            if (isset($snapshot['items'][$key])) {
                $known[$snapshot['items'][$key]] = $identity;
            }
        }
    }

    public function clear(string $class, array $identity): void
    {
        $this->clearKey($this->makeKey($class, $identity));
    }

    public function clearClass(string $class, bool $deleted = false): void
    {
        $prefix = $class . ':';
        foreach ($this->items as $key => $entity) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            if ($deleted) {
                $entity->markDeleted();
            }
            $this->clearKey($key);
        }
    }

    /**
     * 返回本 gateway 已交给调用方的存活实体身份，而不只返回仍在 LRU 中的对象。
     * 调用方用受限主键集合确认实际删除行，避免扫描整表主键。
     *
     * @return list<array<string, int|float|string|bool|null>>
     */
    public function identities(string $class): array
    {
        $identities = [];
        foreach ($this->knownIdentities ?? [] as $entity => $identity) {
            if ($entity::class !== $class || $entity->isNew()) {
                continue;
            }
            $key = $this->makeKey($class, $identity);
            $identities[$key] = $identity;
        }

        return array_values($identities);
    }

    /** @param list<array<string, int|float|string|bool|null>> $identities */
    public function clearDeleted(string $class, array $identities): void
    {
        if ($identities === []) {
            return;
        }
        $deletedKeys = [];
        foreach ($identities as $identity) {
            $deletedKeys[$this->makeKey($class, $identity)] = true;
        }
        foreach ($this->knownIdentities ?? [] as $entity => $identity) {
            if ($entity::class !== $class || $entity->isNew()) {
                continue;
            }
            $key = $this->makeKey($class, $identity);
            if (isset($deletedKeys[$key])) {
                $entity->markDeleted();
            }
        }
        foreach (array_keys($deletedKeys) as $key) {
            $this->clearKey($key);
        }
    }

    public function flush(): void
    {
        $this->items = [];
        $this->identities = [];
    }

    /** 事务结果未知时，旧对象身份不能再参与条件 DELETE 预查。 */
    public function forgetKnown(): void
    {
        $this->knownIdentities = new WeakMap();
    }

    private function makeKey(string $class, array $identity): string
    {
        return self::key($class, $identity);
    }

    /** @internal 类型化序列化保留列边界，避免复合字符串键或标量类型拼接碰撞。 */
    public static function key(string $class, array $identity): string
    {
        ksort($identity);

        return $class . ':' . serialize($identity);
    }
}
