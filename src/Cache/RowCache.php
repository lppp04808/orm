<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cache;

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

    /** @var list<string> */
    private array $keys = [];

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
        $this->maxSize = $maxSize;
    }

    public function get(string $class, array $identity): ?Entity
    {
        $key = $this->makeKey($class, $identity);
        $item = $this->items[$key] ?? null;
        if ($item !== null && !$item->isNew()) {
            return $item;
        }
        unset($this->items[$key]);

        return null;
    }

    /**
     * P2-4：key 已存在时先从 LRU 队列移除旧位置再追加，
     * 保证重复 set 不稀释淘汰顺序、不撑大 keys 数组。
     */
    /** @param array<string, int|float|string|bool|null> $identity */
    public function set(Entity $entity, array $identity): void
    {
        $key = $this->makeKey($entity::class, $identity);
        if (isset($this->items[$key])) {
            $this->keys = array_values(array_diff($this->keys, [$key]));
        }
        $this->items[$key] = $entity;
        $this->identities[$key] = $identity;
        $this->knownIdentities ??= new WeakMap();
        $this->knownIdentities[$entity] = $identity;
        $this->keys[] = $key;

        while (count($this->items) > $this->maxSize) {
            $old = array_shift($this->keys);
            unset($this->items[$old], $this->identities[$old]);
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
        $position = array_search($key, $this->keys, true);
        if ($position !== false) {
            array_splice($this->keys, $position, 1);
        }
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

    /** @return array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, keys: list<string>} */
    public function transactionSnapshot(): array
    {
        return ['items' => $this->items, 'identities' => $this->identities, 'keys' => $this->keys];
    }

    /** @param array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, keys: list<string>} $snapshot */
    public function restoreSnapshot(array $snapshot): void
    {
        $known = $this->knownIdentities ?? new WeakMap();
        $this->items = $snapshot['items'];
        $this->keys = $snapshot['keys'];
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
        $prefix = $class . ':';
        foreach ($this->knownIdentities ?? [] as $entity => $identity) {
            $key = $this->makeKey($class, $identity);
            if (!$entity->isNew() && str_starts_with($key, $prefix)) {
                $identities[] = $identity;
            }
        }

        return $identities;
    }

    /** @param list<array<string, int|float|string|bool|null>> $identities */
    public function clearDeleted(string $class, array $identities): void
    {
        $deletedKeys = [];
        foreach ($identities as $identity) {
            $deletedKeys[$this->makeKey($class, $identity)] = true;
        }
        foreach ($this->knownIdentities ?? [] as $entity => $identity) {
            $key = $this->makeKey($class, $identity);
            if (isset($deletedKeys[$key]) && !$entity->isNew()) {
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
        $this->keys = [];
        $this->identities = [];
    }

    /** 事务结果未知时，旧对象身份不能再参与条件 DELETE 预查。 */
    public function forgetKnown(): void
    {
        $this->knownIdentities = new WeakMap();
    }

    private function makeKey(string $class, array $identity): string
    {
        ksort($identity);

        return $class . ':' . implode("\0", $identity);
    }
}
