<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Cast;

use DateTimeImmutable;
use DateTimeZone;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Exception\CastException;
use Pixiaoka\Orm\Metadata\FieldMeta;

/**
 * 持久化进出的唯一转换边界。
 * 进：数据库值 -> 属性声明类型。出：属性值 -> 可绑定的数据库值。
 * 失败抛 CastException，绝不返回零值冒充成功。
 */
final class Caster
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function toPhp(string $model, FieldMeta $field, mixed $value): mixed
    {
        if ($value === null) {
            if ($field->nullable()) {
                return null;
            }
            throw $this->fail($model, $field, '非空字段收到 NULL');
        }

        $name = $field->typeName();

        return match ($name) {
            'int' => $this->toInt($model, $field, $value),
            'float' => $this->toFloat($model, $field, $value),
            'string' => $this->toString($model, $field, $value),
            'bool' => $this->toBool($model, $field, $value),
            'array' => $this->toArray($model, $field, $value),
            default => $this->toObject($model, $field, $value),
        };
    }

    public function toDb(string $model, FieldMeta $field, mixed $value): int|float|string|null
    {
        if ($value === null) {
            if ($field->nullable()) {
                return null;
            }
            throw $this->fail($model, $field, '非空字段写出 null');
        }

        $name = $field->typeName();

        if ($name === 'bool') {
            if (!is_bool($value)) {
                throw $this->fail($model, $field, '写出的 bool 不是布尔');
            }

            return $value ? 1 : 0;
        }

        if ($name === 'array') {
            if (!is_array($value)) {
                throw $this->fail($model, $field, '写出的 array 不是数组');
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw $this->fail($model, $field, '数组无法编码为 JSON');
            }

            return $json;
        }

        if (is_a($name, Decimal::class, true)) {
            if (!$value instanceof Decimal) {
                throw $this->fail($model, $field, '写出的十进数类型不对');
            }

            return (string) $value;
        }

        if ($name === DateTimeImmutable::class) {
            if (!$value instanceof DateTimeImmutable) {
                throw $this->fail($model, $field, '写出的时间不是 DateTimeImmutable');
            }
            $utc = $value->setTimezone(new DateTimeZone('UTC'));
            if ($field->temporal === Temporal::UNIX) {
                return $utc->getTimestamp();
            }

            return $utc->format(self::TIME_FORMAT);
        }

        if (is_a($name, \BackedEnum::class, true)) {
            if (!$value instanceof \BackedEnum) {
                throw $this->fail($model, $field, '写出的枚举类型不对');
            }

            return $value->value;
        }

        if (is_a($name, Castable::class, true)) {
            if (!$value instanceof $name) {
                throw $this->fail($model, $field, '写出的 Castable 类型不对');
            }
            try {
                return $value->toDatabase();
            } catch (\Throwable $e) {
                throw $this->fail($model, $field, 'Castable 无法序列化: ' . $e->getMessage());
            }
        }

        if ($name === 'int') {
            if (!is_int($value)) {
                throw $this->fail($model, $field, '写出的 int 不是整数');
            }

            return $value;
        }
        if ($name === 'float') {
            if (!is_float($value) && !is_int($value)) {
                throw $this->fail($model, $field, '写出的 float 不是数字');
            }

            return (float) $value;
        }
        if ($name === 'string') {
            if (!is_string($value)) {
                throw $this->fail($model, $field, '写出的 string 不是字符串');
            }

            return $value;
        }

        throw $this->fail($model, $field, '不支持的写出类型');
    }

    /**
     * 将实体值或外部标量规范化为数据库值；已是目标值对象时不做无效往返。
     *
     * @internal
     */
    public function toDbNormalized(string $model, FieldMeta $field, mixed $value): int|float|string|null
    {
        $name = $field->typeName();
        $complex = !$field->type->isBuiltin()
            && ($name === DateTimeImmutable::class
                || is_a($name, Decimal::class, true)
                || is_a($name, \BackedEnum::class, true)
                || is_a($name, Castable::class, true));
        if ($complex && $value instanceof $name) {
            return $this->toDb($model, $field, $value);
        }

        return $this->toDb($model, $field, $this->toPhp($model, $field, $value));
    }

    /** @internal 自动时间戳统一使用秒级 UTC 值，供单实体与批量写入共用。 */
    public function currentTimestamp(FieldMeta $field, ?int $now = null): int|DateTimeImmutable
    {
        if ($field->typeName() === 'int') {
            return $now ?? time();
        }

        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('UTC'));
    }

    private function toInt(string $model, FieldMeta $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw $this->fail($model, $field, '无法解析为 int');
    }

    private function toFloat(string $model, FieldMeta $field, mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        throw $this->fail($model, $field, '无法解析为 float');
    }

    private function toString(string $model, FieldMeta $field, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw $this->fail($model, $field, '无法解析为 string');
    }

    private function toBool(string $model, FieldMeta $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value === 1 || $value === '1';
        }
        throw $this->fail($model, $field, 'bool 只接受 0/1/true/false');
    }

    private function toArray(string $model, FieldMeta $field, mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw $this->fail($model, $field, 'array 只接受 JSON 文本或数组');
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->fail($model, $field, 'JSON 无法解码');
        }
        if (!is_array($decoded)) {
            throw $this->fail($model, $field, 'JSON 解码结果不是数组');
        }

        return $decoded;
    }

    private function toDecimal(string $model, FieldMeta $field, mixed $value): Decimal
    {
        $name = $field->typeName();
        if ($value instanceof $name) {
            return $value;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) {
            throw $this->fail($model, $field, '无法解析为十进数');
        }
        try {
            $decimal = $name::fromString((string) $value);
        } catch (\Throwable) {
            throw $this->fail($model, $field, '无法解析为十进数');
        }
        if (!$decimal instanceof Decimal) {
            throw $this->fail($model, $field, '十进数类型没有实现 Decimal');
        }

        return $decimal;
    }

    private function toObject(string $model, FieldMeta $field, mixed $value): mixed
    {
        $name = $field->typeName();
        if ($name === DateTimeImmutable::class) {
            return $this->toTime($model, $field, $value);
        }
        if (is_a($name, Decimal::class, true)) {
            return $this->toDecimal($model, $field, $value);
        }
        if (is_a($name, Castable::class, true)) {
            return $this->toCastable($model, $field, $value);
        }

        return $this->toEnum($model, $field, $value);
    }

    private function toCastable(string $model, FieldMeta $field, mixed $value): Castable
    {
        $name = $field->typeName();
        if ($value instanceof $name) {
            return $value;
        }
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw $this->fail($model, $field, 'Castable 只接受标量数据库值');
        }
        try {
            $object = $name::fromDatabase($value);
        } catch (\Throwable $e) {
            throw $this->fail($model, $field, 'Castable 无法反序列化: ' . $e->getMessage());
        }
        if (!$object instanceof $name) {
            throw $this->fail($model, $field, 'Castable::fromDatabase() 返回类型不对');
        }

        return $object;
    }

    private function toTime(string $model, FieldMeta $field, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($field->temporal === Temporal::UNIX) {
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                return (new DateTimeImmutable('@' . (int) $value))->setTimezone(new DateTimeZone('UTC'));
            }
            throw $this->fail($model, $field, '无法解析为 Unix 时间');
        }
        if (!is_string($value)) {
            throw $this->fail($model, $field, '时间文本不是字符串');
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . self::TIME_FORMAT, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw $this->fail($model, $field, '时间文本无法解析');
        }

        return $parsed;
    }

    private function toEnum(string $model, FieldMeta $field, mixed $value): \BackedEnum
    {
        $name = $field->typeName();
        if (!is_a($name, \BackedEnum::class, true)) {
            throw $this->fail($model, $field, '不支持的类型 ' . $name);
        }
        if ($value instanceof $name) {
            return $value;
        }
        if (!is_int($value) && !is_string($value)) {
            throw $this->fail($model, $field, '枚举 backing value 类型不对');
        }
        // backing type 是 int 而传了数字字符串（SQLite/PG 驱动常见）时，
        // tryFrom('1') 对 int 枚举直接 TypeError 而非返回 null，须捕获后转 int 重试
        try {
            $enum = $name::tryFrom($value);
        } catch (\TypeError) {
            $enum = null;
        }
        if ($enum === null && is_string($value) && ctype_digit($value)) {
            try {
                $enum = $name::tryFrom((int) $value);
            } catch (\TypeError) {
                $enum = null;
            }
        }
        if (!$enum instanceof \BackedEnum) {
            throw $this->fail($model, $field, '枚举值无法还原');
        }

        return $enum;
    }

    private function fail(string $model, FieldMeta $field, string $reason): CastException
    {
        return new CastException($model . '::' . $field->property . ' ' . $reason, $model, $field->property);
    }
}
