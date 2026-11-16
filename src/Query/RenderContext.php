<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Query;

use InvalidArgumentException;

/**
 * 一次 SQL 构建的独立参数作用域。
 * 同一 Builder 重复 build 均从 q0 开始；raw 占位符在此作用域内唯一化。
 */
final class RenderContext
{
    /** @var array<string, int|float|string|bool|null> */
    private array $params = [];
    private int $index = 0;

    public function bind(int|float|string|bool|null $value): string
    {
        $name = 'q' . $this->index++;
        $this->params[$name] = $value;

        return ':' . $name;
    }

    /**
     * 合并开发者编写的 SQL 片段，严格匹配命名参数并重新编号。
     * 扫描跳过引号、注释、反引号和 PostgreSQL :: 类型转换。
     *
     * @param array<string, int|float|string|bool|null> $rawParams
     */
    public function mergeRaw(string $sql, array $rawParams): string
    {
        foreach ($rawParams as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException('Raw SQL 参数名必须对应命名占位符');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Raw SQL 参数值必须是标量或 null');
            }
        }

        $used = [];
        $out = '';
        $length = strlen($sql);
        $state = 'sql';
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($state !== 'sql') {
                $out .= $char;
                if (str_starts_with($state, '$')) {
                    if (substr($sql, $i, strlen($state)) === $state) {
                        $out .= substr($state, 1);
                        $i += strlen($state) - 1;
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'line') {
                    if ($char === "\n" || $char === "\r") {
                        $state = 'sql';
                    }
                    continue;
                }
                if ($state === 'block') {
                    if ($char === '*' && $next === '/') {
                        $out .= $next;
                        $i++;
                        $state = 'sql';
                    }
                    continue;
                }
                if ($char === $state) {
                    if ($next === $char) {
                        $out .= $next;
                        $i++;
                    } else {
                        $state = 'sql';
                    }
                }
                continue;
            }

            if ($char === '$' && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $match) === 1) {
                $state = $match[0];
                $out .= $state;
                $i += strlen($state) - 1;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $state = $char;
                $out .= $char;
            } elseif ($char === '-' && $next === '-') {
                $state = 'line';
                $out .= '--';
                $i++;
            } elseif ($char === '#') {
                $state = 'line';
                $out .= $char;
            } elseif ($char === '/' && $next === '*') {
                $state = 'block';
                $out .= '/*';
                $i++;
            } elseif ($char === '?') {
                throw new InvalidArgumentException('Raw SQL 必须使用命名占位符，不支持 ? 占位符');
            } elseif ($char === ':' && $next === ':') {
                $out .= '::';
                $i++;
            } elseif ($char === ':' && preg_match('/[A-Za-z_]/', $next) === 1) {
                $end = $i + 1;
                while ($end + 1 < $length && preg_match('/[A-Za-z0-9_]/', $sql[$end + 1]) === 1) {
                    $end++;
                }
                $name = substr($sql, $i + 1, $end - $i);
                if (!array_key_exists($name, $rawParams)) {
                    throw new InvalidArgumentException('Raw SQL 缺少参数: ' . $name);
                }
                $used[$name] = true;
                $out .= $this->bind($rawParams[$name]);
                $i = $end;
            } else {
                $out .= $char;
            }
        }

        $missing = array_diff(array_keys($rawParams), array_keys($used));
        if ($missing !== []) {
            throw new InvalidArgumentException('Raw SQL 存在未使用的参数: ' . implode(', ', $missing));
        }

        return $out;
    }

    /** @return array<string, int|float|string|bool|null> */
    public function params(): array
    {
        return $this->params;
    }
}
