<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;

/**
 * 一次 SQL 构建的独立参数作用域。
 * 同一 Builder 重复 build 均从 q0 开始；raw 占位符在此作用域内唯一化。
 */
final class RenderContext
{
    /** @var array<string, int|float|string|bool|null> */
    private array $params = [];
    private int $index = 0;
    private int $relationAlias = 0;

    /** @var array<string, true> 外部父表、用户别名及关系目标的真实表名。 */
    private array $relationQualifiers = [];

    /** @var array<string, true> 已分配的内部关联别名，与外部限定名分开登记。 */
    private array $relationAliases = [];

    /**
     * 同一次递归渲染共享别名作用域，重复 build 会重新从零编号。
     * 顶层真实表名/用户别名也属于祖先，不能被更深层内部别名遮蔽。
     */
    public function reserveRelationQualifier(string $qualifier): void
    {
        $normalized = strtolower($qualifier);
        if ($this->hasRelationAlias($qualifier) || preg_match('/^_orm_relation_[1-9][0-9]*$/i', $qualifier) === 1) {
            // 外部父表不得占用内部保留命名空间；更深层展开也必须能安全分配别名。
            throw new InvalidArgumentException('Table name or alias conflicts with an allocated ORM relation alias: ' . $qualifier);
        }
        $this->relationQualifiers[$normalized] = true;
    }

    /** 父表及祖先限定名是否与新分配的内部别名冲突。 */
    public function hasRelationQualifier(string $qualifier): bool
    {
        return isset($this->relationQualifiers[strtolower($qualifier)]);
    }

    /**
     * 分配不会被外部父表、祖先或目标真实表名遮蔽的内部别名。
     * 编号只在无冲突时推进；嵌套内部别名不再作为外部限定名重复登记。
     */
    public function relationAlias(): string
    {
        $next = $this->relationAlias + 1;
        $alias = '_orm_relation_' . $next;
        if ($this->hasRelationQualifier($alias)) {
            throw new InvalidArgumentException('Parent table alias or actual table name conflicts with an ORM relation alias: ' . $alias);
        }
        $this->relationAlias = $next;
        $this->relationAliases[strtolower($alias)] = true;

        return $alias;
    }

    /** 已分配的内部别名是否会被一个后登记的外部限定名遮蔽。 */
    public function hasRelationAlias(string $alias): bool
    {
        return isset($this->relationAliases[strtolower($alias)]);
    }

    public function bind(int|float|string|bool|null $value): string
    {
        $name = 'q' . $this->index++;
        $this->params[$name] = $value;

        return ':' . $name;
    }

    /**
     * 合并开发者编写的 SQL 片段，严格匹配命名参数并重新编号。
     * 扫描跳过引号、注释、反引号和 PostgreSQL :: 类型转换。
     *
     * @param array<string, int|float|string|bool|null> $rawParams
     */
    public function mergeRaw(string $sql, array $rawParams, ?string $driver = null): string
    {
        foreach ($rawParams as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException('Raw SQL parameter names must match named placeholders');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Raw SQL parameter values must be scalar or null');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Raw SQL floating-point parameter values must be finite');
            }
        }

        $used = [];
        $out = \Pixiaoka\Orm\Sql\SqlLexer::replacePlaceholders($sql, $driver, function (string $name) use ($rawParams, &$used): string {
            if (!array_key_exists($name, $rawParams)) {
                throw new InvalidArgumentException('Raw SQL is missing parameter: ' . $name);
            }
            $used[$name] = true;

            return $this->bind($rawParams[$name]);
        });

        $missing = array_diff(array_keys($rawParams), array_keys($used));
        if ($missing !== []) {
            throw new InvalidArgumentException('Raw SQL has unused parameters: ' . implode(', ', $missing));
        }

        return $out;
    }

    /** @return array<string, int|float|string|bool|null> */
    public function params(): array
    {
        return $this->params;
    }
}
