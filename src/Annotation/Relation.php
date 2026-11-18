<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

/** @internal 关系声明的公共键配置；键按顺序一一对应。 */
abstract readonly class Relation
{
    /**
     * @param class-string<\Pixiaoka\Orm\Entity> $target
     * @param list<string>|null $localKey 当前模型属性或列名，null 使用当前模型主键
     * @param list<string>|null $foreignKey 目标模型属性或列名，null 使用目标模型主键
     */
    public function __construct(
        public string $target,
        public ?array $localKey = null,
        public ?array $foreignKey = null,
    ) {
    }
}
