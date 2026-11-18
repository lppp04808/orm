<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Relation;

/** @internal 有序预加载节点，避免 PHP 数组数字字符串键改变关系路径。 */
final class RelationSpec
{
    /** @var array<string, self> */
    public array $children = [];

    /** @var callable|null */
    public $constraint = null;

    public function __construct(public readonly string $name)
    {
    }
}
