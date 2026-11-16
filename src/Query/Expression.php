<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;

/**
 * 开发者手写的 SQL 片段 + 绑定参数。仅开发者可写 SQL，值始终参数化。
 *
 * 用在 selectRaw / whereRaw / havingRaw / orderByRaw / joinRaw 与完整 SQL 逃生舱。
 * 命名占位符由调用方声明（:name），由 RenderContext 安全改写为唯一名后绑定。
 */
final readonly class Expression
{
    /**
     * @param array<string, int|float|string|bool|null> $params 命名占位符 => 值
     */
    public function __construct(
        public string $sql,
        public array $params = [],
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidArgumentException('Raw SQL 不能为空');
        }
    }

    /**
     * 返回可直接交给 Connection 执行的 {sql, params}。
     * 占位符保持原样命名（调用方 SQL 自带 :name）。
     *
     * @return array{sql: string, params: array<string, int|float|string|bool|null>}
     */
    public function build(): array
    {
        return ['sql' => $this->sql, 'params' => $this->params];
    }

    /** 工厂方法，等价于 new Expression()。 */
    public static function raw(string $sql, array $params = []): self
    {
        return new self($sql, $params);
    }
}
