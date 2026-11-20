<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Relation;

use Pixiaoka\Orm\Exception\RelationException;

/** @internal 延迟到父查询的渲染上下文中生成相关子查询，确保参数和别名不重复。 */
final class Subquery
{
    public $constraint;

    public function __construct(
        public string $path,
        ?callable $constraint = null,
    ) {
        if ($path === '' || in_array('', explode('.', $path), true)) {
            throw new RelationException('关系路径不能包含空名称');
        }
        $this->constraint = $constraint;
    }
}
