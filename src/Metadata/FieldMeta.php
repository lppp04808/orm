<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use ReflectionNamedType;

/**
 * 一个持久化字段。常规读取以反射为准；缓存导入只重建真实属性类型。
 */
final readonly class FieldMeta
{
    public function __construct(
        public string $property,
        public string $column,
        public ReflectionNamedType $type,
        public bool $primary,
        public bool $generated,
        public int $order,
        public string $temporal,
        public ?string $temporalDisplayFormat = null,
        public ?string $temporalDisplayTimezone = null,
    ) {
    }

    public function typeName(): string
    {
        return $this->type->getName();
    }

    public function nullable(): bool
    {
        return $this->type->allowsNull();
    }
}
