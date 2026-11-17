<?php

declare(strict_types=1);

namespace Pixiaoka\Orm;

use Fiber;
use WeakMap;

/**
 * 框架无关的执行上下文身份。
 *
 * 对象身份避免 Fiber/协程数字 ID 重用。WeakMap 不延长 Fiber 或运行时协程的生命周期；
 * 根上下文由 Session 的根 Session 保持有效。
 *
 * @internal
 */
final class Context
{
    private static ?self $main = null;

    /** @var WeakMap<Fiber, self>|null */
    private static ?WeakMap $fibers = null;

    /** @var WeakMap<object, self>|null */
    private static ?WeakMap $runtime = null;

    private function __construct(
        private readonly string $type,
    ) {
    }

    /** @return 'root'|'fiber'|'coroutine' */
    public function type(): string
    {
        return $this->type;
    }

    public static function root(): self
    {
        return self::$main ??= new self('root');
    }

    public static function fiber(Fiber $fiber): self
    {
        self::$fibers ??= new WeakMap();

        return self::$fibers[$fiber] ??= new self('fiber');
    }

    public static function coroutine(object $coroutine): self
    {
        self::$runtime ??= new WeakMap();

        return self::$runtime[$coroutine] ??= new self('coroutine');
    }
}
