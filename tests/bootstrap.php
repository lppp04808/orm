<?php

declare(strict_types=1);

// 库的独立测试入口：
// 1) 有 vendor/autoload.php 时直接复用（composer install 之后）
// 2) 没有 vendor 时手工注册 Pixiaoka\Orm\ / Tests\Pixiaoka\Orm\ 两个前缀，配合全局 phpunit 或主项目 phpunit 也能跑
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Pixiaoka\Orm\\' => __DIR__ . '/../src/',
        'Tests\\Pixiaoka\Orm\\' => __DIR__ . '/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
