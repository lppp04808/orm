<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use Pixiaoka\Orm\Annotation\Column;
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Cast\Decimal;
use Pixiaoka\Orm\Annotation\PrimaryKey;
use Pixiaoka\Orm\Annotation\SoftDelete;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\Version;
use Pixiaoka\Orm\Cast\Castable;
use Pixiaoka\Orm\Annotation\BelongsTo;
use Pixiaoka\Orm\Annotation\HasMany;
use Pixiaoka\Orm\Annotation\HasOne;
use Pixiaoka\Orm\Annotation\Index;
use Pixiaoka\Orm\Annotation\Relation as RelationAnnotation;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;
use Pixiaoka\Orm\Schema\IndexSchema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * 字段与主键只从注解属性来。结果按类名缓存，主键顺序稳定。
 */
final class MetadataReader
{
    public const int CACHE_VERSION = 1;

    /** @var array<class-string<Entity>, array<string, mixed>> */
    private array $compiled = [];

    /**
     * @param array{version?: int, models?: array<class-string<Entity>, array<string, mixed>>} $cache
     *        可由 var_export() 写入 PHP 文件，并在部署时一次性加载。
     */
    public function __construct(array $cache = [])
    {
        if ($cache !== []) {
            $this->import($cache);
        }
    }

    /** @return array{version: int, models: array<class-string<Entity>, array<string, mixed>>} */
    public function export(): array
    {
        return ['version' => self::CACHE_VERSION, 'models' => $this->compiled];
    }

    /** @param array{version?: int, models?: array<class-string<Entity>, array<string, mixed>>} $cache */
    public function import(array $cache): void
    {
        if (($cache['version'] ?? null) !== self::CACHE_VERSION) {
            throw new MetadataException('元数据缓存版本不兼容，请重新生成');
        }
        if (!isset($cache['models']) || !is_array($cache['models'])) {
            throw new MetadataException('元数据缓存缺少 models');
        }
        $compiled = [];
        foreach ($cache['models'] as $class => $model) {
            if (!is_string($class) || !is_subclass_of($class, Entity::class) || !is_array($model)) {
                throw new MetadataException('元数据缓存包含无效模型');
            }
            $compiled[$class] = $model;
        }
        $this->cache = [];
        $this->relationCache = [];
        $this->validatedRelations = [];
        $this->compiled = $compiled;
    }
    /** @var array<class-string, ModelMeta> */
    private array $cache = [];

    /** @var array<class-string, array<string, RelationMeta>> */
    private array $relationCache = [];

    /** @var array<class-string, true> 第一次使用关系时一次性校验全部声明。 */
    private array $validatedRelations = [];

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
        if (isset($this->compiled[$class])) {
            return $this->cache[$class] = $this->hydrate($class, $this->compiled[$class]);
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

        $version = $this->version($class, $reflection, $byProperty, $timestamps);
        $softDelete = $this->softDelete($class, $reflection, $byProperty, $timestamps, $version);
        $indexes = $this->indexes($class, $reflection, $byProperty);
        $identityKind = $generated === [] ? 'natural' : 'surrogate';
        $this->compiled[$class] = [
            'table' => $table,
            'fields' => array_map(static fn (FieldMeta $field): array => [
                'property' => $field->property,
                'column' => $field->column,
                'type' => $field->typeName(),
                'nullable' => $field->nullable(),
                'primary' => $field->primary,
                'generated' => $field->generated,
                'order' => $field->order,
                'temporal' => $field->temporal,
            ], $byProperty),
            'primaryKey' => array_map(static fn (FieldMeta $field): string => $field->property, $primary),
            'identityKind' => $identityKind,
            'timestamps' => $timestamps,
            'version' => $version,
            'softDelete' => $softDelete,
            'indexes' => array_map(static fn (IndexSchema $index): array => [
                'name' => $index->name,
                'columns' => $index->columns,
                'unique' => $index->unique,
            ], $indexes),
        ];

        return $this->cache[$class] = new ModelMeta(
            $class,
            $table,
            $byProperty,
            $primary,
            $identityKind,
            $timestamps,
            $version,
            $softDelete,
            $indexes,
        );
    }

    /** @return array<string, RelationMeta> 只有显式加载或 Relation::get() 时才解析关系。 */
    public function relations(string $class): array
    {
        if (isset($this->relationCache[$class])) {
            return $this->relationCache[$class];
        }
        $this->read($class);
        $reflection = new ReflectionClass($class);
        $relations = [];
        $kinds = [HasOne::class => 'hasOne', HasMany::class => 'hasMany', BelongsTo::class => 'belongsTo'];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $found = [];
            $name = $method->getName();
            $declaredKinds = [];
            foreach ($kinds as $annotation => $kind) {
                $attributes = $method->getAttributes($annotation);
                if ($attributes === []) {
                    continue;
                }
                $declaredKinds[] = $kind;
                if (count($attributes) > 1) {
                    throw new MetadataException("$class::$name 不能重复声明关系 $kind");
                }
                $found[] = [$annotation, $kind, $attributes[0]];
            }
            if ($found === []) {
                continue;
            }
            if (count($found) > 1) {
                throw new MetadataException(
                    "$class::$name 不能同时声明多种关系 " . implode('、', $declaredKinds),
                );
            }
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                || $method->getNumberOfParameters() > 0) {
                throw new MetadataException("$class::$name 关系方法必须是无参数 public 实例方法");
            }
            $type = $method->getReturnType();
            $expected = $kinds[$found[0][0]];
            $relationClass = match ($expected) {
                'hasOne' => \Pixiaoka\Orm\Relation\HasOne::class,
                'hasMany' => \Pixiaoka\Orm\Relation\HasMany::class,
                'belongsTo' => \Pixiaoka\Orm\Relation\BelongsTo::class,
            };
            if (!$type instanceof ReflectionNamedType || $type->getName() !== $relationClass) {
                throw new MetadataException("$class::$name 返回类型必须声明为 $relationClass");
            }
            /** @var RelationAnnotation $annotation */
            $annotation = $found[0][2]->newInstance();
            if (!is_subclass_of($annotation->target, Entity::class)) {
                throw new MetadataException("$class::$name target 必须继承 " . Entity::class);
            }
            $relations[$name] = RelationMeta::from(
                $name,
                $class,
                $this->read($class),
                $this->read($annotation->target),
                $annotation,
                $found[0][1],
            );
        }

        return $this->relationCache[$class] = $relations;
    }

    /** 只有进入关系功能才扫描并验证所有关系声明，普通 CRUD 不付这笔成本。 */
    public function assertRelational(string $class): void
    {
        if (isset($this->validatedRelations[$class])) {
            return;
        }
        $this->relations($class);
        $this->validatedRelations[$class] = true;
    }

    public function relation(string $class, string $name): RelationMeta
    {
        $this->assertRelational($class);

        return $this->relationCache[$class][$name]
            ?? throw new MetadataException($class . ' 未声明关系 ' . $name);
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

    /**
     * @param class-string<Entity> $class
     * @param ReflectionClass<Entity> $reflection
     * @param array<string, FieldMeta> $fields
     * @param array{created_at?: string, updated_at?: string} $timestamps
     */
    private function softDelete(
        string $class,
        ReflectionClass $reflection,
        array $fields,
        array $timestamps,
        ?string $version,
    ): ?string {
        $softDelete = null;
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(SoftDelete::class) === []) {
                continue;
            }
            $name = $property->getName();
            $field = $fields[$name] ?? throw new MetadataException("$class::$name 软删除字段必须同时声明 #[Column]");
            if ($softDelete !== null) {
                throw new MetadataException("$class::$name 一个模型只能声明一个软删除字段");
            }
            if ($field->primary || $field->generated || $name === $version || in_array($name, $timestamps, true)) {
                throw new MetadataException("$class::$name 软删除字段不能兼任主键、生成列、版本或自动时间字段");
            }
            if ($field->typeName() !== \DateTimeImmutable::class || !$field->nullable()) {
                throw new MetadataException("$class::$name 软删除字段必须是可空 DateTimeImmutable");
            }
            $softDelete = $name;
        }

        return $softDelete;
    }

    /**
     * @param class-string<Entity> $class
     * @param ReflectionClass<Entity> $reflection
     * @param array<string, FieldMeta> $fields
     * @return list<IndexSchema>
     */
    private function indexes(string $class, ReflectionClass $reflection, array $fields): array
    {
        $indexes = [];
        foreach ($reflection->getAttributes(Index::class) as $attribute) {
            $index = $attribute->newInstance();
            if (isset($indexes[$index->name])) {
                throw new MetadataException($class . ' 索引名称重复: ' . $index->name);
            }
            $columns = [];
            foreach ($index->columns as $property) {
                $field = $fields[$property] ?? null;
                if ($field === null) {
                    foreach ($fields as $candidate) {
                        if ($candidate->column === $property) {
                            $field = $candidate;
                            break;
                        }
                    }
                }
                if ($field === null) {
                    throw new MetadataException($class . ' 索引 ' . $index->name . ' 包含未知字段 ' . $property);
                }
                $columns[] = $field->column;
            }
            $indexes[$index->name] = new IndexSchema($index->name, $columns, $index->unique);
        }

        return array_values($indexes);
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
                || is_a($name, Decimal::class, true)
                || is_a($name, Castable::class, true);
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

    /**
     * 缓存仍以实际类定义为最终校验依据，损坏或部署后过期的缓存不会静默生效。
     *
     * @param class-string<Entity> $class
     * @param array<string, mixed> $model
     */
    private function hydrate(string $class, array $model): ModelMeta
    {
        try {
            $table = $model['table'] ?? null;
            $definitions = $model['fields'] ?? null;
            $primaryNames = $model['primaryKey'] ?? null;
            $identityKind = $model['identityKind'] ?? null;
            $timestamps = $model['timestamps'] ?? null;
            $version = $model['version'] ?? null;
            $softDelete = $model['softDelete'] ?? null;
            if (!is_string($table) || $table !== $class::table() || !is_array($definitions)
                || !is_array($primaryNames) || !in_array($identityKind, ['natural', 'surrogate'], true)
                || !is_array($timestamps) || (!is_string($version) && $version !== null)
                || (!is_string($softDelete) && $softDelete !== null)) {
                throw new MetadataException($class . ' 元数据缓存结构无效或已过期');
            }
            $reflection = new ReflectionClass($class);
            $fields = [];
            foreach ($definitions as $property => $definition) {
                if (!is_string($property) || !is_array($definition)) {
                    throw new MetadataException($class . ' 元数据缓存字段无效');
                }
                $type = $definition['type'] ?? null;
                $column = $definition['column'] ?? null;
                $nullable = $definition['nullable'] ?? null;
                $temporal = $definition['temporal'] ?? null;
                if (!is_string($type) || !is_string($column) || !is_bool($nullable) || !is_string($temporal)) {
                    throw new MetadataException("$class::$property 元数据缓存字段类型无效");
                }
                $propertyReflection = $reflection->getProperty($property);
                $actualType = $propertyReflection->getType();
                if (!$actualType instanceof ReflectionNamedType || $actualType->getName() !== $type
                    || $actualType->allowsNull() !== $nullable) {
                    throw new MetadataException("$class::$property 元数据缓存与属性类型不一致，请重新生成");
                }
                foreach (['primary', 'generated'] as $flag) {
                    if (!is_bool($definition[$flag] ?? null)) {
                        throw new MetadataException("$class::$property 元数据缓存标记无效");
                    }
                }
                if (!is_int($definition['order'] ?? null)) {
                    throw new MetadataException("$class::$property 元数据缓存顺序无效");
                }
                $fields[$property] = new FieldMeta(
                    $property,
                    $column,
                    $actualType,
                    $definition['primary'],
                    $definition['generated'],
                    $definition['order'],
                    $temporal,
                );
            }
            $primary = [];
            foreach ($primaryNames as $property) {
                if (!is_string($property) || !isset($fields[$property]) || !$fields[$property]->primary) {
                    throw new MetadataException($class . ' 元数据缓存主键无效');
                }
                $primary[] = $fields[$property];
            }
            if ($primary === [] || $fields === []) {
                throw new MetadataException($class . ' 元数据缓存缺少字段或主键');
            }
            /** @var array{created_at?: string, updated_at?: string} $timestamps */
            foreach ($timestamps as $role => $property) {
                if (!in_array($role, ['created_at', 'updated_at'], true)
                    || !is_string($property) || !isset($fields[$property])) {
                    throw new MetadataException($class . ' 元数据缓存时间字段无效');
                }
            }
            if ($version !== null && (!isset($fields[$version]) || $fields[$version]->primary)) {
                throw new MetadataException($class . ' 元数据缓存版本字段无效');
            }

            if ($softDelete !== null && !isset($fields[$softDelete])) {
                throw new MetadataException($class . ' 元数据缓存软删除字段无效');
            }
            $indexes = [];
            $definitions = $model['indexes'] ?? [];
            if (!is_array($definitions)) {
                throw new MetadataException($class . ' 元数据缓存索引无效');
            }
            $columns = array_map(static fn (FieldMeta $field): string => $field->column, $fields);
            foreach ($definitions as $definition) {
                if (!is_array($definition) || !is_string($definition['name'] ?? null)
                    || !is_array($definition['columns'] ?? null) || !is_bool($definition['unique'] ?? null)) {
                    throw new MetadataException($class . ' 元数据缓存索引结构无效');
                }
                foreach ($definition['columns'] as $column) {
                    if (!is_string($column) || !in_array($column, $columns, true)) {
                        throw new MetadataException($class . ' 元数据缓存索引字段无效');
                    }
                }
                $indexes[] = new IndexSchema($definition['name'], $definition['columns'], $definition['unique']);
            }

            return new ModelMeta($class, $table, $fields, $primary, $identityKind, $timestamps, $version, $softDelete, $indexes);
        } catch (MetadataException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MetadataException($class . ' 元数据缓存无效: ' . $e->getMessage(), 0, $e);
        }
    }
}