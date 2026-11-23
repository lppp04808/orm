<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use Pixiaoka\Orm\Relation\Subquery;

/** @internal Builder 的全部可变查询状态；克隆时隔离查询图，不克隆业务值对象。 */
final class QueryState
{
    /** @var list<array{column: string, operator: string, value: mixed}> */
    public array $where = [];

    /** @var list<array{conditions: list<array{column: string, operator: string, value: mixed}>, groups: array}> */
    public array $orGroups = [];

    /** @var list<array{kind: 'column'|'as'|'raw'|'aggregate', column?: string, alias?: string, sql?: string, params?: array, function?: string, distinct?: bool, direction?: string}> */
    public array $orderBy = [];

    /** @var list<array{kind: 'column'|'as'|'raw'|'aggregate', column?: string, alias?: string, sql?: string, params?: array, function?: string, distinct?: bool}> */
    public array $projection = [];

    public bool $distinct = false;
    public ?string $tableAlias = null;
    public ?string $relationAlias = null;

    /** @var list<array{relation: Subquery, alias: string}> */
    public array $relationCounts = [];

    public bool $buildingRelationConstraint = false;
    public bool $correlatedRelation = false;
    public bool $relationConstraintValidated = false;

    /** @var array{builder: Builder, alias: string}|null */
    public ?array $derivedFrom = null;

    /** @var list<array{type: string, table?: string, alias?: string, on?: JoinClause, raw?: string, params?: array}> */
    public array $joins = [];

    /** @var list<string> */
    public array $groupBy = [];

    /** @var list<array{kind: 'structured'|'raw', expression?: string, operator?: string, value?: mixed, sql?: string, params?: array}> */
    public array $having = [];

    /** @var list<array{builder: Builder, all: bool}> */
    public array $unions = [];

    /** @var list<array{kind: string, column?: string, direction?: string, sql?: string, params?: array}>|null */
    public ?array $unionFirstOrderBy = null;
    public ?int $unionFirstLimit = null;
    public int $unionFirstOffset = 0;
    public int $offset = 0;
    public ?int $limit = null;
    public ?string $lockMode = null;

    /** @var 'default'|'with'|'only' */
    public string $softDeleteMode = 'default';

    /** @var 'default'|'with'|'only' */
    public string $cacheSoftDeleteMode = 'default';

    /** @var array<int|string, mixed> */
    public array $eagerRelations = [];
    public bool $nestedUnion = false;

    public function __clone()
    {
        $this->where = $this->copyValue($this->where);
        $this->orGroups = $this->copyValue($this->orGroups);
        $this->orderBy = $this->copyValue($this->orderBy);
        $this->projection = $this->copyValue($this->projection);
        $this->relationCounts = $this->copyValue($this->relationCounts);
        $this->derivedFrom = $this->copyValue($this->derivedFrom);
        $this->joins = $this->copyValue($this->joins);
        $this->groupBy = $this->copyValue($this->groupBy);
        $this->having = $this->copyValue($this->having);
        $this->unions = $this->copyValue($this->unions);
        $this->unionFirstOrderBy = $this->copyValue($this->unionFirstOrderBy);
        $this->eagerRelations = $this->copyValue($this->eagerRelations);
        $this->nestedUnion = false;
    }

    /** @return mixed 仅复制 ORM 拥有的查询结构和子查询，保留条件中的值对象身份。 */
    private function copyValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = $this->copyValue($item);
            }

            return $copy;
        }
        if ($value instanceof Builder || $value instanceof JoinClause || $value instanceof Subquery) {
            return clone $value;
        }

        return $value;
    }
}
