<?php

/**
 * 真实 MySQL/PostgreSQL 多进程 soak。只清理本次随机前缀的表。
 * 用法见 SOAK.md；示例必须显式提供测试 DSN，不提供就不会连接数据库。
 */

declare(strict_types=1);

use Pixiaoka\Orm\Gateway\EntityGateway;
use Pixiaoka\Orm\Gateway\PdoConnection;
use Pixiaoka\Orm\Session;
use Pixiaoka\Orm\Soak\Database;
use Pixiaoka\Orm\Soak\SoakTables;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Soak/Fixtures.php';
require __DIR__ . '/Soak/Database.php';

final class SoakRunner
{
    private array $options;
    private string $runDir;
    private string $prefix;
    private float $deadline;
    private array $processes = [];
    private array $driverStats = [];
    private array $stopRequests = [];
    private bool $normalStop = false;
    private string $stopReason = '';
    private array $failures = [];
    private array $workerHistory = [];
    private array $secretFiles = [];
    private float $stopAt = 0.0;
    private float $started;

    public function __construct(array $options)
    {
        $this->options = $options;
        $this->started = microtime(true);
        $this->deadline = $this->started + $options['duration'];
        $this->prefix = 'orm_soak_' . strtolower(bin2hex(random_bytes(6))) . '_';
        $root = $options['output'] ?: dirname(__DIR__) . '/build/soak';
        $this->runDir = $root . '/' . date('Ymd_His') . '_' . substr($this->prefix, 9, 12);
        if (!is_dir($this->runDir) && !mkdir($this->runDir, 0770, true) && !is_dir($this->runDir)) {
            throw new RuntimeException('无法创建 soak 报告目录: ' . $this->runDir);
        }
    }

    public function run(): int
    {
        $this->write('run_started', [
            'options' => $this->publicOptions(),
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'host_pid' => getmypid(),
        ]);
        $this->installSignals();
        $cleanupPdos = [];
        try {
            if (in_array($this->options['fault'], ['ledger', 'worker_crash'], true)
                && $this->options['drivers'] !== ['mysql']) {
                throw new InvalidArgumentException('故障注入使用单库 --drivers=mysql，不能宣称双库正常验收');
            }
            foreach ($this->options['drivers'] as $driver) {
                $config = $this->driverConfig($driver);
                $pdo = Database::connect($config);
                $cleanupPdos[$driver] = $pdo;
                SoakTables::configure($this->prefix);
                $this->assertOwnedTablesAbsent($pdo);
                Session::use(new EntityGateway(new PdoConnection($pdo)));
                SoakTables::create($pdo, $this->options['accounts']);
                Session::discard();
                $this->write('fixtures_created', ['driver' => $driver]);
            }

            $sequence = 0;
            foreach ($this->options['drivers'] as $driver) {
                $this->driverStats[$driver] = $this->emptyStats();
                for ($worker = 1; $worker <= $this->options['workers']; $worker++) {
                    $sequence++;
                    $this->startWorker($driver, $worker, $sequence);
                }
            }
            $this->write('workers_started', ['count' => count($this->processes)]);

            $lastSample = 0.0;
            $lastAudit = 0.0;
            $auditSequence = 0;
            while ($this->processes !== []) {
                $this->checkSignals();
                $now = microtime(true);
                if ($this->stopReason === '' && $now >= $this->deadline) {
                    $this->normalStop = true;
                    $this->stopReason = 'deadline_reached';
                    $this->requestStop();
                }
                if ($this->stopReason !== '' && $now >= $this->stopAt + $this->options['grace']) {
                    $this->failures[] = 'workers_grace_timeout';
                    $this->terminateWorkers();
                }
                foreach ($this->processes as $key => &$process) {
                    $status = proc_get_status($process['handle']);
                    $process['last_log'] = $this->readLog($process);
                    $heartbeat = $this->latestHeartbeat($process);
                    $process['heartbeat'] = $heartbeat;
                    if (!$status['running']) {
                        $this->collectProcess($key, $status['exitcode']);
                        continue;
                    }
                    if ($heartbeat !== null) {
                        $this->mergeStats($process['driver'], $heartbeat['stats'] ?? []);
                    }
                    if ($now - $process['activity'] > $this->options['heartbeat_timeout']) {
                        $this->failures[] = 'heartbeat_timeout:' . $process['driver'] . ':' . $process['worker'];
                        $this->stopReason = 'heartbeat_timeout';
                        proc_terminate($process['handle']);
                    }
                }
                unset($process);
                $now = microtime(true);
                if ($now - $lastSample >= $this->options['sample']) {
                    $this->write('monitor', $this->monitorSummary());
                    $lastSample = $now;
                }
                if ($this->stopReason === '' && $now - $lastAudit >= $this->options['audit_interval']) {
                    $auditSequence++;
                    foreach ($cleanupPdos as $driver => $pdo) {
                        $this->audit($driver, $pdo, false);
                    }
                    $lastAudit = $now;
                }
                if ($this->processes !== []) {
                    usleep(200000);
                }
            }
            $this->write('workers_stopped', ['reason' => $this->stopReason ?: 'workers_exited']);
            foreach ($cleanupPdos as $driver => $pdo) {
                $this->audit($driver, $pdo, true);
            }
        } catch (Throwable $e) {
            $this->failures[] = 'runner:' . $e::class . ':' . $e->getMessage();
            $this->write('runner_exception', ['exception' => Database::exceptionInfo($e)]);
            $this->requestStop();
            $this->terminateWorkers();
        } finally {
            foreach ($this->processes as $process) {
                if (is_resource($process['handle'])) {
                    proc_terminate($process['handle'], 9);
                    proc_close($process['handle']);
                }
            }
            $this->processes = [];
            foreach ($this->secretFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $this->secretFiles = [];
            try {
                Session::discard();
            } catch (Throwable $e) {
                $this->failures[] = 'session_cleanup:' . $e->getMessage();
            }
            foreach ($cleanupPdos as $driver => $pdo) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    SoakTables::dropCreated($pdo);
                    $this->write('fixtures_dropped', ['driver' => $driver, 'tables' => SoakTables::names()]);
                } catch (Throwable $e) {
                    $this->failures[] = 'cleanup:' . $driver . ':' . $e->getMessage();
                }
            }
        }
        $this->checkCompletion();
        $elapsed = microtime(true) - $this->started;
        $expected = !$this->normalStop || $this->stopReason !== 'deadline_reached';
        if ($this->options['fault'] !== 'none') {
            $expected = true;
        }
        $ok = $this->failures === [] && !$expected;
        if (!$ok && $this->failures === []) {
            $this->failures[] = 'early_stop_or_fault_injection';
        }
        $summary = [
            'schema_version' => 1,
            'run_id' => basename($this->runDir),
            'prefix' => $this->prefix,
            'ok' => $ok,
            'expected_failure' => $this->options['fault'] !== 'none' && !$ok,
            'fault' => $this->options['fault'],
            'planned_seconds' => $this->options['duration'],
            'actual_seconds' => round($elapsed, 3),
            'stop_reason' => $this->stopReason,
            'normal_stop' => $this->normalStop,
            'drivers' => $this->driverStats,
            'failures' => array_values(array_unique($this->failures)),
            'worker_history' => $this->historySummary(),
            'report' => $this->runDir . '/summary.json',
            'events' => $this->runDir . '/events.jsonl',
            'limits' => $this->limits(),
        ];
        file_put_contents($this->runDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->write('run_finished', ['ok' => $ok, 'summary' => $summary]);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        return $ok ? 0 : 1;
    }

    private function startWorker(string $driver, int $worker, int $sequence): void
    {
        $log = $this->runDir . '/worker-' . $driver . '-' . $worker . '.jsonl';
        $configPath = $this->runDir . '/worker-' . $driver . '-' . $worker . '.php';
        $config = array_merge($this->driverConfig($driver), [
            'worker' => $worker,
            'prefix' => $this->prefix,
            'deadline' => $this->deadline,
            'stop_file' => $this->runDir . '/STOP',
            'log' => $log,
            'seed' => $this->options['seed'] + $sequence * 10007,
        ]);
        file_put_contents($configPath . '.json', json_encode($config, JSON_UNESCAPED_SLASHES));
        chmod($configPath . '.json', 0600);
        $this->secretFiles[] = $configPath . '.json';
        $command = array_merge([
            PHP_BINARY,
            '-d', 'memory_limit=' . $this->options['memory_limit'],
        ], $this->options['php_args'], [
            __DIR__ . '/Soak/Worker.php',
            $configPath . '.json',
        ]);
        $handle = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->runDir . '/worker-' . $driver . '-' . $worker . '.stdout.log', 'ab'], 2 => ['file', $this->runDir . '/worker-' . $driver . '-' . $worker . '.stderr.log', 'ab']], $pipes, dirname(__DIR__));
        if (!is_resource($handle)) {
            throw new RuntimeException('无法启动 worker: ' . $driver . ':' . $worker);
        }
        fclose($pipes[0]);
        $key = $driver . ':' . $worker;
        $this->processes[$key] = [
            'handle' => $handle,
            'driver' => $driver,
            'worker' => $worker,
            'log' => $log,
            'config_file' => $configPath . '.json',
            'offset' => 0,
            'partial' => '',
            'activity' => microtime(true),
            'last_log' => null,
            'heartbeat' => null,
            'memory_samples' => [],
            'heartbeat_memory' => [],
            'max' => [],
        ];
        $this->write('process_started', ['driver' => $driver, 'worker' => $worker, 'pid' => proc_get_status($handle)['pid']]);
    }

    private function collectProcess(string $key, int $exitCode): void
    {
        $process = $this->processes[$key];
        $this->readLog($process, true);
        $heartbeat = $this->latestHeartbeat($process);
        $process['heartbeat'] = $heartbeat;
        if ($heartbeat !== null) {
            $this->mergeStats($process['driver'], $heartbeat['stats'] ?? []);
        }
        $stats = $this->driverStats[$process['driver']];
        $stats['workers_finished']++;
        if ($exitCode === 0) {
            $stats['workers_exited_zero']++;
        } else {
            $this->failures[] = 'worker_exit:' . $key . ':' . $exitCode;
        }
        $stats['max'] = $process['max'];
        $stats['memory'][] = $this->memorySummary($process);
        $stats['workers'][$process['worker']] = [
            'exit_code' => $exitCode,
            'stats' => $heartbeat['stats'] ?? [],
            'memory' => $this->memorySummary($process),
        ];
        $stats['totals'] = $this->sumWorkerStats($stats['workers']);
        $this->driverStats[$process['driver']] = $stats;
        $this->write('process_finished', ['driver' => $process['driver'], 'worker' => $process['worker'], 'exit_code' => $exitCode, 'last_log' => $process['last_log']]);
        $this->workerHistory[$process['driver'] . ':' . $process['worker']] = $process;
        if (isset($process['config_file']) && is_file($process['config_file'])) {
            unlink($process['config_file']);
        }
        proc_close($process['handle']);
        unset($this->processes[$key]);
        if ($exitCode !== 0 && $this->stopReason === '') {
            $this->stopReason = 'worker_exit';
            $this->requestStop();
        }
    }

    private function readLog(array &$process, bool $final = false): ?array
    {
        $path = $process['log'];
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : 0;
        if ($size === false || $size < $process['offset']) {
            return $process['last_log'];
        }
        if ($size > $process['offset']) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return $process['last_log'];
            }
            fseek($handle, $process['offset']);
            $chunk = stream_get_contents($handle);
            fclose($handle);
            if (!is_string($chunk)) {
                return $process['last_log'];
            }
            $process['offset'] += strlen($chunk);
            $lines = explode("\n", $process['partial'] . $chunk);
            $process['partial'] = array_pop($lines);
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (!is_array($event)) {
                    $this->failures[] = 'invalid_worker_json:' . $process['driver'] . ':' . $process['worker'];
                    continue;
                }
                $process['last_log'] = $event;
                $process['activity'] = microtime(true);
                if ($event['type'] === 'heartbeat') {
                    $process['heartbeat'] = $event;
                    if (isset($event['memory']) && is_array($event['memory'])) {
                        $process['heartbeat_memory'][] = $event['memory'];
                    }
                }
                if (isset($event['memory_samples']) && is_array($event['memory_samples'])
                    && $event['memory_samples'] !== []) {
                    // 后续无统计的 stopped 事件不能抹掉完成事件中的完整序列。
                    $process['memory_samples'] = $event['memory_samples'];
                }
                if ($event['type'] === 'unexpected_exception' || $event['type'] === 'worker_failed' || $event['type'] === 'audit_failed') {
                    $this->failures[] = $event['type'] . ':' . $process['driver'] . ':' . $process['worker'];
                }
                if ($event['type'] === 'injected_commit_confirmed') {
                    $this->driverStats[$process['driver']]['injected_commit_confirmed']++;
                }
            }
        }
        if ($final && $process['partial'] !== '') {
            $this->failures[] = 'incomplete_worker_json:' . $process['driver'] . ':' . $process['worker'];
        }

        return $process['last_log'];
    }

    private function latestHeartbeat(array $process): ?array
    {
        $last = $process['last_log'];
        if (is_array($last) && in_array($last['type'], ['worker_finished', 'worker_failed', 'worker_stopped'], true)) {
            return $last;
        }
        $heartbeat = $process['heartbeat'];
        if ($heartbeat !== null) {
            return $heartbeat;
        }

        return null;
    }

    private function mergeStats(string $driver, array $stats): void
    {
        $current = $this->driverStats[$driver];
        $current['latest'] = $stats;
        $this->driverStats[$driver] = $current;
    }

    private function sumWorkerStats(array $workers): array
    {
        $totals = [];
        foreach ($workers as $worker) {
            foreach ($worker['stats'] as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                } elseif (is_array($value)) {
                    foreach ($value as $child => $number) {
                        if (is_int($number) || is_float($number)) {
                            $totals[$key][$child] = ($totals[$key][$child] ?? 0) + $number;
                        }
                    }
                }
            }
        }

        return $totals;
    }

    private function audit(string $driver, \PDO $pdo, bool $final): void
    {
        try {
            Session::use(new EntityGateway(new PdoConnection($pdo)));
            $audit = SoakTables::audit($pdo, $this->options['accounts'], $final);
            $this->driverStats[$driver]['audits'][] = $audit;
            $this->write('audit', ['driver' => $driver, 'audit' => $audit]);
            if (!$audit['ok']) {
                $this->failures[] = 'audit:' . $driver . ':' . implode(',', $audit['violations']);
                $this->stopReason = 'audit_failed';
                $this->requestStop();
            }
        } catch (Throwable $e) {
            $this->failures[] = 'audit_exception:' . $driver . ':' . $e->getMessage();
            $this->write('audit_exception', ['driver' => $driver, 'exception' => Database::exceptionInfo($e)]);
            $this->stopReason = 'audit_failed';
            $this->requestStop();
        } finally {
            try {
                Session::discard();
            } catch (Throwable $e) {
                $this->failures[] = 'audit_session:' . $driver . ':' . $e->getMessage();
            }
        }
    }

    private function assertOwnedTablesAbsent(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :prefix'
            : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE :prefix";
        $statement = $pdo->prepare($sql);
        $statement->execute(['prefix' => $this->prefix . '%']);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('随机 soak 表前缀已存在，拒绝覆盖');
        }
    }

    private function driverConfig(string $driver): array
    {
        $upper = strtoupper($driver);
        $dsn = getenv('PIXIAOKA_ORM_SOAK_' . $upper . '_DSN');
        if (!is_string($dsn) || $dsn === '') {
            throw new InvalidArgumentException('缺少 PIXIAOKA_ORM_SOAK_' . $upper . '_DSN，拒绝使用默认数据库');
        }
        $user = getenv('PIXIAOKA_ORM_SOAK_' . $upper . '_USER') ?: '';
        $password = getenv('PIXIAOKA_ORM_SOAK_' . $upper . '_PASSWORD') ?: '';

        return [
            'driver' => $driver,
            'dsn' => $dsn,
            'user' => $user,
            'password' => $password,
            'accounts' => $this->options['accounts'],
            'rotate' => $this->options['rotate'],
            'sample' => $this->options['sample'],
            'audit_interval' => $this->options['audit_interval'],
            'weights' => $this->options['weights'],
            'delay_ms' => $this->options['delay_ms'],
            'fault' => $this->options['fault'],
        ];
    }

    private function requestStop(): void
    {
        if ($this->stopAt === 0.0) {
            $this->stopAt = microtime(true);
        }
        if (!is_file($this->runDir . '/STOP')) {
            file_put_contents($this->runDir . '/STOP', $this->stopReason . "\n");
        }
    }

    private function terminateWorkers(): void
    {
        $this->requestStop();
        foreach ($this->processes as $process) {
            proc_terminate($process['handle'], 9);
        }
    }

    private function installSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->stopRequests[$signal] = true;
            });
        }
    }

    private function checkSignals(): void
    {
        if ($this->stopRequests === []) {
            return;
        }
        $this->stopReason = 'signal';
        $this->failures[] = 'interrupted_by_signal';
        $this->requestStop();
        $this->stopRequests = [];
    }

    private function historySummary(): array
    {
        $result = [];
        foreach ($this->workerHistory as $key => $process) {
            $result[$key] = [
                'last_log' => $process['last_log'],
                'heartbeat' => $process['heartbeat'],
            ];
        }

        return $result;
    }

    private function checkCompletion(): void
    {
        if ($this->options['fault'] !== 'none') {
            return;
        }
        if (!$this->normalStop || microtime(true) - $this->started + 1 < $this->options['duration']) {
            $this->failures[] = 'duration_not_reached';
        }
        $required = ['transfer_commits', 'explicit_rollbacks', 'exception_rollbacks', 'relation_checks', 'optimistic_conflicts', 'connection_rotations', 'audits'];
        foreach ($this->driverStats as $driver => $stats) {
            $totals = $stats['totals'];
            foreach ($required as $key) {
                if (($totals[$key] ?? 0) < $this->options['workers']) {
                    $this->failures[] = 'coverage:' . $driver . ':' . $key;
                }
            }
            if ($stats['workers_finished'] !== $this->options['workers']
                || $stats['workers_exited_zero'] !== $this->options['workers']) {
                $this->failures[] = 'workers_not_clean:' . $driver;
            }
            foreach ($stats['memory'] as $index => $memory) {
                if (($memory['samples'] ?? 0) < 3) {
                    $this->failures[] = 'memory_samples:' . $driver . ':' . $index;
                }
                if (($memory['growth_bytes'] ?? PHP_INT_MAX) > $this->options['memory_growth']) {
                    $this->failures[] = 'memory_growth:' . $driver . ':' . $index;
                }
            }
            $finalAudit = $stats['audits'][array_key_last($stats['audits'])] ?? null;
            if (!is_array($finalAudit) || !($finalAudit['final'] ?? false) || !($finalAudit['ok'] ?? false)) {
                $this->failures[] = 'final_audit:' . $driver;
            } elseif (($totals['transfer_commits'] ?? 0) + $stats['injected_commit_confirmed'] !== $finalAudit['effects']) {
                $this->failures[] = 'effect_reconciliation:' . $driver;
            }
        }
    }

    private function monitorSummary(): array
    {
        $workers = [];
        foreach ($this->processes as $key => $process) {
            $workers[$key] = [
                'heartbeat_age_seconds' => round(microtime(true) - $process['activity'], 3),
                'last_type' => $process['last_log']['type'] ?? null,
                'stats' => $process['heartbeat']['stats'] ?? null,
            ];
        }

        return ['workers' => $workers, 'stop_reason' => $this->stopReason];
    }

    private function memorySummary(array $process): array
    {
        $samples = $process['memory_samples'];
        if ($samples === []) {
            $samples = $process['heartbeat_memory'];
        }
        $values = array_values(array_filter(array_map(static fn (array $sample): int => (int) ($sample['real'] ?? 0), $samples)));
        $count = count($values);
        if ($count === 0) {
            return ['samples' => 0];
        }
        $warm = $values[min($count - 1, max(0, intdiv($count, 4)))];
        $final = $values[$count - 1];
        $growth = $final - $warm;

        return [
            'samples' => $count,
            'warm_bytes' => $warm,
            'final_bytes' => $final,
            'growth_bytes' => $growth,
            'max_bytes' => max($values),
            'threshold_bytes' => $this->options['memory_growth'],
        ];
    }

    private function publicOptions(): array
    {
        return array_merge($this->options, [
            'prefix' => $this->prefix,
            'run_dir' => $this->runDir,
            'deadline_utc' => gmdate('c', (int) $this->deadline),
        ]);
    }

    private function limits(): array
    {
        return ['memory_growth_bytes' => $this->options['memory_growth'], 'heartbeat_timeout_seconds' => $this->options['heartbeat_timeout']];
    }

    private function emptyStats(): array
    {
        return [
            'workers_finished' => 0,
            'workers_exited_zero' => 0,
            'latest' => [],
            'max' => [],
            'memory' => [],
            'workers' => [],
            'totals' => [],
            'audits' => [],
            'injected_commit_confirmed' => 0,
        ];
    }

    private function write(string $type, array $extra = []): void
    {
        $record = array_merge(['type' => $type, 'at' => microtime(true)], $extra);
        file_put_contents($this->runDir . '/events.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}

function soakUsage(): never
{
    echo "用法: php tests/orm_soak.php [选项]\n";
    echo "  --duration=1800 秒；--workers=4 每库进程数；--drivers=mysql,pgsql\n";
    echo "  --sample=10 --audit-interval=60 --rotate=25 --accounts=12 --delay-ms=10\n";
    echo "  --memory-growth=67108864 --heartbeat-timeout=90 --grace=30\n";
    echo "  --output=build/soak --seed=20261120 --fault=none|ledger|worker_crash\n";
    echo "必须显式设置 PIXIAOKA_ORM_SOAK_MYSQL_DSN / PIXIAOKA_ORM_SOAK_PGSQL_DSN。\n";
    exit(2);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = $argv;
    array_shift($args);
    $options = [
        'duration' => 1800,
        'workers' => 4,
        'drivers' => ['mysql', 'pgsql'],
        'sample' => 10,
        'audit_interval' => 60,
        'rotate' => 25,
        'accounts' => 12,
        'delay_ms' => 10,
        'memory_growth' => 67108864,
        'memory_limit' => '256M',
        'heartbeat_timeout' => 90,
        'grace' => 30,
        'output' => '',
        'seed' => 20261120,
        'fault' => 'none',
        'weights' => ['transfer' => 45, 'rollback' => 15, 'exception' => 10, 'contention' => 10, 'relation' => 20],
        'php_args' => [],
    ];
    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            soakUsage();
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            soakUsage();
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $key = str_replace('-', '_', $key);
        if (!array_key_exists($key, $options)) {
            soakUsage();
        }
        $options[$key] = match ($key) {
            'drivers' => array_values(array_filter(explode(',', $value))),
            'fault' => $value,
            'output', 'memory_limit' => $value,
            default => is_numeric($value) ? (int) $value : $value,
        };
    }
    foreach (['duration', 'workers', 'sample', 'audit_interval', 'rotate', 'accounts', 'heartbeat_timeout', 'grace', 'memory_growth'] as $key) {
        if (!is_int($options[$key]) || $options[$key] < 1) {
            soakUsage();
        }
    }
    if ($options['delay_ms'] < 0 || $options['accounts'] < 4 || $options['duration'] < 10
        || !in_array($options['fault'], ['none', 'ledger', 'worker_crash'], true)
        || $options['drivers'] === [] || array_diff($options['drivers'], ['mysql', 'pgsql']) !== []
        || array_sum($options['weights']) !== 100) {
        soakUsage();
    }
    $options['drivers'] = array_values(array_unique($options['drivers']));
    exit((new SoakRunner($options))->run());
}
