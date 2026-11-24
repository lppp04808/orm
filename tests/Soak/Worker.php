<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Soak;

use Pixiaoka\Orm\Exception\OptimisticLockException;
use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Fixtures.php';
require __DIR__ . '/Database.php';

final class Worker
{
    private array $stats = [
        'iterations' => 0,
        'transfer_commits' => 0,
        'ledger_lock_conflicts' => 0,
        'explicit_rollbacks' => 0,
        'exception_rollbacks' => 0,
        'relation_checks' => 0,
        'optimistic_conflicts' => 0,
        'lock_conflicts' => 0,
        'connection_rotations' => 0,
        'audits' => 0,
        'audit_failures' => 0,
        'unexpected_exceptions' => 0,
        'sql_statements' => 0,
        'sql_seconds' => 0.0,
        'sql_failures' => 0,
        'transaction_begin' => 0,
        'transaction_commit' => 0,
        'transaction_rollback' => 0,
        'transaction_failures' => 0,
    ];

    /** @var list<array{at: int, operations: int, used: int, real: int, peak: int}> */
    private array $memory = [];

    public function __construct(
        private readonly array $config,
        private readonly string $log,
    ) {
    }

    public function run(): int
    {
        $worker = (int) $this->config['worker'];
        $prefix = $this->config['prefix'];
        SoakTables::configure($prefix);
        Session::strict();
        mt_srand($this->config['seed'] + $worker * 1009);
        $deadline = (float) $this->config['deadline'];
        $pdo = null;
        $lastSample = 0.0;
        $lastAudit = 0;
        $started = microtime(true);
        $this->write('worker_started', ['pid' => getmypid()]);

        try {
            while (microtime(true) < $deadline && !is_file($this->config['stop_file'])) {
                $iteration = $this->stats['iterations'] + 1;
                if ($pdo === null || ($iteration - 1) % $this->config['rotate'] === 0) {
                    $pdo = Database::connect($this->config);
                    $this->stats['connection_rotations']++;
                    gc_collect_cycles();
                }
                $operation = $this->chooseOperation();
                $gateway = new EntityGateway(new PdoConnection($pdo));
                Database::observe($gateway, $this->stats);
                $this->write('operation_started', ['operation' => $operation, 'iteration' => $iteration]);
                $startedAt = microtime(true);
                try {
                    Session::scope($gateway, function () use ($operation, $worker, $iteration): void {
                        match ($operation) {
                            'transfer' => $this->transfer($worker, $iteration),
                            'rollback' => $this->rollback($worker, $iteration, false),
                            'exception' => $this->rollback($worker, $iteration, true),
                            'contention' => $this->contention($worker),
                            'relation' => $this->relation(),
                            default => throw new \LogicException('未知 soak 操作'),
                        };
                    });
                    $this->stats['operation_seconds'][$operation]
                        = ($this->stats['operation_seconds'][$operation] ?? 0.0) + microtime(true) - $startedAt;
                } catch (\Throwable $e) {
                    if (Database::isLockFailure($e)) {
                        $this->stats['lock_conflicts']++;
                        $this->write('lock_conflict', ['operation' => $operation]);
                    } else {
                        $this->stats['unexpected_exceptions']++;
                        $this->write('unexpected_exception', [
                            'operation' => $operation,
                            'exception' => Database::exceptionInfo($e),
                        ]);
                        throw $e;
                    }
                }
                $this->stats['iterations']++;
                $this->stats['operations'][$operation] = ($this->stats['operations'][$operation] ?? 0) + 1;

                if ($iteration - $lastAudit >= $this->config['audit_interval']) {
                    $this->audit();
                    $lastAudit = $iteration;
                }
                $now = microtime(true);
                if ($now - $lastSample >= $this->config['sample']) {
                    $this->sample($now);
                    $lastSample = $now;
                }
                $this->pace();
            }
            $this->audit();
            $this->sample(microtime(true), true);
            $this->write('worker_finished', ['elapsed_seconds' => microtime(true) - $started, 'memory_samples' => $this->memory]);

            return $this->stats['unexpected_exceptions'] === 0 && $this->stats['audit_failures'] === 0 ? 0 : 1;
        } catch (\Throwable $e) {
            $this->write('worker_failed', ['exception' => Database::exceptionInfo($e)]);

            return 1;
        } finally {
            try {
                Session::discard();
            } catch (\Throwable $e) {
                $this->write('discard_failed', ['exception' => Database::exceptionInfo($e)]);
            }
            $this->write('worker_stopped', ['pid' => getmypid()]);
        }
    }

    private function transfer(int $worker, int $iteration): void
    {
        $id = $this->operationId($worker, $iteration);
        $from = mt_rand(1, $this->config['accounts']);
        do {
            $to = mt_rand(1, $this->config['accounts']);
        } while ($to === $from);
        $amount = mt_rand(1, 20);
        $this->effect($id, $worker, $from, $to, $amount, false);
    }

    private function rollback(int $worker, int $iteration, bool $exception): void
    {
        $id = $this->operationId($worker, $iteration);
        $from = mt_rand(1, $this->config['accounts']);
        do {
            $to = mt_rand(1, $this->config['accounts']);
        } while ($to === $from);
        $this->effect($id, $worker, $from, $to, mt_rand(1, 20), true, $exception);
    }

    private function effect(
        string $id,
        int $worker,
        int $from,
        int $to,
        int $amount,
        bool $rollback,
        bool $exception = false,
    ): void {
        $loaded = [];
        $intent = new SoakOperationIntent();
        $intent->operationId = $id;
        $intent->worker_id = $worker;
        $intent->kind = $rollback ? ($exception ? 'exception' : 'rollback') : 'transfer';
        $intent->from_account = $from;
        $intent->to_account = $to;
        $intent->amount = $amount;
        $intent->created_at = time();
        $intent->save();
        try {
            SoakAccount::transaction(function () use ($id, $from, $to, $amount, $rollback, $exception, &$loaded): void {
                $source = SoakAccount::find(['id' => $from]);
                $destination = SoakAccount::find(['id' => $to]);
                if ($source === null || $destination === null) {
                    throw new \RuntimeException('固定账户丢失');
                }
                $loaded = [$source, $destination];
                $before = [$source->toArray(), $destination->toArray()];
                $original = [$source->takeOriginal(), $destination->takeOriginal()];
                $loaded['before'] = $before;
                $loaded['original'] = $original;
                $source->balance -= $amount;
                $destination->balance += $amount;
                $source->save();
                $destination->save();
                if ($source->revision < 2 || $destination->revision < 2) {
                    throw new \RuntimeException('成功 UPDATE 未推进 revision');
                }
                $effect = new SoakTransferEffect();
                $effect->operationId = $id;
                $effect->from_account = $from;
                $effect->to_account = $to;
                $effect->amount = $amount;
                $effect->created_at = time();
                $effect->save();
                if ($this->config['fault'] === 'ledger' && $worker === 1) {
                    $source->balance += 1000000;
                    $source->save();
                    return;
                }
                if ($rollback) {
                    throw $exception
                        ? new \DomainException('soak 异常回滚：' . $id)
                        : new SoakRollback('soak 主动回滚：' . $id);
                }
                // 后续数据库断言不依赖这些闭包局部值；这里保留事务前快照供回滚断言。
            });
            if ($this->config['fault'] === 'worker_crash' && $worker === 1) {
                $this->write('injected_commit_confirmed');
                exit(70);
            }
            $this->stats['transfer_commits']++;
        } catch (SoakRollback|\DomainException $e) {
            if (!$rollback || !str_contains($e->getMessage(), $id)) {
                throw $e;
            }
            $this->assertRestored($loaded);
            $this->stats[$exception ? 'exception_rollbacks' : 'explicit_rollbacks']++;
        } catch (OptimisticLockException $e) {
            $this->assertRestored($loaded);
            $this->stats['optimistic_conflicts']++;
        } catch (\Throwable $e) {
            if ($rollback && Database::isLockFailure($e)) {
                $this->stats['ledger_lock_conflicts']++;
            }
            throw $e;
        }
    }

    private function assertRestored(array $loaded): void
    {
        $before = $loaded['before'] ?? null;
        $original = $loaded['original'] ?? null;
        if (!is_array($before) || !is_array($original)) {
            throw new \RuntimeException('事务在建立账户快照前异常终止');
        }
        foreach ([0, 1] as $index) {
            $entity = $loaded[$index];
            if (!$entity instanceof SoakAccount) {
                throw new \RuntimeException('事务实体快照损坏');
            }
            $values = $entity->toArray();
            foreach (['balance', 'revision'] as $field) {
                if ($values[$field] !== $before[$index][$field]
                    || ($entity->takeOriginal()[$field] ?? null) !== $original[$index][$field]) {
                    throw new \RuntimeException('回滚没有恢复 ' . $field . ' 或 original');
                }
            }
        }
    }

    private function contention(int $worker): void
    {
        $id = $this->config['accounts'] + 1 + ($worker % 2);
        SoakAccount::flushCache();
        $stale = SoakAccount::find(['id' => $id]);
        if ($stale === null) {
            throw new \RuntimeException('竞争账户不存在');
        }
        $version = $stale->revision;
        $otherPdo = Database::connect($this->config);
        $other = new EntityGateway(new PdoConnection($otherPdo));
        Session::scope($other, function () use ($id): void {
            $fresh = SoakAccount::find(['id' => $id]);
            if ($fresh === null) {
                throw new \RuntimeException('竞争账户在独立连接中不存在');
            }
            $fresh->balance++;
            $fresh->save();
        });
        $stale->balance--;
        $original = $stale->takeOriginal();
        try {
            $stale->save();
            throw new \RuntimeException('陈旧 revision UPDATE 没有产生乐观锁冲突');
        } catch (OptimisticLockException $e) {
            if ($e->expectedVersion !== $version || $stale->revision !== $version
                || $stale->takeOriginal() !== $original) {
                throw new \RuntimeException('乐观锁失败提前修改了版本或 original', 0, $e);
            }
            $this->stats['optimistic_conflicts']++;
        }
        $stale->refresh();
        $actual = $otherPdo->query('SELECT balance, revision FROM ' . SoakTables::quote($otherPdo, SoakAccount::table()) . ' WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        if ($actual === false || $stale->balance !== (int) $actual['balance']
            || $stale->revision !== (int) $actual['revision']) {
            throw new \RuntimeException('refresh 与独立连接数据库值不一致');
        }
    }

    private function relation(): void
    {
        $account = mt_rand(1, $this->config['accounts']);
        $pdo = Database::connect($this->config);
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::scope($gateway, function () use ($pdo, $account): void {
            SoakTables::readOnlySnapshot($pdo, function () use ($pdo, $account): void {
                $table = SoakTables::quote($pdo, SoakTransferEffect::table());
                $rows = SoakAccount::query()->where('id', $account)
                    ->withCount(['outgoing', 'outgoing.source'])->rows();
                $exists = SoakAccount::query()->where('id', $account)
                    ->whereHas('outgoing')->rows();
                $related = SoakAccount::query()->where('id', $account)
                    ->whereRelation('outgoing', 'amount', '>=', 10)->rows();
                $none = SoakAccount::query()->where('id', $account)
                    ->whereDoesntHave('outgoing')->rows();
                $nestedNone = SoakAccount::query()->where('id', $account)
                    ->whereDoesntHave('outgoing.source')->rows();
                $raw = SoakAccount::queryOneRaw(
                    'SELECT COUNT(*) AS outgoing, '
                    . 'EXISTS(SELECT 1 FROM ' . $table . ' WHERE from_account = :large_account AND amount >= 10) AS has_large FROM '
                    . $table . ' WHERE from_account = :account',
                    ['account' => $account, 'large_account' => $account],
                );
                if ($raw === null || count($rows) !== 1
                    || (int) $rows[0]['outgoing_count'] !== (int) $raw['outgoing']
                    || (int) $rows[0]['outgoing_source_count'] !== (int) $raw['outgoing']) {
                    throw new \RuntimeException('withCount 与原始 SQL 不一致');
                }
                $has = (int) $raw['outgoing'] > 0;
                $constrained = (int) $raw['has_large'] === 1;
                if (count($exists) !== ($has ? 1 : 0) || count($related) !== ($constrained ? 1 : 0)
                    || count($none) !== ($has ? 0 : 1) || count($nestedNone) !== ($has ? 0 : 1)) {
                    throw new \RuntimeException('关系 EXISTS/NOT EXISTS 与原始 SQL 不一致');
                }
                $this->stats['relation_checks']++;
            });
        });
    }

    private function audit(): void
    {
        $pdo = Database::connect($this->config);
        $gateway = new EntityGateway(new PdoConnection($pdo));
        Session::scope($gateway, function () use ($pdo): void {
            $audit = SoakTables::audit($pdo, $this->config['accounts'], false);
            $this->stats['audits']++;
            if (!$audit['ok']) {
                $this->stats['audit_failures']++;
                $this->write('audit_failed', ['audit' => $audit]);
                throw new \RuntimeException('数据库不变量失败: ' . implode(', ', $audit['violations']));
            }
        });
    }

    private function chooseOperation(): string
    {
        $roll = mt_rand(1, 100);
        foreach ($this->config['weights'] as $operation => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $operation;
            }
        }

        return 'relation';
    }

    private function operationId(int $worker, int $iteration): string
    {
        return sprintf('%s-w%03d-%012d', substr($this->config['prefix'], 9, 12), $worker, $iteration);
    }

    private function pace(): void
    {
        if ($this->config['delay_ms'] > 0) {
            usleep($this->config['delay_ms'] * 1000);
        }
    }

    private function sample(float $now, bool $final = false): void
    {
        gc_collect_cycles();
        $this->memory[] = [
            'at' => time(),
            'operations' => $this->stats['iterations'],
            'used' => memory_get_usage(false),
            'real' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
        $this->write('heartbeat', ['final' => $final]);
    }

    /** @param array<string, mixed> $extra */
    private function write(string $type, array $extra = []): void
    {
        $record = array_merge([
            'type' => $type,
            'at' => microtime(true),
            'driver' => $this->config['driver'],
            'worker' => $this->config['worker'],
            'pid' => getmypid(),
            'stats' => $this->stats,
            'memory' => end($this->memory) ?: null,
        ], $extra);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || file_put_contents($this->log, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('worker 无法写入日志: ' . $this->log);
        }
    }
}

$configPath = $argv[1] ?? '';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR) : null;
if (!is_array($config) || !isset($config['worker'], $config['log'])) {
    fwrite(STDERR, "worker 配置无效\n");
    exit(2);
}
exit((new Worker($config, $config['log']))->run());
