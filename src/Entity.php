<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Query\Builder;
use Pixiaoka\Orm\Query\Expression;

/**
 * Active Record。字段是带 #[Column] 的类型化属性，表名由子类给出。
 * save / insert / delete 走当前 Session，不在模型里写 SQL。
 */
abstract class Entity
{
    private bool $ormNew = true;

    /** @var array<string, mixed> 持久化时的原值，用于脏跟踪 */
    private array $ormOriginal = [];

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

    /** 插入成功，或从数据库读出后，标成已存在。 */
    public function markPersisted(): void
    {
        $this->ormNew = false;
        $this->ormOriginal = $this->snapshot();
    }

    /** 删除成功后标成未入库。之后 save 会重新插入。 */
    public function markDeleted(): void
    {
        $this->ormNew = true;
        $this->ormOriginal = [];
    }

    /** @internal */
    public function takeOriginal(): array
    {
        return $this->ormOriginal;
    }

    /**
     * @internal 事务边界保存完整持久化状态，包含字段的未初始化状态。
     * @return array{new: bool, original: array<string, mixed>, values: array<string, mixed>}
     */
    public function captureState(ModelMeta $meta): array
    {
        $values = [];
        foreach ($meta->fields as $field) {
            if ($this->propSet($field->property)) {
                $values[$field->property] = $this->{$field->property};
            }
        }

        return ['new' => $this->ormNew, 'original' => $this->ormOriginal, 'values' => $values];
    }

    /**
     * @param array{new: bool, original: array<string, mixed>, values: array<string, mixed>} $state
     * @param ModelMeta $meta
     * @throws \ReflectionException
     * @internal 只在物理事务回滚时恢复，不能用于普通业务赋值。
     */
    public function restoreState(array $state, ModelMeta $meta): void
    {
        foreach ($meta->fields as $field) {
            $property = new \ReflectionProperty($this, $field->property);
            if (array_key_exists($field->property, $state['values'])) {
                $this->{$field->property} = $state['values'][$field->property];
            } elseif ($property->isInitialized($this)) {
                unset($this->{$field->property});
            }
        }
        $this->ormNew = $state['new'];
        $this->ormOriginal = $state['original'];
    }

    /** 只改过的字段才进入 UPDATE。 */
    private function snapshot(): array
    {
        $arr = [];
        $meta = Session::get()->gateway->meta(static::class);
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

        Session::get()->gateway->update($this);
    }

    public function insert(): void
    {
        if (!$this->ormNew) {
            throw new IdentityException(static::class . ' 已经在库里，不能再 insert');
        }

        Session::get()->gateway->insert($this);
    }

    public function delete(): void
    {
        if ($this->ormNew) {
            throw new IdentityException(static::class . ' 还没入库，不能 delete');
        }

        Session::get()->gateway->delete($this);
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
        return Session::get()->gateway->toArray($this);
    }

    public function refresh(): static
    {
        Session::get()->gateway->refresh($this);

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $rows
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

    /** @internal 属性是否已初始化 */
    public function propSet(string $property): bool
    {
        return (new \ReflectionProperty($this, $property))->isInitialized($this);
    }
}