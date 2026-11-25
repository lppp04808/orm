<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Sql;

/**
 * @internal SQL 构建、预览和原生参数校验共用的方言词法扫描。
 * 仅返回普通 SQL 中的命名参数；引号、注释、标识符及 dollar quote 原样保留。
 */
final class SqlLexer
{
    /**
     * @return list<string> 每次出现都返回名称；重复参数不能折叠成一次绑定。
     */
    public static function placeholders(string $sql, string $driver): array
    {
        $parameters = [];
        self::scan($sql, $driver, static function (string $name) use (&$parameters): string {
            $parameters[] = $name;

            return ':' . $name;
        });

        return $parameters;
    }

    /** @param callable(string): string $replace */
    public static function replacePlaceholders(string $sql, ?string $driver, callable $replace): string
    {
        // 未声明能力的旧 Connection 沿用保守扫描；只有明确的 MySQL 才启用 # 注释规则。
        return self::scan($sql, $driver ?? 'pgsql', $replace);
    }

    /** PostgreSQL dollar-quoted 文本转为等价 E 字符串，避免 pdo_pgsql 误认 :name。 */
    public static function normalizePostgresDollarQuotes(string $sql): string
    {
        if (!str_contains($sql, '$')) {
            return $sql;
        }

        $out = '';
        $length = strlen($sql);
        $blockDepth = 0;
        $state = 'sql';
        /** @var string|null $delimiter */
        $delimiter = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($delimiter !== null) {
                if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                    $out .= $delimiter;
                    $i += strlen($delimiter) - 1;
                    $delimiter = null;
                } else {
                    $out .= $char;
                }
                continue;
            }
            if ($state === 'line') {
                $out .= $char;
                if ($char === "\n" || $char === "\r") {
                    $state = 'sql';
                }
                continue;
            }
            if ($state === 'block') {
                if ($char === '/' && $next === '*') {
                    $out .= '/*';
                    $blockDepth++;
                    $i++;
                } elseif ($char === '*' && $next === '/') {
                    $out .= '*/';
                    $i++;
                    if (--$blockDepth === 0) {
                        $state = 'sql';
                    }
                } else {
                    $out .= $char;
                }
                continue;
            }
            if ($state !== 'sql') {
                $out .= $char;
                if ($char === '\\' && ($state === "'" || $state === 'E') && $next !== '') {
                    $out .= $next;
                    $i++;
                    continue;
                }
                $quote = $state === 'E' ? "'" : $state;
                if ($char === $quote) {
                    if ($next === $char) {
                        $out .= $next;
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }
            if ($char === "'") {
                $out .= $char;
                $state = self::isEscapeStringPrefix($sql, $i) ? 'E' : "'";
            } elseif ($char === '"' || $char === '`') {
                $out .= $char;
                $state = $char;
            } elseif ($char === '-' && $next === '-') {
                $out .= '--';
                $i++;
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $out .= '/*';
                $i++;
                $blockDepth = 1;
                $state = 'block';
            } elseif ($char === '$'
                && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i) === 1) {
                $tag = $match[0];
                $start = $i + strlen($tag);
                $end = strpos($sql, $tag, $start);
                if ($end === false) {
                    $out .= substr($sql, $i);
                    break;
                }
                $content = substr($sql, $start, $end - $start);
                $out .= "E'" . str_replace(["\\", "'"], ["\\\\", "''"], $content) . "'";
                $i = $end + strlen($tag) - 1;
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /** @param callable(string): string $replace */
    private static function scan(string $sql, string $driver, callable $replace): string
    {
        $out = '';
        $length = strlen($sql);
        $blockDepth = 0;
        $delimiter = null;
        $state = 'sql';
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($delimiter !== null) {
                if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                    $out .= $delimiter;
                    $i += strlen($delimiter) - 1;
                    $delimiter = null;
                } else {
                    $out .= $char;
                }
                continue;
            }
            if ($state === 'line') {
                $out .= $char;
                if ($char === "\n" || $char === "\r") {
                    $state = 'sql';
                }
                continue;
            }
            if ($state === 'block') {
                $out .= $char;
                if ($char === '/' && $next === '*') {
                    $out .= $next;
                    $i++;
                    $blockDepth++;
                } elseif ($char === '*' && $next === '/') {
                    $out .= $next;
                    $i++;
                    if (--$blockDepth === 0) {
                        $state = 'sql';
                    }
                }
                continue;
            }
            if ($state !== 'sql') {
                $out .= $char;
                if ($char === '\\' && $next !== ''
                    && (($driver === 'mysql' && ($state === "'" || $state === '"'))
                        || ($driver === 'pgsql' && $state === 'E'))) {
                    $out .= $next;
                    $i++;
                    continue;
                }
                $quote = $state === 'E' ? "'" : $state;
                if ($char === $quote) {
                    if ($next === $char) {
                        $out .= $next;
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }

            if ($char === '$'
                && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i) === 1) {
                $delimiter = $match[0];
                $out .= $delimiter;
                $i += strlen($delimiter) - 1;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $out .= $char;
                $state = $char === "'" && $driver === 'pgsql' && self::isEscapeStringPrefix($sql, $i) ? 'E' : $char;
            } elseif ($char === '-' && $next === '-') {
                $out .= '--';
                $i++;
                $state = 'line';
            } elseif ($driver === 'mysql' && $char === '#') {
                $out .= $char;
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $out .= '/*';
                $i++;
                $blockDepth = 1;
                $state = 'block';
            } elseif ($char === ':' && $next === ':') {
                $out .= '::';
                $i++;
            } elseif ($char === ':' && preg_match('/[A-Za-z_]/', $next) === 1) {
                $end = $i + 1;
                while ($end + 1 < $length && preg_match('/[A-Za-z0-9_]/', $sql[$end + 1]) === 1) {
                    $end++;
                }
                $out .= $replace(substr($sql, $i + 1, $end - $i));
                $i = $end;
            } elseif ($char === '?') {
                throw new \InvalidArgumentException('Raw SQL must use named placeholders to avoid parameter misalignment in complex queries');
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    private static function isEscapeStringPrefix(string $sql, int $offset): bool
    {
        if ($offset === 0 || ($sql[$offset - 1] !== 'E' && $sql[$offset - 1] !== 'e')) {
            return false;
        }

        return $offset === 1 || preg_match('/[A-Za-z0-9_$]/', $sql[$offset - 2]) !== 1;
    }
}
