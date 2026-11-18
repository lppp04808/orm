<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Relation;

use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\RelationException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Metadata\RelationMeta;
use Pixiaoka\Orm\Query\Builder;

/** 单个实体的显式只读关系查询。方法注解声明关系，本类不推导关联名称。 */
abstract class Relation
{
    public function __construct(
        protected readonly Entity $source,
        protected readonly RelationMeta $meta,
        protected readonly EntityGateway $gateway,
    ) {
    }

    /** 额外条件只适用于当前关系查询，不修改源实体。 */
    public function query(Builder $query): static
    {
        if ($query->modelClass() !== $this->meta->target) {
            throw new RelationException('关系条件 Builder 的模型必须是 ' . $this->meta->target);
        }
        $this->gateway->assertSame($query->gateway(), '关系查询');
        $clone = clone $this;
        $clone->query = clone $query;

        return $clone;
    }

    /** @return list<Entity>|Entity|null */
    public function get(): array|Entity|null
    {
        $entities = $this->queryEntities();
        if ($this->meta->many()) {
            return $entities;
        }

        return $entities[0] ?? null;
    }

    public function first(): ?Entity
    {
        $query = $this->query === null
            ? new Builder($this->meta->target, $this->gateway)
            : clone $this->query;
        $values = $this->sourceValues();
        if ($values === null) {
            return null;
        }
        $query->whereRaw($this->gateway->constrainRelation($this->meta->targetKey, $values));
        $this->stableOrder($query);

        return $query->first();
    }

    protected ?Builder $query = null;

    /** @return list<Entity> */
    private function queryEntities(): array
    {
        $query = $this->query === null
            ? new Builder($this->meta->target, $this->gateway)
            : clone $this->query;
        $values = $this->sourceValues();
        if ($values === null) {
            return [];
        }
        $query->whereRaw($this->gateway->constrainRelation($this->meta->targetKey, $values));
        $this->stableOrder($query);

        return $query->get();
    }

    /**
     * 用户排序优先，主键仅作为未排序或同值时的稳定决胜条件。
     * hasOne/belongsTo 的 get() 因此与 first() 选择同一条记录。
     */
    private function stableOrder(Builder $query): void
    {
        $order = $query->relationShape()['order'];
        foreach ($this->gateway->meta($this->meta->target)->primaryKey as $field) {
            if (!in_array($field->column, $order, true)) {
                $query->orderBy($field->column);
            }
        }
    }

    /** @return list<int|float|string|bool|null>|null NULL 表示至少一侧键为空，不做 NULL 等值关联。 */
    private function sourceValues(): ?array
    {
        $values = [];
        foreach ($this->meta->sourceKey as $field) {
            if (!$this->source->propSet($field->property)) {
                throw new RelationException(
                    $this->meta->source . '::' . $this->meta->name . ' 缺少关联键 ' . $field->property,
                );
            }
            $value = $this->source->{$field->property};
            if ($value === null) {
                return null;
            }
            $values[] = $this->gateway->castQueryValue($this->meta->source, $field->column, $value);
        }

        return $values;
    }
}
