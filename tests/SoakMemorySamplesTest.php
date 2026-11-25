<?php

declare(strict_types=1);

namespace Tests\Pixiaoka\Orm;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/orm_soak.php';

final class SoakMemorySamplesTest extends TestCase
{
    private string $runDir;

    protected function setUp(): void
    {
        $this->runDir = sys_get_temp_dir() . '/orm-soak-memory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->runDir, 0700, true));
    }

    protected function tearDown(): void
    {
        $log = $this->runDir . '/worker.jsonl';
        if (is_file($log)) {
            unlink($log);
        }
        if (is_dir($this->runDir)) {
            rmdir($this->runDir);
        }
    }

    public function testStoppedEventDoesNotDiscardCompleteMemorySamples(): void
    {
        $summary = $this->readMemorySummary([
            ['type' => 'heartbeat', 'memory' => $this->sample(1000)],
            ['type' => 'heartbeat', 'memory' => $this->sample(1200)],
            ['type' => 'worker_finished', 'memory_samples' => [
                $this->sample(1000),
                $this->sample(1200),
                $this->sample(1400),
                $this->sample(1800),
            ]],
            ['type' => 'worker_stopped'],
        ]);

        self::assertSame([
            'samples' => 4,
            'warm_bytes' => 1200,
            'final_bytes' => 1800,
            'growth_bytes' => 600,
            'max_bytes' => 1800,
            'threshold_bytes' => 67108864,
        ], $summary);
    }

    public function testFailureRetainsAllHeartbeatMemorySamples(): void
    {
        $summary = $this->readMemorySummary([
            ['type' => 'heartbeat', 'memory' => $this->sample(1000)],
            ['type' => 'heartbeat', 'memory' => $this->sample(1200)],
            ['type' => 'heartbeat', 'memory' => $this->sample(1600)],
            ['type' => 'worker_failed', 'exception' => ['class' => 'RuntimeException']],
            ['type' => 'worker_stopped'],
        ]);

        self::assertSame([
            'samples' => 3,
            'warm_bytes' => 1000,
            'final_bytes' => 1600,
            'growth_bytes' => 600,
            'max_bytes' => 1600,
            'threshold_bytes' => 67108864,
        ], $summary);
    }

    public function testExplicitSamplesWinEvenWhenStoppedEventFollowsThem(): void
    {
        $summary = $this->readMemorySummary([
            ['type' => 'worker_finished', 'memory_samples' => [
                $this->sample(1000),
                $this->sample(1200),
                $this->sample(1600),
            ]],
            ['type' => 'worker_stopped'],
        ]);

        self::assertSame(3, $summary['samples']);
        self::assertSame(1600, $summary['final_bytes']);
    }

    public function testLaterCompleteSampleSetReplacesEarlierPartialSet(): void
    {
        $summary = $this->readMemorySummary([
            ['type' => 'heartbeat', 'memory_samples' => [$this->sample(1000)]],
            ['type' => 'worker_finished', 'memory_samples' => [
                $this->sample(1000),
                $this->sample(1200),
                $this->sample(2000),
            ]],
            ['type' => 'worker_stopped'],
        ]);

        self::assertSame(3, $summary['samples']);
        self::assertSame(2000, $summary['final_bytes']);
    }

    public function testEmptyLaterSampleSetDoesNotEraseCompleteSamples(): void
    {
        $summary = $this->readMemorySummary([
            ['type' => 'worker_finished', 'memory_samples' => [
                $this->sample(1000),
                $this->sample(1100),
                $this->sample(1500),
            ]],
            ['type' => 'worker_stopped', 'memory_samples' => []],
        ]);

        self::assertSame(3, $summary['samples']);
        self::assertSame(1500, $summary['final_bytes']);
    }

    private function readMemorySummary(array $events): array
    {
        $log = $this->runDir . '/worker.jsonl';
        $lines = array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR),
            $events,
        );
        file_put_contents($log, implode("\n", $lines) . "\n");

        $reflection = new ReflectionClass(\SoakRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();
        $options = $reflection->getProperty('options');
        $options->setValue($runner, ['memory_growth' => 67108864]);
        $failures = $reflection->getProperty('failures');
        $failures->setValue($runner, []);
        $process = [
            'driver' => 'mysql',
            'worker' => 1,
            'log' => $log,
            'offset' => 0,
            'partial' => '',
            'last_log' => null,
            'heartbeat' => null,
            'memory_samples' => [],
            'heartbeat_memory' => [],
        ];
        $readLog = $reflection->getMethod('readLog');
        $readLog->invokeArgs($runner, [&$process, true]);
        $memorySummary = $reflection->getMethod('memorySummary');

        return $memorySummary->invoke($runner, $process);
    }

    private function sample(int $real): array
    {
        return ['real' => $real];
    }
}
