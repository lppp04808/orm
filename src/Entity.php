<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Metadata\RelationMeta;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\Expression;
use Pixiaoka\Orm\Relation\BelongsTo;
use Pixiaoka\Orm\Relation\HasMany;
use Pixiaoka\Orm\Relation\HasOne;
use Pixiaoka\Orm\Relation\Relation;
use WeakReference;

/**
 * Active Record。字段是带 #[Column] 的类型化属性，表名由子类给出。
 * save / insert / delete 走当前 Session 并检查实体的弱 Gateway 归属，不在模型里写 SQL。
 */
abstract class Entity
{
    private bool $ormNew = true;

    /** @var array<class-string, array<string, \ReflectionProperty>> 反射元数据按实际实体类与属性名复用，不进入持久化元数据。 */
    private static array $reflectionProperties = [];

    /** @var ?WeakReference<EntityGateway> 弱归属不延长 Gateway/连接生命周期；禁止跨连接隐式切换。 */
    private ?WeakReference $ormGateway = null;

    /** @var array<string, mixed> 持久化时的原值，用于脏跟踪 */
    private array $ormOriginal = [];

    /** @var array<string, Entity|list<Entity>|null> 仅保存显式加载的关系，不参与持久化。 */
    private array $ormRelations = [];

    /** @var array<string, true> 已加载 null/空数组也必须与未加载区分。 */
    private array $ormLoadedRelations = [];
    // ---- 生命周期钩子，子类覆盖 ----

    public function beforeSave(): void {}
    public function afterSave(): void {}
    public function beforeInsert(): void {}
    public function afterInsert(): void {}
    public function beforeUpdate(): void {}
    public function afterUpdate(): void {}
    public function beforeDelete(): void {}
    public function afterDelete(): void {}

    abstract public static function table(): string;

    public function isNew(): bool { return $this->ormNew; }

    /** @internal 由所属 Gateway 在插入成功或水合后标成已存在；业务代码应使用 save/refresh。 */
    public function markPersisted(EntityGateway $gateway): void
    {
        $gateway->forEntity($this);
        $this->ormNew = false;
        $this->ormOriginal = $this->snapshot();
    }

    /** 删除成功后标成未入库。之后 save 只能在原 Gateway 上重新插入。 */
    public function markDeleted(): void
    {
        $this->ormNew = true;
        $this->ormOriginal = [];
        $this->clearLoadedRelations();
    }

    /** 显式重新读取当前行时也清理关系，避免旧外键继续对应新实体状态。 @internal */
    public function clearLoadedRelations(): void
    {
        $this->ormRelations = [];
        $this->ormLoadedRelations = [];
    }

    /** @internal 只由只读关系加载器在关联键变化时失效对应关系。 */
    public function forgetLoadedRelation(string $name): void
    {
        unset($this->ormRelations[$name], $this->ormLoadedRelations[$name]);
    }

    /** @internal 只由只读关系加载器调用。 */
    public function setLoadedRelation(string $name, Entity|array|null $value): void
    {
        $this->ormRelations[$name] = $value;
        $this->ormLoadedRelations[$name] = true;
    }

    public function relationLoaded(string $name): bool
    {
        return isset($this->ormLoadedRelations[$name]);
    }

    /** 未加载关系不会隐式查询。 @return Entity|list<Entity>|null */
    public function loadedRelation(string $name): Entity|array|null
    {
        if (!$this->relationLoaded($name)) {
            throw new \LogicException(static::class . "::$name is not loaded; call with/load or $name()->get()");
        }

        return $this->ormRelations[$name];
    }

    /** @param string|array<int|string, mixed> $relations */
    public function load(string|array $relations): static
    {
        $this->gateway()->loadRelations([$this], $relations);

        return $this;
    }

    /** 根据目标关系方法的显式返回类型创建查询对象。 @internal */
    public function makeRelation(?RelationMeta $meta = null): Relation
    {
        $gateway = $this->gateway();
        if ($meta === null) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $name = $trace[1]['function'] ?? '';
            $meta = $gateway->relation(static::class, $name);
        }

        return match ($meta->kind) {
            'hasOne' => new HasOne($this, $meta, $gateway),
            'hasMany' => new HasMany($this, $meta, $gateway),
            'belongsTo' => new BelongsTo($this, $meta, $gateway),
            default => throw new \LogicException('Unknown relation type: ' . $meta->kind),
        };
    }

    /** @internal */
    public function takeOriginal(): array
    {
        return $this->ormOriginal;
    }

    /** @internal 仅首次接触实体时建立弱归属；过期也不能静默换绑。 */
    public function bindGateway(EntityGateway $gateway): void
    {
        if ($this->ormGateway !== null) {
            throw new \LogicException(static::class . ' already has a Gateway binding and cannot be rebound');
        }
        $this->ormGateway = WeakReference::create($gateway);
    }

    /** @internal */
    public function hasGatewayBinding(): bool
    {
        return $this->ormGateway !== null;
    }

    /** @internal */
    public function boundGateway(): ?EntityGateway
    {
        return $this->ormGateway?->get();
    }

    /** @internal 任何实体入口都须经过 Gateway 的绑定/归属检查。 */
    private function gateway(): EntityGateway
    {
        return Session::get()->gateway->forEntity($this);
    }

    /**
     * @internal 事务边界保存完整持久化状态，包含字段的未初始化状态。
     * @return array{new: bool, original: array<string, mixed>, values: array<string, mixed>, relations: array<string, mixed>, loadedRelations: array<string, true>}
     */
    public function captureState(ModelMeta $meta): array
    {
        $values = [];
        foreach ($meta->fields as $field) {
            if ($this->propSet($field->property)) {
                $values[$field->property] = $this->{$field->property};
            }
        }

        return [
            'new' => $this->ormNew,
            'original' => $this->ormOriginal,
            'values' => $values,
            'relations' => $this->ormRelations,
            'loadedRelations' => $this->ormLoadedRelations,
        ];
    }

    /**
     * @param array{new: bool, original: array<string, mixed>, values: array<string, mixed>, relations?: array<string, mixed>, loadedRelations?: array<string, true>} $state
     * @param ModelMeta $meta
     * @throws \ReflectionException
     * @internal 只在物理事务回滚时恢复，不能用于普通业务赋值。
     */
    public function restoreState(array $state, ModelMeta $meta): void
    {
        foreach ($meta->fields as $field) {
            $property = $this->reflectionProperty($field->property);
            if (array_key_exists($field->property, $state['values'])) {
                $this->{$field->property} = $state['values'][$field->property];
            } elseif ($property->isInitialized($this)) {
                unset($this->{$field->property});
            }
        }
        $this->ormNew = $state['new'];
        $this->ormOriginal = $state['original'];
        $this->ormRelations = $state['relations'] ?? [];
        $this->ormLoadedRelations = $state['loadedRelations'] ?? [];
    }

    /** 只改过的字段才进入 UPDATE。 */
    private function snapshot(): array
    {
        $arr = [];
        $gateway = $this->boundGateway();
        if ($gateway === null) {
            if ($this->hasGatewayBinding()) {
                throw new \LogicException(static::class . ' has a destroyed Gateway; query it explicitly through a new Gateway');
            }
            $gateway = Session::get()->gateway;
        }
        $meta = $gateway->meta(static::class);
        foreach ($meta->fields as $field) {
            if (!$field->primary && $this->propSet($field->property)) {
                $arr[$field->property] = $this->{$field->property};
            }
        }

        return $arr;
    }

    public function save(): void
    {
        if ($this->ormNew) {
            $this->insert();

            return;
        }

        $this->gateway()->update($this);
    }

    public function insert(): void
    {
        if (!$this->ormNew) {
            throw new IdentityException(static::class . ' is already persisted and cannot be inserted again');
        }

        $this->gateway()->insert($this);
    }

    public function delete(): void
    {
        if ($this->ormNew) {
            throw new IdentityException(static::class . ' is not persisted and cannot be deleted');
        }

        $this->gateway()->delete($this);
    }

    public function restore(): void
    {
        if ($this->ormNew) {
            throw new IdentityException(static::class . ' is not persisted and cannot be restored');
        }
        $this->gateway()->restore($this);
    }

    public function forceDelete(): void
    {
        if ($this->ormNew) {
            throw new IdentityException(static::class . ' is not persisted and cannot be force-deleted');
        }
        $this->gateway()->forceDelete($this);
    }

    /** @return Builder<static> */
    public static function query(): Builder
    {
        return new Builder(static::class, Session::get()->gateway);
    }

    /**
     * @param array<string, mixed> $key
     */
    public static function find(array $key): ?static
    {
        /** @var static|null */
        return Session::get()->gateway->find(static::class, $key);
    }

    public function toArray(): array
    {
        $gateway = $this->boundGateway();
        if ($gateway !== null) {
            return $gateway->toArray($this);
        }
        if ($this->hasGatewayBinding()) {
            throw new \LogicException(static::class . ' has a destroyed Gateway; query it explicitly through a new Gateway');
        }

        return Session::get()->gateway->toArray($this);
    }

    public function refresh(): static
    {
        $this->gateway()->refresh($this);

        return $this;
    }

    /**
     * @param list<static|array<string, mixed>> $rows
     */
    public static function insertAll(array $rows): void
    {
        Session::get()->gateway->batchInsert(static::class, $rows);
    }

    /** 构造可复用的参数化原生 SQL，例如 SELECT、CTE、窗口函数或数据库专有语句。 */
    public static function raw(string $sql, array $params = []): Expression
    {
        return new Expression($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public static function queryRaw(string|Expression $sql, array $params = []): array
    {
        return Session::get()->gateway->selectRaw($sql, $params);
    }

    public static function queryOneRaw(string|Expression $sql, array $params = []): ?array
    {
        return Session::get()->gateway->selectOneRaw($sql, $params);
    }

    public static function executeRaw(string|Expression $sql, array $params = []): int
    {
        return Session::get()->gateway->executeRaw($sql, $params);
    }

    /**
     * 默认按主键冲突；$updateColumns=[] 为冲突时忽略，null 为更新其余输入字段。
     *
     * @param array<string, mixed> $values
     * @param list<string>|null $updateColumns
     * @param list<string>|null $conflictColumns
     */
    public static function upsert(
        array $values,
        ?array $updateColumns = null,
        ?array $conflictColumns = null,
    ): int {
        return Session::get()->gateway->upsert(static::class, $values, $updateColumns, $conflictColumns);
    }

    /** @internal 原始写操作绕过模型身份，调用方可显式清理该模型的进程内缓存。 */
    public static function flushCache(): void
    {
        Session::get()->gateway->clearCache(static::class);
    }

    /** 事务。嵌套调用只在最外层提交/回滚。 */
    public static function transaction(callable $func): mixed
    {
        return Session::get()->gateway->transaction($func);
    }

    public function __call(string $name, array $arguments): Relation
    {
        $meta = $this->gateway()->relation(static::class, $name);
        if ($arguments !== []) {
            throw new \InvalidArgumentException(static::class . "::$name relation method does not accept arguments");
        }

        return $this->makeRelation($meta);
    }

    /** @internal 属性是否已初始化 */
    public function propSet(string $property): bool
    {
        return $this->reflectionProperty($property)->isInitialized($this);
    }

    /** @throws \ReflectionException 属性不存在时保持原生反射异常语义。 */
    private function reflectionProperty(string $property): \ReflectionProperty
    {
        return self::$reflectionProperties[static::class][$property]
            ??= new \ReflectionProperty(static::class, $property);
    }
}