<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Metadata;

/**
 * 一个模型的字段与主键。主键列已按 order 排好。
 *
 * @phpstan-type IdentityKind 'surrogate'|'natural'
 */
final readonly class ModelMeta
{
    /**
     * @param array<string, FieldMeta> $fields 属性名 => 字段
     * @param list<FieldMeta> $primaryKey 有序主键列
     * @param 'surrogate'|'natural' $identityKind
     * @param array{created_at?: true, updated_at?: true} $timestamps
     */
    public function __construct(
        public string $class,
        public string $table,
        public array $fields,
        public array $primaryKey,
        public string $identityKind,
        public array $timestamps,
    ) {
    }

    public function field(string $property): FieldMeta
    {
        return $this->fields[$property] ?? throw new \LogicException('未知字段: ' . $property);
    }

    /** @return list<string> */
    public function primaryColumns(): array
    {
        return array_map(static fn (FieldMeta $field): string => $field->column, $this->primaryKey);
    }

    public function generatedField(): ?FieldMeta
    {
        foreach ($this->primaryKey as $field) {
            if ($field->generated) {
                return $field;
            }
        }

        return null;
    }
}
