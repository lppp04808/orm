<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\Version;
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
            /** @var list<\ReflectionAttribute<Column>> $columnAttr */
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
        $timestamps = $this->timestamps($class, $reflection, $byProperty);

        return $this->cache[$class] = new ModelMeta(
            $class,
            $table,
            $byProperty,
            $primary,
            $generated === [] ? 'natural' : 'surrogate',
            $timestamps,
            $this->version($class, $reflection, $byProperty, $timestamps),
        );
    }

    /**
     * 显式时间角色优先；兼容传统 int created_at/updated_at。
     *
     * @param class-string<Entity> $class
     * @param ReflectionClass<Entity> $reflection
     * @param array<string, FieldMeta> $fields
     * @return array{created_at?: string, updated_at?: string}
     */
    private function timestamps(string $class, ReflectionClass $reflection, array $fields): array
    {
        $ts = [];
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if (!isset($fields[$name])) {
                if ($property->getAttributes(CreatedAt::class) !== []
                    || $property->getAttributes(UpdatedAt::class) !== []) {
                    throw new MetadataException("$class::$name 时间角色必须同时声明 #[Column]");
                }
                continue;
            }
            $field = $fields[$name];
            $roles = [];
            if ($property->getAttributes(CreatedAt::class) !== []) {
                $roles[] = 'created_at';
            }
            if ($property->getAttributes(UpdatedAt::class) !== []) {
                $roles[] = 'updated_at';
            }
            if (count($roles) > 1) {
                throw new MetadataException("$class::$name 创建时间和更新时间角色不能共用同一字段");
            }
            foreach ($roles as $role) {
                if (isset($ts[$role])) {
                    throw new MetadataException("$class::$name 时间角色 $role 重复声明");
                }
                if ($field->primary) {
                    throw new MetadataException("$class::$name 主键不能作为自动时间角色");
                }
                if (!in_array($field->typeName(), ['int', \DateTimeImmutable::class], true)) {
                    throw new MetadataException("$class::$name 自动时间角色只支持 int 或 DateTimeImmutable");
                }
                $ts[$role] = $name;
            }
        }
        foreach (['created_at', 'updated_at'] as $role) {
            if (!isset($ts[$role]) && isset($fields[$role]) && $fields[$role]->typeName() === 'int') {
                $ts[$role] = $role;
            }
        }

        return $ts;
    }

    /**
     * @param class-string<Entity> $class
     * @param ReflectionClass<Entity> $reflection
     * @param array<string, FieldMeta> $fields
     * @param array{created_at?: string, updated_at?: string} $timestamps
     */
    private function version(string $class, ReflectionClass $reflection, array $fields, array $timestamps): ?string
    {
        $version = null;
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Version::class) === []) {
                continue;
            }
            $name = $property->getName();
            $field = $fields[$name] ?? throw new MetadataException("$class::$name 版本字段必须同时声明 #[Column]");
            if ($version !== null) {
                throw new MetadataException("$class::$name 一个模型只能声明一个版本字段，$version 已声明");
            }
            if ($field->primary || $field->generated) {
                throw new MetadataException("$class::$name 版本字段不能兼任主键或生成列");
            }
            if (in_array($name, $timestamps, true)) {
                throw new MetadataException("$class::$name 版本字段不能兼任自动时间字段");
            }
            if ($field->typeName() !== 'int' || $field->nullable()) {
                throw new MetadataException("$class::$name 版本字段必须是非空 int");
            }
            $version = $name;
        }

        return $version;
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