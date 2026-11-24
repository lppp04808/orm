<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Gateway\EntityGateway;

/**
 * Builder 的无副作用 SQL 调试预览。
 *
 * 只复用查询编译与写入校验，不执行 SQL、不触碰缓存、不触发生命周期钩子。
 * 输出包含明文参数，仅供人工诊断，不得代替参数化执行。
 *
 * @template T of Entity
 */
final readonly class SqlPreview
{
    private EntityGateway $gateway;
    private string $class;

    /** @param Builder<T> $builder */
    public function __construct(private Builder $builder)
    {
        $this->gateway = $builder->gateway();
        $this->class = $builder->modelClass();
    }

    public function get(): string
    {
        $this->builder->assertHydratableTerminal();

        return $this->render($this->builder->build());
    }

    public function first(): string
    {
        $this->builder->assertHydratableTerminal();

        return $this->render($this->limited()->build());
    }

    public function rows(): string
    {
        $this->builder->assertRawTerminal('rows()');

        return $this->render($this->builder->build());
    }

    public function row(): string
    {
        $this->builder->assertRawTerminal('row()');

        return $this->render($this->limited()->build());
    }

    public function value(?string $column = null): string
    {
        $this->builder->assertRawTerminal('value()');

        return $this->render($this->limited()->build());
    }

    public function count(string $column = '*'): string
    {
        $this->builder->assertCountTerminal();

        return $this->render($this->builder->countSql($column));
    }

    public function update(array $values): string
    {
        $this->assertWrite('conditional UPDATE');

        return $this->render($this->builder->buildUpdate($values));
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): string
    {
        return $this->delta($column, '+', $amount, $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): string
    {
        return $this->delta($column, '-', $amount, $extra);
    }

    public function delete(): string
    {
        $this->assertWrite('conditional DELETE');
        $softDelete = $this->gateway->meta($this->class)->softDeleteField();
        if ($softDelete === null) {
            return $this->render($this->builder->buildDelete());
        }

        return $this->render($this->builder->buildUpdate([
            $softDelete->property => $this->gateway->currentTimestamp($this->class, $softDelete->property),
        ]));
    }

    public function restore(): string
    {
        $this->assertWrite('conditional UPDATE');
        $field = $this->gateway->meta($this->class)->softDeleteField();
        if ($field === null) {
            throw new InvalidArgumentException($this->class . ' does not declare a soft-delete field');
        }
        $clone = clone $this->builder;
        $clone->state->softDeleteMode = 'only';
        $clone->state->cacheSoftDeleteMode = 'only';

        return $this->render($clone->buildUpdate([$field->property => null]));
    }

    public function forceDelete(): string
    {
        $this->assertWrite('conditional DELETE', true);

        return $this->render($this->builder->buildDelete());
    }

    private function delta(string $column, string $operator, int|float $amount, array $extra): string
    {
        $this->assertWrite('conditional UPDATE');

        return $this->render($this->builder->buildDeltaUpdate($column, $operator, $amount, $extra));
    }

    private function limited(): Builder
    {
        $clone = clone $this->builder;
        $clone->state->limit = 1;

        return $clone;
    }

    private function assertWrite(string $operation, bool $forceDelete = false): void
    {
        $this->builder->assertTerminal();
        $this->gateway->assertConditionalWrite($this->class, $operation, $forceDelete);
    }

    /** @param array{sql: string, params: array<string, int|float|string|bool|null>} $query */
    private function render(array $query): string
    {
        return SqlCompiler::interpolate($query['sql'], $query['params'], $this->gateway->driver());
    }
}
