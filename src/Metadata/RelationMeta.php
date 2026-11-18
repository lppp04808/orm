<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

use Pixiaoka\Orm\Annotation\Relation as RelationAnnotation;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\MetadataException;

/** 一个已经过两侧键和类型校验的只读关系。 */
final readonly class RelationMeta
{
    /**
     * @param class-string<Entity> $source
     * @param class-string<Entity> $target
     * @param 'hasOne'|'hasMany'|'belongsTo' $kind
     * @param list<FieldMeta> $sourceKey
     * @param list<FieldMeta> $targetKey
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $target,
        public string $kind,
        public array $sourceKey,
        public array $targetKey,
    ) {
    }

    public function many(): bool
    {
        return $this->kind === 'hasMany';
    }

    /** @param class-string<Entity> $source */
    public static function from(
        string $name,
        string $source,
        ModelMeta $sourceMeta,
        ModelMeta $targetMeta,
        RelationAnnotation $relation,
        string $kind,
    ): self {
        $sourceFields = $relation->localKey === null
            ? $sourceMeta->primaryKey
            : self::fields($source, $name, 'localKey', $sourceMeta, $relation->localKey);
        $targetFields = $relation->foreignKey === null
            ? $targetMeta->primaryKey
            : self::fields($source, $name, 'foreignKey', $targetMeta, $relation->foreignKey);

        if (count($sourceFields) !== count($targetFields)) {
            throw new MetadataException("$source::$name 关系两侧键数量必须相同");
        }
        foreach ($sourceFields as $index => $field) {
            $target = $targetFields[$index];
            if ($field->typeName() !== $target->typeName()
                || $field->temporal !== $target->temporal) {
                throw new MetadataException(
                    "$source::$name 键 {$field->property} 与 {$target->property} 的类型或时间存储形式不匹配",
                );
            }
        }

        return new self($name, $source, $targetMeta->class, $kind, $sourceFields, $targetFields);
    }

    /** @return list<FieldMeta> */
    private static function fields(
        string $source,
        string $name,
        string $argument,
        ModelMeta $meta,
        array $keys,
    ): array {
        if ($keys === [] || array_is_list($keys) === false) {
            throw new MetadataException("$source::$name $argument 必须是非空有序字符串数组");
        }
        $fields = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new MetadataException("$source::$name $argument 只能包含非空字段名");
            }
            $field = null;
            foreach ($meta->fields as $candidate) {
                if ($candidate->property === $key || $candidate->column === $key) {
                    $field = $candidate;
                    break;
                }
            }
            $field ??= throw new MetadataException("$source::$name $argument 包含未知字段 $key");
            if (isset($fields[$field->property])) {
                throw new MetadataException("$source::$name 不能重复使用键 {$field->property}");
            }
            $fields[$field->property] = $field;
        }

        return array_values($fields);
    }
}
