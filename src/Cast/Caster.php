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
            throw $this->fail($model, $field, 'Non-nullable field received NULL');
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
            throw $this->fail($model, $field, 'Cannot write null to a non-nullable field');
        }

        $name = $field->typeName();

        if ($name === 'bool') {
            if (!is_bool($value)) {
                throw $this->fail($model, $field, 'Expected a bool value for database output');
            }

            return $value ? 1 : 0;
        }

        if ($name === 'array') {
            if (!is_array($value)) {
                throw $this->fail($model, $field, 'Expected an array value for database output');
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw $this->fail($model, $field, 'Failed to encode array as JSON');
            }

            return $json;
        }

        if (is_a($name, Decimal::class, true)) {
            if (!$value instanceof Decimal) {
                throw $this->fail($model, $field, 'Expected a Decimal value for database output');
            }

            return (string) $value;
        }

        if ($name === DateTimeImmutable::class) {
            if (!$value instanceof DateTimeImmutable) {
                throw $this->fail($model, $field, 'Expected a DateTimeImmutable value for database output');
            }
            $utc = $value->setTimezone(new DateTimeZone('UTC'));

            return match ($field->temporal) {
                Temporal::DATE => $utc->format('Y-m-d'),
                Temporal::TEXT_US => $utc->format('Y-m-d H:i:s.u'),
                Temporal::UNIX => $utc->getTimestamp(),
                Temporal::UNIX_MS => $this->unixMilliseconds($model, $field, $utc),
                default => $utc->format(self::TIME_FORMAT),
            };
        }

        if (is_a($name, \BackedEnum::class, true)) {
            if (!$value instanceof \BackedEnum) {
                throw $this->fail($model, $field, 'Expected a BackedEnum value for database output');
            }

            return $value->value;
        }

        if (is_a($name, Castable::class, true)) {
            if (!$value instanceof $name) {
                throw $this->fail($model, $field, 'Invalid Castable value for database output');
            }
            try {
                return $value->toDatabase();
            } catch (\Throwable $e) {
                throw $this->fail($model, $field, 'Failed to serialize Castable: ' . $e->getMessage());
            }
        }

        if ($name === 'int') {
            if (!is_int($value)) {
                throw $this->fail($model, $field, 'Expected an int value for database output');
            }

            return $value;
        }
        if ($name === 'float') {
            if (!is_float($value) && !is_int($value)) {
                throw $this->fail($model, $field, 'Expected a numeric value for database output');
            }

            $number = (float) $value;
            if (!is_finite($number)) {
                throw $this->fail($model, $field, 'Database output float must be finite');
            }

            return $number;
        }
        if ($name === 'string') {
            if (!is_string($value)) {
                throw $this->fail($model, $field, 'Expected a string value for database output');
            }

            return $value;
        }

        throw $this->fail($model, $field, 'Unsupported database output type');
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

    /** @internal 自动时间戳保留旧秒级输入，同时按声明存储提供当前精度。 */
    public function currentTimestamp(FieldMeta $field, ?int $now = null): int|DateTimeImmutable
    {
        if ($field->typeName() === 'int') {
            return $now ?? time();
        }
        if ($now === null && in_array($field->temporal, [Temporal::TEXT_US, Temporal::UNIX_MS], true)) {
            $clock = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
            if ($clock === false) {
                throw new \RuntimeException('Unable to read the system clock');
            }

            return $clock->setTimezone(new DateTimeZone('UTC'));
        }

        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @internal 仅供 EntityGateway::toArray() 调用，不参与持久化或字段比较。 */
    public function formatDisplay(string $model, FieldMeta $field, mixed $value): mixed
    {
        if ($value instanceof DateTimeImmutable && $field->temporalDisplayFormat !== null) {
            return $value->setTimezone(
                new DateTimeZone($field->temporalDisplayTimezone ?? 'UTC'),
            )->format($field->temporalDisplayFormat);
        }

        return $this->toDb($model, $field, $value);
    }

    private function toInt(string $model, FieldMeta $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $digits = ltrim($value, '-');
            $normalized = ltrim($digits, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $limit = ltrim((string) (str_starts_with($value, '-') ? PHP_INT_MIN : PHP_INT_MAX), '-');
            if (strlen($normalized) < strlen($limit)
                || (strlen($normalized) === strlen($limit) && strcmp($normalized, $limit) <= 0)) {
                return (int) $value;
            }
            throw $this->fail($model, $field, 'Integer value exceeds PHP integer range');
        }
        throw $this->fail($model, $field, 'Cannot parse value as int');
    }

    private function toFloat(string $model, FieldMeta $field, mixed $value): float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;
            if (!is_finite($number)) {
                throw $this->fail($model, $field, 'Cannot parse a non-finite float');
            }

            return $number;
        }
        throw $this->fail($model, $field, 'Cannot parse value as float');
    }

    private function toString(string $model, FieldMeta $field, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw $this->fail($model, $field, 'Cannot parse value as string');
    }

    private function toBool(string $model, FieldMeta $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value === 1 || $value === '1';
        }
        throw $this->fail($model, $field, 'bool accepts only 0/1/true/false');
    }

    private function toArray(string $model, FieldMeta $field, mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw $this->fail($model, $field, 'array accepts only JSON text or an array');
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->fail($model, $field, 'Failed to decode JSON');
        }
        if (!is_array($decoded)) {
            throw $this->fail($model, $field, 'Decoded JSON value is not an array');
        }

        return $decoded;
    }

    private function toDecimal(string $model, FieldMeta $field, mixed $value): Decimal
    {
        $name = $field->typeName();
        if ($value instanceof $name) {
            return $value;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1)) {
            throw $this->fail($model, $field, 'Cannot parse value as a decimal number');
        }
        try {
            /**
             * @var class-string $name
             */
            $decimal = $name::fromString((string) $value);
        } catch (\Throwable) {
            throw $this->fail($model, $field, 'Cannot parse value as a decimal number');
        }
        if (!$decimal instanceof Decimal) {
            throw $this->fail($model, $field, 'Decimal type does not implement Decimal');
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
            throw $this->fail($model, $field, 'Castable accepts only scalar database values');
        }
        try {
            /**
             * @var class-string<Castable> $name
             */
            $object = $name::fromDatabase($value);
        } catch (\Throwable $e) {
            throw $this->fail($model, $field, 'Failed to deserialize Castable: ' . $e->getMessage());
        }
        if (!$object instanceof $name) {
            throw $this->fail($model, $field, 'Castable::fromDatabase() returned an invalid type');
        }

        return $object;
    }

    private function toTime(string $model, FieldMeta $field, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (in_array($field->temporal, [Temporal::UNIX, Temporal::UNIX_MS], true)) {
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
                throw $this->fail($model, $field, 'Cannot parse value as a Unix timestamp');
            }
            $timestamp = $this->toInt($model, $field, $value);
            if ($field->temporal === Temporal::UNIX) {
                return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
            }
            $milliseconds = $timestamp;
            $seconds = intdiv($milliseconds, 1000);
            $microseconds = ($milliseconds - $seconds * 1000) * 1000;
            if ($microseconds < 0) {
                $seconds--;
                $microseconds += 1_000_000;
            }
            // U.u 的小数部分在 PHP 8.3 会走浮点，极小负时间可能向 -∞ 进一秒。
            // 先构造整数秒，再以整数微秒偏移恢复，保证毫秒输入精确往返。
            $base = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
            if ($microseconds === 0) {
                return $base;
            }

            return $base->modify('+' . $microseconds . ' microseconds');
        }
        if (!is_string($value)) {
            throw $this->fail($model, $field, 'Temporal database value must be a string');
        }
        $format = match ($field->temporal) {
            Temporal::DATE => '!Y-m-d',
            Temporal::TEXT_US => '!Y-m-d H:i:s.u',
            default => '!' . self::TIME_FORMAT,
        };
        if ($field->temporal === Temporal::TEXT_US) {
            $value = $this->microsecondText($value);
        }
        if (str_contains($value, "\0")) {
            throw $this->fail($model, $field, 'Cannot parse temporal text');
        }
        $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw $this->fail($model, $field, 'Cannot parse temporal text');
        }

        return $parsed;
    }

    private function unixMilliseconds(string $model, FieldMeta $field, DateTimeImmutable $value): int
    {
        $seconds = $value->getTimestamp();
        $milliseconds = intdiv((int) $value->format('u'), 1000);
        $maximumSeconds = intdiv(PHP_INT_MAX, 1000);
        if ($seconds > $maximumSeconds || ($seconds === $maximumSeconds && $milliseconds > PHP_INT_MAX % 1000)) {
            throw $this->fail($model, $field, 'Unix millisecond timestamp exceeds PHP integer range');
        }
        $minimumSeconds = intdiv(PHP_INT_MIN, 1000);
        if ($seconds < $minimumSeconds) {
            $minimumMilliseconds = 1000 + PHP_INT_MIN % 1000;
            if ($seconds === $minimumSeconds - 1 && $milliseconds >= $minimumMilliseconds) {
                return PHP_INT_MIN + ($milliseconds - $minimumMilliseconds);
            }
            throw $this->fail($model, $field, 'Unix millisecond timestamp exceeds PHP integer range');
        }

        return $seconds * 1000 + $milliseconds;
    }

    private function microsecondText(string $value): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $value, $matches) !== 1) {
            return $value;
        }

        return $matches[1] . '.' . str_pad($matches[2] ?? '', 6, '0');
    }

    private function toEnum(string $model, FieldMeta $field, mixed $value): \BackedEnum
    {
        $name = $field->typeName();
        if (!is_a($name, \BackedEnum::class, true)) {
            throw $this->fail($model, $field, 'Unsupported type ' . $name);
        }
        if ($value instanceof $name) {
            return $value;
        }
        if (!is_int($value) && !is_string($value)) {
            throw $this->fail($model, $field, 'Invalid enum backing value type');
        }
        try {
            $enum = $name::tryFrom($value);
        } catch (\TypeError) {
            // 某些驱动将整数枚举读为字符串，沿用普通 int 字段的符号和范围校验。
            $enum = is_string($value) && preg_match('/^-?\d+$/D', $value) === 1
                ? $name::tryFrom($this->toInt($model, $field, $value))
                : null;
        }
        if (!$enum instanceof \BackedEnum) {
            throw $this->fail($model, $field, 'Cannot restore enum from its backing value');
        }

        return $enum;
    }

    private function fail(string $model, FieldMeta $field, string $reason): CastException
    {
        return new CastException($model . '::' . $field->property . ' ' . $reason, $model, $field->property);
    }
}
