<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Relation;

use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Metadata\RelationMeta;
use Pixiaoka\Orm\Query\Builder;

/** @internal 只读关系批量加载；独立 SELECT，不把 JOIN 行水合为实体。 */
final class Loader
{
    /** @var array<string, true> 当前调用栈，防止关系配置形成无限递归。 */
    private array $loading = [];

    /** @var array<string, Entity> 单次关系图内已知对象身份；不使用普通查询的新副本替换它。 */
    private array $identities = [];

    /** @var array<string, array{entity: Entity, state: array}> 关系图发布前保存旧目标完整状态。 */
    private array $targetStates = [];

    /** @var array<string, array{fresh: Entity}> 成功时统一发布的关系目标。 */
    private array $publish = [];

    /** @var list<Entity> 本次主查询对象优先于旧 RowCache，保证反向关系指向本次结果。 */
    private array $sources = [];

    /** @var array|null 预加载只读阶段可能合并旧对象，失败须恢复缓存身份和 LRU。 */
    private ?array $cacheSnapshot = null;

    /** @var list<array{entity: Entity, name: string, value: Entity|array|null}> 整棵关系树成功后才安装。 */
    private array $pending = [];

    public function __construct(private readonly EntityGateway $gateway)
    {
    }

    /**
     * @param list<Entity> $entities
     * @param string|array<int|string, mixed> $relations
     */
    public function load(array $entities, string|array $relations): void
    {
        if ($this->loading !== []) {
            throw new RelationException('预加载约束不得重入 with/load；请使用点分嵌套路径声明关系');
        }
        if ($entities === []) {
            return;
        }
        $class = $entities[0]::class;
        foreach ($entities as $entity) {
            if ($entity::class !== $class) {
                throw new RelationException('一次 with/load 只能加载同一个模型的关系');
            }
        }
        $this->identities = [];
        $this->pending = [];
        $this->targetStates = [];
        $this->publish = [];
        $this->sources = $entities;
        $this->cacheSnapshot = null;
        $this->registerTargets($entities);
        try {
            // 先规范化整个配置，避免主查询后才发现无效路径。
            $tree = $this->normalize($relations);
            $this->cacheSnapshot = $this->gateway->relationCacheSnapshot();
            $this->loadLevel($entities, $tree, $class);
            // 保存基线早于任何字段合并，以便失败及物理事务回滚都恢复同一状态。
            foreach ($this->sources as $source) {
                $this->remember($source);
                $this->gateway->recordRelationState($source);
            }
            foreach ($this->publish as $key => $publication) {
                $target = $this->identities[$key];
                $this->prepareTarget($target, $publication['fresh']);
                $this->gateway->publishRelationTarget($target);
            }
            foreach ($this->pending as $loaded) {
                $loaded['entity']->setLoadedRelation($loaded['name'], $loaded['value']);
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($this->targetStates) as $target) {
                $target['entity']->restoreState($target['state'], $this->gateway->meta($target['entity']::class));
            }
            if ($this->cacheSnapshot !== null) {
                $this->gateway->restoreRelationCache($this->cacheSnapshot);
            }
            throw $e;
        } finally {
            $this->identities = [];
            $this->pending = [];
            $this->targetStates = [];
            $this->publish = [];
            $this->sources = [];
            $this->cacheSnapshot = null;
        }
    }

    private function registerTargets(array $entities): void
    {
        foreach ($entities as $entity) {
            $identity = $this->gateway->relationIdentity($entity::class, $this->identityValues($entity));
            $this->identities[RowCache::key($entity::class, $identity)] ??= $entity;
        }
    }

    /** 在任何字段或关系合并前保存完整基线，物理事务回滚也复用同一基线。 */
    private function prepareTarget(Entity $target, Entity $fresh): void
    {
        $this->remember($target);
        $this->gateway->recordRelationState($target);
        $this->refresh($target, $fresh);
    }

    /** 合并持久化字段但不覆盖本地脏字段；只失效依赖已变化持久化键的关系。 */
    private function refresh(Entity $target, Entity $fresh): void
    {
        if ($target === $fresh) {
            return;
        }
        $meta = $this->gateway->meta($target::class);
        $current = $target->captureState($meta);
        $incoming = $fresh->captureState($meta);
        $original = $current['original'];
        $values = $current['values'];
        $changed = [];
        foreach ($incoming['values'] as $property => $value) {
            $field = $meta->field($property);
            // 比较“当前值是否偏离本地原值”，不能比较数据库新值与原值：
            // 外部 UPDATE 可能让二者相等，而调用方另有不等的未保存修改。
            $dirty = !array_key_exists($property, $original)
                || !array_key_exists($property, $values)
                || !$this->gateway->fieldValuesEqual($meta, $field, $values[$property], $original[$property]);
            if (!$dirty) {
                $values[$property] = $value;
            }
            $hadOriginal = array_key_exists($property, $original);
            $hasOriginal = array_key_exists($property, $incoming['original']);
            $changed[$property] = $hadOriginal !== $hasOriginal
                || ($hadOriginal && $hasOriginal && !$this->gateway->fieldValuesEqual(
                    $meta,
                    $field,
                    $original[$property],
                    $incoming['original'][$property],
                ));
        }
        $current['values'] = $values;
        $current['original'] = $incoming['original'];
        $target->restoreState($current, $meta);

        // 失效规则必须与当前图中待安装关系协调，不能让本轮正确结果覆盖旧关系卸载。
        $pendingRelations = [];
        foreach ($this->pending as $loaded) {
            if ($loaded['entity'] === $target) {
                $pendingRelations[$loaded['name']] = true;
            }
        }
        foreach ($current['loadedRelations'] as $name => $_) {
            $relation = $this->gateway->relationDefinitions($target::class)[$name]
                ?? throw new RelationException($target::class . "::$name 的关系声明已失效");
            foreach ($relation->sourceKey as $field) {
                if (($changed[$field->property] ?? false) && !isset($pendingRelations[$name])) {
                    $target->forgetLoadedRelation($name);
                    break;
                }
            }
        }
    }

    private function remember(Entity $entity): void
    {
        $key = $entity::class . ':' . spl_object_id($entity);
        $this->targetStates[$key] ??= [
            'entity' => $entity,
            'state' => $entity->captureState($this->gateway->meta($entity::class)),
        ];
    }

    /** @param array<string, RelationSpec> $paths */
    private function loadLevel(array $entities, array $paths, string $class): void
    {
        foreach ($paths as $name => $spec) {
            $relation = $this->gateway->relation($class, $name);
            $key = $class . '::' . $name;
            if (isset($this->loading[$key])) {
                throw new RelationException("关系加载存在循环: $key");
            }
            // 在执行约束之前置位：约束闭包重入同一关系也必须被拒绝。
            $this->loading[$key] = true;
            try {
                $fetched = $this->fetch($entities, $relation, $spec->constraint);
                if ($spec->children !== []) {
                    // 子查询必须读取已合并的新关联键；失败时仍由上面的完整状态快照恢复。
                    foreach ($fetched['related'] as $target) {
                        $identity = RowCache::key(
                            $target::class,
                            $this->gateway->relationIdentity($target::class, $this->identityValues($target)),
                        );
                        if (isset($this->publish[$identity])) {
                            $this->prepareTarget($target, $this->publish[$identity]['fresh']);
                        }
                    }
                    $this->loadLevel($fetched['related'], $spec->children, $relation->target);
                }
                foreach ($fetched['loaded'] as $loaded) {
                    $this->pending[] = ['entity' => $loaded['entity'], 'name' => $name, 'value' => $loaded['value']];
                }
            } finally {
                unset($this->loading[$key]);
            }
        }
    }

    /**
     * @param list<Entity> $entities
     * @param callable|null $constraint
     * @return array{related: list<Entity>, loaded: list<array{entity: Entity, value: mixed}>}
     */
    private function fetch(array $entities, RelationMeta $relation, ?callable $constraint): array
    {
        $keys = [];
        $this->registerTargets($entities);
        foreach ($entities as $source) {
            $values = $this->keyValues($source, $relation);
            if ($values !== null) {
                $keys[$this->key($values)] = $values;
            }
        }
        $groups = array_fill_keys(array_keys($keys), []);

        if ($keys !== []) {
            foreach (array_chunk(array_values($keys), max(1, intdiv(1000, count($relation->targetKey)))) as $chunk) {
                $query = new Builder($relation->target, $this->gateway);
                $query->whereRaw($this->gateway->constrainRelationTuples($relation->targetKey, $chunk));
                if ($constraint !== null) {
                    $query = $this->applyConstraint($query, $constraint, $relation);
                } else {
                    $query = $this->applyConstraint($query, static fn (Builder $query): Builder => $query, $relation);
                }
                foreach ($query->withoutCache() as $target) {
                    $values = $this->keyValues($target, $relation, false);
                    // 每个元组条件已经排除 NULL，理论不可达；显式保护避免错误键串行。
                    if ($values === null) {
                        throw new RelationException($relation->target . ' 的关联键不能为 NULL');
                    }
                    $identityValues = $this->identityValues($target);
                    $identity = RowCache::key(
                        $relation->target,
                        $this->gateway->relationIdentity($relation->target, $identityValues),
                    );
                    if (!isset($this->identities[$identity])) {
                        $this->identities[$identity] = $this->gateway->relationTarget(
                            $relation->target,
                            $this->gateway->relationIdentity($relation->target, $identityValues),
                        ) ?? $target;
                    }
                    $this->publish[$identity] ??= ['fresh' => $target];
                    $groups[$this->key($values)][$identity] = $this->identities[$identity];
                }
            }
        }

        $related = [];
        $identities = [];
        $loaded = [];
        foreach ($entities as $source) {
            $values = $this->keyValues($source, $relation);
            $children = $values === null ? [] : array_values($groups[$this->key($values)] ?? []);
            $loaded[] = ['entity' => $source, 'value' => $relation->many() ? $children : ($children[0] ?? null)];
            foreach ($children as $child) {
                $identity = RowCache::key(
                    $child::class,
                    $this->gateway->relationIdentity($child::class, $this->identityValues($child)),
                );
                if (!isset($identities[$identity])) {
                    $related[] = $child;
                    $identities[$identity] = true;
                }
            }
        }

        return ['related' => $related, 'loaded' => $loaded];
    }

    private function applyConstraint(Builder $query, callable $constraint, RelationMeta $relation): Builder
    {
        $result = $constraint($query);
        if ($result !== null && $result !== $query) {
            throw new RelationException($relation->name . ' 预加载约束必须修改传入的 Builder，或返回同一 Builder');
        }
        $shape = $query->relationShape();
        if ($shape['limit'] !== null || $shape['offset'] > 0) {
            throw new RelationException($relation->name . ' 预加载不支持全局 limit/offset；请先关联查询，后续使用独立游标分页');
        }
        if ($shape['projection'] || $shape['joins'] || $shape['groupBy'] || $shape['having']
            || $shape['unions'] || $shape['derivedFrom'] || $shape['distinct'] || $shape['alias']
            || $shape['lock'] || $shape['relations'] !== []) {
            throw new RelationException($relation->name . ' 预加载只允许 WHERE/OR/排序及确定性 targetKey 覆盖；不支持复杂查询、行锁或嵌套 with');
        }
        // 用户排序在前；关联键用于父行分组，目标主键用于同组候选的稳定决胜。
        $ordered = $shape['order'];
        foreach (array_merge($relation->targetKey, $this->gateway->meta($relation->target)->primaryKey) as $field) {
            if (!in_array($field->column, $ordered, true)) {
                $query->orderBy($field->column);
                $ordered[] = $field->column;
            }
        }

        return $query;
    }

    /** @return list<int|float|string|bool|null>|null */
    private function keyValues(Entity $entity, RelationMeta $relation, bool $source = true): ?array
    {
        $values = [];
        $fields = $source ? $relation->sourceKey : $relation->targetKey;
        foreach ($fields as $field) {
            if (!$entity->propSet($field->property)) {
                throw new RelationException($entity::class . '::' . $relation->name . ' 缺少关联键 ' . $field->property);
            }
            $value = $entity->{$field->property};
            if ($value === null) {
                return null;
            }
            $values[] = $this->gateway->castQueryValue($entity::class, $field->property, $value);
        }

        return $values;
    }

    /** @return list<int|float|string|bool|null> */
    private function identityValues(Entity $entity): array
    {
        $values = [];
        foreach ($this->gateway->meta($entity::class)->primaryKey as $field) {
            if (!$entity->propSet($field->property)) {
                throw new RelationException($entity::class . ' 缺少主键 ' . $field->property);
            }
            $values[] = $this->gateway->castQueryValue($entity::class, $field->column, $entity->{$field->property});
        }

        return $values;
    }

    private function key(array $values): string
    {
        return serialize($values);
    }

    /** @return array<string, RelationSpec> */
    private function normalize(string|array $relations): array
    {
        $tree = [];
        foreach ($this->items($relations) as [$path, $constraint]) {
            $this->insertPath($tree, explode('.', $path), $constraint, $path);
        }

        return $tree;
    }

    private function insertPath(array &$tree, array $parts, ?callable $constraint, string $path): void
    {
        $name = array_shift($parts);
        if ($name === '') {
            throw new RelationException('关系路径不能包含空名称');
        }
        if (!isset($tree[$name])) {
            $tree[$name] = new RelationSpec($name);
        }
        /** @var array<string, RelationSpec> $children */
        $children = $tree[$name]->children;
        if ($parts !== []) {
            $this->insertPath($children, $parts, $constraint, $path);
            $tree[$name]->children = $children;

            return;
        }
        if ($constraint !== null && $tree[$name]->constraint !== null) {
            throw new RelationException('同一关系路径不能声明多个约束: ' . $path);
        }
        $tree[$name]->constraint = $constraint;
    }

    /** @return list<array{string, callable|null}> */
    private function items(string|array $relations): array
    {
        if (is_string($relations)) {
            return $this->items([$relations]);
        }
        $items = [];
        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    foreach ($this->items($value) as $item) {
                        $items[] = $item;
                    }
                    continue;
                }
                if (!is_string($value)) {
                    throw new RelationException('位置关系项必须是路径字符串或关系配置数组');
                }
                $items[] = [$value, null];
            } elseif (is_string($value)) {
                $items[] = [$key . '.' . $value, null];
            } elseif (is_array($value)) {
                foreach ($this->items($value) as [$child, $constraint]) {
                    $items[] = [$key . '.' . $child, $constraint];
                }
            } elseif (is_callable($value)) {
                $items[] = [$key, $value];
            } else {
                throw new RelationException('关系约束必须是可调用对象或嵌套路径数组');
            }
        }

        return $items;
    }
}
