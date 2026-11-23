<?php

declare(strict_types=1);

namespace Swow {
    if (!class_exists(Coroutine::class, false)) {
        final class Coroutine
        {
            private static ?self $current = null;

            public static function getCurrent(): ?self { return self::$current; }
            public static function setCurrent(?self $current): void { self::$current = $current; }
        }
    }
}

namespace Swoole {
    if (!class_exists(Coroutine::class, false)) {
        final class Coroutine
        {
            private static ?object $context = null;

            public static function getCid(): int { return self::$context === null ? -1 : 1; }
            public static function getContext(): ?object { return self::$context; }
            public static function setCurrentForTest(?object $context): void { self::$context = $context; }
        }
    }
}

namespace Tests\Pixiaoka\Orm {
    use PHPUnit\Framework\TestCase;
    use Pixiaoka\Orm\Context;
    use Pixiaoka\Orm\Gateway\EntityGateway;
    use Pixiaoka\Orm\Gateway\PdoConnection;
    use Pixiaoka\Orm\Session;
    use Pixiaoka\Orm\SessionScope;
    use Swoole\Coroutine as SwooleCoroutine;
    use Swow\Coroutine as SwowCoroutine;

    final class SessionRuntimeTest extends TestCase
    {
        private EntityGateway $root;

        protected function setUp(): void
        {
            Session::discard();
            Session::strict(false);
            $this->root = self::gateway();
            Session::use($this->root);
        }

        protected function tearDown(): void
        {
            SwowCoroutine::setCurrent(null);
            if (method_exists(SwooleCoroutine::class, 'setCurrentForTest')) {
                SwooleCoroutine::setCurrentForTest(null);
            }
            Session::strict(false);
            Session::discard();
        }

        public function testSwooleContextObjectIsUsedWithoutGetCurrentApi(): void
        {
            if (!method_exists(SwooleCoroutine::class, 'setCurrentForTest')) {
                self::markTestSkipped('真实 Swoole 扩展的并发适配需在独立扩展环境验收');
            }
            $swooleContext = new \stdClass();
            SwooleCoroutine::setCurrentForTest($swooleContext);
            self::assertSame(Context::coroutine($swooleContext), Session::context());
            $gateway = self::gateway();
            Session::use($gateway);
            $scoped = self::gateway();
            Session::scope($scoped, static function () use ($scoped): void {
                self::assertSame($scoped, Session::get()->gateway);
            });
            self::assertSame($gateway, Session::get()->gateway);
            Session::discard();
            SwooleCoroutine::setCurrentForTest(null);
        }

        public function testStrictSwooleContextCannotFallbackToRoot(): void
        {
            if (!method_exists(SwooleCoroutine::class, 'setCurrentForTest')) {
                self::markTestSkipped('真实 Swoole 扩展的并发适配需在独立扩展环境验收');
            }
            SwooleCoroutine::setCurrentForTest(new \stdClass());
            Session::strict();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('严格模式禁止回退根连接');
            try {
                Session::get();
            } finally {
                SwooleCoroutine::setCurrentForTest(null);
            }
        }

        public function testRuntimeObjectsDoNotShareSessionOrTransactionContext(): void
        {
            Session::strict();
            $first = new SwowCoroutine();
            $second = new SwowCoroutine();
            $firstGateway = self::gateway();
            $secondGateway = self::gateway();
            SwowCoroutine::setCurrent($first);
            Session::use($firstGateway);
            $firstContext = Session::context();
            SwowCoroutine::setCurrent($second);
            $this->expectException(\LogicException::class);
            try {
                Session::get();
            } finally {
                Session::use($secondGateway);
                self::assertNotSame($firstContext, Session::context());
                self::assertSame($secondGateway, Session::get()->gateway);
                SwowCoroutine::setCurrent($first);
                self::assertSame($firstGateway, Session::get()->gateway);
                Session::discard();
                SwowCoroutine::setCurrent($second);
                Session::discard();
                SwowCoroutine::setCurrent(null);
            }
        }

        public function testRuntimeScopeRestoresOnlyItsOwnPreviousSession(): void
        {
            $runtime = new SwowCoroutine();
            SwowCoroutine::setCurrent($runtime);
            $first = self::gateway();
            $second = self::gateway();
            Session::use($first);
            $scope = Session::scope($second);
            self::assertSame($second, Session::get()->gateway);
            $scope->close();
            self::assertSame($first, Session::get()->gateway);
            Session::discard();
            SwowCoroutine::setCurrent(null);
        }

        public function testRuntimeDetectionIsCachedAndNamedSessionsStayIsolated(): void
        {
            $runtime = new SwowCoroutine();
            SwowCoroutine::setCurrent($runtime);
            $readonly = self::gateway();
            Session::use($readonly, 'readonly');
            self::assertSame($this->root, Session::get()->gateway);
            self::assertSame($readonly, Session::get('readonly')->gateway);
            for ($i = 0; $i < 3; $i++) {
                self::assertSame(Context::coroutine($runtime), Session::context());
            }
            foreach (['hasSwoole', 'hasSwow'] as $property) {
                $cached = new \ReflectionProperty(Session::class, $property);
                self::assertIsBool($cached->getValue());
            }
            Session::discard('readonly');
            self::assertSame($this->root, Session::get()->gateway);
            Session::discard();
            SwowCoroutine::setCurrent(null);
        }

        public function testAnotherActiveRuntimeCannotUseSameGateway(): void
        {
            $first = new SwowCoroutine();
            $second = new SwowCoroutine();
            SwowCoroutine::setCurrent($first);
            $gateway = self::gateway();
            Session::use($gateway);
            SwowCoroutine::setCurrent($second);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('另一个活跃 Fiber/协程');
            try {
                Session::use($gateway);
            } finally {
                SwowCoroutine::setCurrent($first);
                Session::discard();
                SwowCoroutine::setCurrent(null);
            }
        }

        public function testRuntimeScopeCannotCloseFromRoot(): void
        {
            SwowCoroutine::setCurrent(new SwowCoroutine());
            $scope = Session::scope(self::gateway());
            SwowCoroutine::setCurrent(null);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('创建它的 Fiber');
            try {
                $scope->close();
            } finally {
                $reflection = new \ReflectionProperty(SessionScope::class, 'active');
                $reflection->setValue($scope, false);
                SwowCoroutine::setCurrent(null);
            }
        }

        private static function gateway(): EntityGateway
        {
            return new EntityGateway(new PdoConnection(new \PDO('sqlite::memory:')));
        }
    }
}
