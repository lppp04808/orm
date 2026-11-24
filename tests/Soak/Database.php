<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Soak;

use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Observability\Event;
use Pixiaoka\Orm\Observability\SqlEvent;
use Pixiaoka\Orm\Observability\TransactionEvent;

final class Database
{
    public static function connect(array $config): \PDO
    {
        $pdo = new \PDO($config['dsn'], $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver !== $config['driver']) {
            throw new \RuntimeException('DSN 实际驱动与 --driver 不一致: ' . $driver);
        }
        if ($driver === 'mysql') {
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        } elseif ($driver === 'pgsql') {
            $pdo->exec("SET statement_timeout = '15s'");
            $pdo->exec("SET lock_timeout = '10s'");
            $pdo->exec("SET idle_in_transaction_session_timeout = '30s'");
            $pdo->exec("SET TIME ZONE 'UTC'");
        } else {
            throw new \InvalidArgumentException('真实 soak 不接受 SQLite 或其他驱动');
        }

        return $pdo;
    }

    public static function observe(EntityGateway $gateway, array &$stats): void
    {
        $gateway->subscribe(static function (Event $event) use (&$stats): void {
            if ($event instanceof SqlEvent) {
                $stats['sql_statements']++;
                $stats['sql_seconds'] += $event->durationMs / 1000;
                if (!$event->successful) {
                    $stats['sql_failures']++;
                }
            } elseif ($event instanceof TransactionEvent) {
                $key = 'transaction_' . $event->phase;
                $stats[$key] = ($stats[$key] ?? 0) + 1;
                if (!$event->successful) {
                    $stats['transaction_failures']++;
                }
            }
        });
    }

    public static function exceptionInfo(\Throwable $e): array
    {
        $info = ['class' => $e::class, 'message' => $e->getMessage()];
        if ($e instanceof \PDOException) {
            $info['sqlstate'] = $e->getCode();
            $info['driver_code'] = $e->errorInfo[1] ?? null;
        }

        return $info;
    }

    public static function isLockFailure(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $state = (string) $e->getCode();
        $driverCode = $e->errorInfo[1] ?? null;

        return in_array($state, ['40001', '40P01', '55P03'], true)
            || in_array($driverCode, [1205, 1213], true);
    }
}
