<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

use DateTimeImmutable;
use Pixiaoka\Orm\Annotation\CreatedAt;
use Pixiaoka\Orm\Annotation\Temporal;
use Pixiaoka\Orm\Annotation\UpdatedAt;
use Pixiaoka\Orm\Cast\BcDecimal;

/** 将数据库列映射为 ORM 支持的 PHP 类型，并仅渲染静态合法默认值。 */
final class TypeMapper
{
    /** @var array<string, string> */
    private const array TYPES = [
        'smallint' => 'int', 'integer' => 'int', 'bigint' => 'int', 'int' => 'int',
        'serial' => 'int', 'smallserial' => 'int', 'bigserial' => 'int',
        'int2' => 'int', 'int4' => 'int', 'int8' => 'int', 'tinyint' => 'int',
        'mediumint' => 'int', 'year' => 'int', 'bit' => 'int',
        'real' => 'float', 'double' => 'float', 'double precision' => 'float',
        'float' => 'float', 'float4' => 'float', 'float8' => 'float',
        'bool' => 'bool', 'boolean' => 'bool',
        'json' => 'array', 'jsonb' => 'array',
        'numeric' => BcDecimal::class, 'decimal' => BcDecimal::class, 'money' => BcDecimal::class,
        // Temporal::TEXT 只无损处理 UTC Y-m-d H:i:s；date/TIME/时区/精度格式保守映射为 string。
        'datetime' => DateTimeImmutable::class, 'timestamp' => DateTimeImmutable::class,
        'timestamp without time zone' => DateTimeImmutable::class,
    ];

    /** @return non-empty-string */
    public function phpType(ColumnSchema $column): string
    {
        $base = $this->baseType($column->databaseType);
        if ($base === 'tinyint' && preg_match('/^tinyint\s*\(\s*1\s*\)/i', $column->databaseType) === 1) {
            return 'bool';
        }
        if ($base === 'timestamp' && $column->defaultDialect === 'pgsql') {
            return 'string';
        }
        if (in_array($base, ['datetime', 'timestamp'], true) && $column->defaultDialect === 'mysql') {
            return 'string';
        }
        if (str_starts_with($base, 'timestamp(') || str_starts_with($base, 'datetime(')) {
            return 'string';
        }

        return self::TYPES[$base] ?? 'string';
    }

    public function nullableType(ColumnSchema $column): string
    {
        $type = $this->phpType($column);
        $type = match ($type) {
            BcDecimal::class => 'BcDecimal',
            DateTimeImmutable::class => 'DateTimeImmutable',
            default => $type,
        };

        return ($column->nullable ? '?' : '') . $type;
    }

    /** @return list<class-string> */
    public function imports(ColumnSchema $column): array
    {
        $imports = [];
        $type = $this->phpType($column);
        if ($type === BcDecimal::class || $type === DateTimeImmutable::class) {
            $imports[] = $type;
        }
        if ($type === DateTimeImmutable::class) {
            $imports[] = Temporal::class;
        }
        if (in_array($type, ['int', DateTimeImmutable::class], true)) {
            if ($column->name === 'created_at') {
                $imports[] = CreatedAt::class;
            }
            if ($column->name === 'updated_at') {
                $imports[] = UpdatedAt::class;
            }
        }

        return $imports;
    }

    /**
     * 无默认值的非空标量需要 PHP 零值兜底，保证 new Entity() 可读。
     * 可空属性保留未初始化，以区分未赋值与显式 null；对象永远不生成 new 默认值。
     *
     * @return string PHP 属性初始化片段（含前导空格），或空字符串。
     */
    public function defaultInitializer(ColumnSchema $column): string
    {
        if ($column->primary() || $column->generated || $column->nullable) {
            return '';
        }

        $type = $this->phpType($column);
        if ($column->default !== null && !$column->defaultExpression) {
            $literal = $this->staticLiteral($column, $type);
            if ($literal !== null) {
                return ' = ' . $literal;
            }
        }

        if (in_array($type, [BcDecimal::class, DateTimeImmutable::class], true)) {
            return '';
        }

        return match ($type) {
            'int' => ' = 0',
            'float' => ' = 0.0',
            'bool' => ' = false',
            'string' => " = ''",
            'array' => ' = []',
            default => '',
        };
    }

    private function baseType(string $databaseType): string
    {
        $base = strtolower(trim($databaseType));
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;
        if (preg_match('/^(?:datetime|timestamp)\s*\(/', $base) === 1) {
            return $base;
        }

        return trim(explode('(', $base, 2)[0]);
    }

    private function staticLiteral(ColumnSchema $column, string $type): ?string
    {
        $default = (string) $column->default;
        if ($column->defaultDialect === 'pgsql' && preg_match('/^[eE]/', ltrim($default)) === 1) {
            $value = $this->decodePgEscapeDefault($default, $column->serverEncoding);
            if ($value === null) {
                return null;
            }

            return match ($type) {
                'string' => var_export($value, true),
                'array' => $this->jsonArrayLiteral($value),
                default => null,
            };
        }

        $value = $this->unwrap($default, $column->defaultDialect);
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => preg_match('/^[+-]?\d+$/D', $value) === 1 ? (string) (int) $value : null,
            'float' => is_numeric($value) && is_finite((float) $value) ? var_export((float) $value, true) : null,
            'bool' => $this->boolLiteral($value, $column->defaultDialect),
            'string' => $this->stringLiteral($value, $column->defaultDialect),
            'array' => $this->arrayLiteral($value, $column->defaultDialect),
            // Decimal 与 DateTimeImmutable 的对象构造不能作为 PHP 8.3 属性默认值。
            default => null,
        };
    }

    private function boolLiteral(string $value, ?string $dialect): ?string
    {
        $normalized = strtolower(trim($value));
        $unquoted = $this->unquote($normalized, $dialect) ?? $normalized;
        if (str_starts_with(strtolower($unquoted), 'b\'') && str_ends_with($unquoted, '\'')) {
            $unquoted = substr($unquoted, 2, -1);
        }

        return match (strtolower(trim($unquoted))) {
            '1', 'true', 't', 'yes', 'on', 'y' => 'true',
            '0', 'false', 'f', 'no', 'off', 'n' => 'false',
            default => null,
        };
    }

    private function stringLiteral(string $value, ?string $dialect): ?string
    {
        $decoded = $this->unquote($value, $dialect);
        if ($decoded === null) {
            // MySQL 的静态文本可能恰好像函数或 CURRENT_TIMESTAMP，EXTRA 已单独标记表达式。
            if ($dialect === 'mysql') {
                $decoded = $value;
            } else {
                if (preg_match('/^(?:current_(?:date|time|timestamp)|localtime(?:stamp)?|now\s*\(|uuid\s*\(|gen_random_uuid\s*\()/i', $value) === 1
                    || preg_match('/[()]/', $value) === 1) {
                    return null;
                }
                $decoded = $value;
            }
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded) === 1) {
            return null;
        }

        return var_export($decoded, true);
    }

    private function arrayLiteral(string $value, ?string $dialect): ?string
    {
        $decoded = $this->unquote($value, $dialect);
        if ($decoded === null) {
            return null;
        }
        try {
            $array = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($array)) {
            return null;
        }

        return var_export($array, true);
    }

    /** 去除 PostgreSQL 尾部类型转换和包裹静态字面量的括号。 */
    private function unwrap(string $default, ?string $dialect): ?string
    {
        $value = trim($default);
        if ($value === '' || ($dialect !== 'mysql' && strcasecmp($value, 'null') === 0)) {
            return null;
        }
        if ($dialect === 'mysql') {
            // information_schema 已经解码 MySQL 值；不能把它再次当成 SQL 字面量。
            return $value;
        }

        $value = preg_replace('/::[a-zA-Z_][a-zA-Z0-9_ .]*(?:\s*\(\s*\d+(?:\s*,\s*\d+)*\s*\))?(?:\[\])?$/', '', $value) ?? $value;
        $value = trim($value);
        while (strlen($value) >= 3 && $value[0] === '(' && str_ends_with($value, ')')) {
            $inner = substr($value, 1, -1);
            if ($this->unquote($inner, $dialect) === null && !is_numeric($inner)) {
                break;
            }
            $value = trim($inner);
        }

        return $value;
    }

    private function unquote(string $value, ?string $dialect): ?string
    {
        $value = trim($value);
        if ($dialect === 'mysql' || $value === '') {
            // 保留已解码文本中的引号和所有反斜杠，包括 Windows 路径。
            return $value;
        }
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace('""', '"', substr($value, 1, -1));
        }

        return null;
    }

    /** 按服务器编码解析 PostgreSQL E'...' 静态字面量，拒绝动态表达式、NUL 和非法编码。 */
    private function decodePgEscapeDefault(string $default, ?string $encoding): ?string
    {
        $default = trim($default);
        $default = preg_replace('/::[a-zA-Z_][a-zA-Z0-9_ .]*(?:\s*\(\s*\d+(?:\s*,\s*\d+)*\s*\))?(?:\[\])?$/', '', $default) ?? $default;
        $default = trim($default);
        if (preg_match("/^[eE]'((?:[^']|''|\\\\.)*)'\$/sD", $default, $matches) !== 1) {
            return null;
        }

        $content = str_replace("''", "'", $matches[1]);
        $decoded = '';
        $length = strlen($content);
        for ($index = 0; $index < $length; $index++) {
            if ($content[$index] !== '\\') {
                $decoded .= $content[$index];
                continue;
            }
            $index++;
            if ($index >= $length) {
                return null;
            }
            $escaped = $content[$index];
            $octal = str_contains('01234567', $escaped);
            if (in_array($escaped, ['x', 'u', 'U'], true) || $octal) {
                $digits = match ($escaped) {
                    'x' => '/\G[0-9A-Fa-f]{1,2}/A',
                    'u' => '/\G[0-9A-Fa-f]{4}/A',
                    'U' => '/\G[0-9A-Fa-f]{8}/A',
                    default => '/\G[0-7]{0,2}/A',
                };
                if (preg_match($digits, $content, $number, 0, $index + 1) !== 1 && $escaped !== '0') {
                    if ($escaped === 'x') {
                        // PostgreSQL 的 \x 后没有十六进制数字时退化为字符 x。
                        $decoded .= 'x';
                        continue;
                    }

                    return null;
                }
                $text = ($octal ? $escaped : '') . ($number[0] ?? '');
                $index += strlen($number[0] ?? '');
                $codepoint = intval($text, $octal ? 8 : 16);
                if ($octal) {
                    // 八进制是原始字节；最终按服务器编码统一转换，支持 UTF-8 多字节序列。
                    if ($codepoint > 255) {
                        return null;
                    }
                    $decoded .= chr($codepoint);
                    continue;
                }
                $utf8 = $this->codepoint($codepoint);
                if ($utf8 === null) {
                    return null;
                }
                $decoded .= $utf8;
                continue;
            }
            $decoded .= match ($escaped) {
                'b' => "\x08",
                'f' => "\x0C",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                default => $escaped,
            };
        }
        $hasUnicodeEscape = preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\[uU][0-9A-Fa-f]/', $content) === 1;
        $encoding = strtoupper((string) $encoding);
        if ($hasUnicodeEscape && !in_array($encoding, ['UTF8', 'UTF-8', 'UNICODE'], true)) {
            // Unicode 转义的码点结果固定为 UTF-8，不代表相邻原始字节也是 UTF-8。
            // 其他编码下无法无损保留完整混合值，宁可不渲染数据库默认值。
            return null;
        }
        if (preg_match('/[\x80-\xFF]/', $decoded) === 1
            && !in_array($encoding, ['UTF8', 'UTF-8', 'UNICODE'], true)) {
            if (preg_match('/^(?:ISO_8859_[0-9]+|ISO-8859-[0-9]+|LATIN[1-9]|LATIN10|WIN(?:125[0-8]|874)|CP(?:125[0-8]|874)|EUC_(?:CN|JP|KR|TW)|GB18030|GBK|BIG5|SJIS|SHIFT_JIS|UHC)$/', $encoding) === 1
                && function_exists('iconv') && @iconv($encoding, 'UTF-8', '') !== false) {
                $decoded = iconv($encoding, 'UTF-8', $decoded);
                if ($decoded === false) {
                    return null;
                }
            } else {
                // SQL_ASCII 等编码没有可靠 Unicode 映射，不能猜测 PHP 字符串含义。
                return null;
            }
        }
        if (preg_match('//u', $decoded) !== 1
            || str_contains($decoded, "\0")
            || preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded) === 1) {
            return null;
        }

        return $decoded;
    }

    /** PostgreSQL E 字符串的 Unicode 转义严格按码点编码，拒绝 NUL 和代理区。 */
    private function codepoint(int $codepoint): ?string
    {
        // 显式十进制常量：PHP 8.3 会将带前导零的十六进制数字字符串按数值比较。
        if ($codepoint <= 0 || $codepoint > 1114111 || ($codepoint >= 55296 && $codepoint <= 57343)) {
            return null;
        }
        if ($codepoint <= 127) {
            return chr($codepoint);
        }
        if ($codepoint <= 2047) {
            return chr(192 | ($codepoint >> 6)) . chr(128 | ($codepoint & 63));
        }
        if ($codepoint <= 65535) {
            return chr(224 | ($codepoint >> 12))
                . chr(128 | (($codepoint >> 6) & 63))
                . chr(128 | ($codepoint & 63));
        }

        return chr(240 | ($codepoint >> 18))
            . chr(128 | (($codepoint >> 12) & 63))
            . chr(128 | (($codepoint >> 6) & 63))
            . chr(128 | ($codepoint & 63));
    }

    private function jsonArrayLiteral(string $value): ?string
    {
        try {
            $array = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($array) ? var_export($array, true) : null;
    }
}
