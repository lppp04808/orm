<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * 字段与主键只从注解属性来。结果按类名缓存，主键顺序稳定。
 */
final class MetadataReader
{
    /** @var array<class-string, ModelMeta> */
    private array $cache = [];

    /**
     * @param class-string<Entity> $class
     */
    public function read(string $class): ModelMeta
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        if (!is_subclass_of($class, Entity::class)) {
            throw new MetadataException($class . ' 必须继承 Pixiaoka\Orm\\Entity');
        }

        $reflection = new ReflectionClass($class);
        $table = $class::table();
        $fields = [];
        $declaration = 0;

        foreach ($reflection->getProperties() as $property) {
            /**
             * @var \ReflectionAttribute<Column[]> $columnAttr
             */
            $columnAttr = $property->getAttributes(Column::class);
            if (empty($columnAttr)) {
                continue;
            }

            $fields[] = $this->field($class, $property, $columnAttr[0]->newInstance(), $declaration);
            $declaration++;
        }

        if ($fields === []) {
            throw new MetadataException($class . ' 没有持久化字段');
        }

        $primary = array_values(array_filter($fields, static fn (FieldMeta $field): bool => $field->primary));
        if ($primary === []) {
            throw new MetadataException($class . ' 没有主键');
        }

        usort($primary, static function (FieldMeta $a, FieldMeta $b): int {
            return [$a->order, $a->property] <=> [$b->order, $b->property];
        });

        $generated = array_values(array_filter($primary, static fn (FieldMeta $field): bool => $field->generated));
        if (count($generated) > 1) {
            throw new MetadataException($class . ' 只能有一个生成列');
        }
        if ($generated !== [] && count($primary) !== 1) {
            throw new MetadataException($class . ' 生成列不能和自然键组成复合主键');
        }
        foreach ($primary as $field) {
            if ($field->nullable()) {
                throw new MetadataException($class . '::' . $field->property . ' 主键列不可空');
            }
        }

        $byProperty = [];
        foreach ($fields as $field) {
            $byProperty[$field->property] = $field;
        }

        return $this->cache[$class] = new ModelMeta(
            $class,
            $table,
            $byProperty,
            $primary,
            $generated === [] ? 'natural' : 'surrogate',
            $this->timestamps($byProperty),
        );
    }

    /**
     * @param array<string, FieldMeta> $fields
     * @return array{created_at?: true, updated_at?: true}
     */
    private function timestamps(array $fields): array
    {
        $ts = [];
        foreach ($fields as $property => $field) {
            if ($property === 'created_at' && $field->typeName() === 'int') {
                $ts['created_at'] = true;
            }
            if ($property === 'updated_at' && $field->typeName() === 'int') {
                $ts['updated_at'] = true;
            }
        }

        return $ts;
    }

    private function field(string $class, ReflectionProperty $property, Column $column, int $declaration): FieldMeta
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new MetadataException($class . '::' . $property->getName() . ' 必须是具名类型');
        }
        if (!$type->isBuiltin() && !class_exists($type->getName()) && !enum_exists($type->getName())) {
            throw new MetadataException($class . '::' . $property->getName() . ' 类/枚举不存在');
        }

        $primaryAttr = $property->getAttributes(PrimaryKey::class);
        $primary = $primaryAttr === [] ? null : $primaryAttr[0]->newInstance();

        $temporal = Temporal::TEXT;
        $temporalAttr = $property->getAttributes(Temporal::class);
        if ($temporalAttr !== []) {
            $temporal = $temporalAttr[0]->newInstance()->storage;
            if (!in_array($temporal, [Temporal::TEXT, Temporal::UNIX], true)) {
                throw new MetadataException($class . '::' . $property->getName() . ' 时间存储形式不合法');
            }
        }

        $name = $type->getName();
        $supported = $type->isBuiltin()
            ? in_array($name, ['int', 'float', 'string', 'bool', 'array'], true)
            : is_a($name, \BackedEnum::class, true)
                || $name === \DateTimeImmutable::class
                || is_a($name, Decimal::class, true);
        if (!$supported) {
            throw new MetadataException($class . '::' . $property->getName() . ' 不支持类型 ' . $name);
        }
        if ($temporalAttr !== [] && $name !== \DateTimeImmutable::class) {
            throw new MetadataException($class . '::' . $property->getName() . ' 只有 DateTimeImmutable 可以声明时间存储');
        }

        return new FieldMeta(
            $property->getName(),
            $column->name ?? $property->getName(),
            $type,
            $primaryAttr !== [],
            $primary?->generated ?? false,
            $primary?->order ?? $declaration,
            $temporal,
        );
    }
}