<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use ReflectionNamedType;

/**
 * 一个持久化字段。类型以反射为准，不接受手写的类型字符串映射。
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
