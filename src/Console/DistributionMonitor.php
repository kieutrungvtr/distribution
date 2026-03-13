<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use Carbon\Carbon;

class DistributionMonitor extends Command
{
    protected $signature = 'distribution:monitor
                            {--job= : Filter by specific job name}
                            {--detail : Show per-request breakdown}
                            {--failures : Show recent failures}
                            {--watch=0 : Auto-refresh every N seconds (0 = once)}
                            {--json : Output as JSON}';

    protected $description = 'Monitor distribution queue status';

    public function handle()
    {
        $jobName  = $this->option('job') ?: null;
        $detail   = $this->option('detail');
        $failures = $this->option('failures');
        $watch    = (int) $this->option('watch');
        $json     = $this->option('json');

        $repo  = app(DistributionRepository::class);
        $cache = app(DistributionCache::class);
        $isFirstRender = true;

        // Register signal handler for clean exit in watch mode
        if ($watch > 0) {
            $this->registerWatchSignals();
        }

        do {
            if (!$isFirstRender) {
                // Move cursor to home position — overwrite previous output in place
                $this->output->write("\033[H");
            }

            if ($json) {
                $this->outputJson($repo, $cache, $jobName, $detail, $failures);
            } else {
                $this->outputTable($repo, $cache, $jobName, $detail, $failures);
            }

            if ($watch > 0) {
                // Clear any leftover lines below from previous (longer) render
                $this->output->write("\033[J");

                if ($isFirstRender) {
                    $this->output->write("\033[?25l"); // hide cursor
                    $isFirstRender = false;
                }

                sleep($watch);
            }
        } while ($watch > 0 && !$this->shouldStopWatch);

        if (!$isFirstRender) {
            $this->output->write("\033[?25h"); // restore cursor
        }
    }

    private bool $shouldStopWatch = false;

    private function registerWatchSignals(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function () {
            $this->shouldStopWatch = true;
            $this->output->write("\033[?25h"); // restore cursor before exit
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function outputTable(DistributionRepository $repo, DistributionCache $cache, ?string $jobName, bool $detail, bool $failures): void
    {
        $timestamp = Carbon::now()->toDateTimeString();
        $quota = config('distribution.quota');

        $this->newLine();
        $this->info("=== Distribution Queue Monitor [$timestamp] ===");
        $this->newLine();

        // ── Workers (when supervisor + cache enabled) ──
        if ($cache->isEnabled() && config('distribution.supervisor.enabled', false)) {
            $workers = $cache->getActiveWorkers();
            $workerCount = count($workers);
            $this->info("Active Workers: $workerCount");
            if ($workerCount > 0) {
                $this->table(
                    ['#', 'Worker ID'],
                    collect($workers)->map(function ($id, $index) {
                        return [$index + 1, $id];
                    })->toArray()
                );
            }
            $this->newLine();
        }

        // ── Overview table ──
        $stats = $repo->getStats($jobName);

        if (empty($stats)) {
            $this->warn('No distributions found.');
            return;
        }

        // Build Redis pushed counts for comparison
        $redisCounts = [];
        if ($cache->isEnabled()) {
            foreach ($stats as $row) {
                $redisCounts[$row['job']] = $cache->getRedisPushedCount($row['job']);
            }
        }

        $headers = ['Job', 'Pending', 'Pushed', 'Processing', 'Failed', 'Completed', 'Total', 'Quota'];
        if ($cache->isEnabled()) {
            $headers[] = 'Redis Pushed';
        }

        $this->info('Jobs Overview:');
        $this->table(
            $headers,
            collect($stats)->map(function ($row) use ($quota, $cache, $redisCounts) {
                $pushed = $row['pushed'];
                $quotaDisplay = "$pushed / $quota";
                if ($pushed >= $quota) {
                    $quotaDisplay .= ' FULL';
                }
                $cols = [
                    $row['job'],
                    $row['initial'],
                    $row['pushed'],
                    $row['processing'],
                    $this->colorFailed($row['failed']),
                    $row['completed'],
                    $row['total'],
                    $quotaDisplay,
                ];
                if ($cache->isEnabled()) {
                    $redisVal = $redisCounts[$row['job']] ?? '-';
                    $driftWarning = '';
                    if (is_int($redisVal) && $redisVal !== $row['pushed']) {
                        $driftWarning = ' <fg=yellow>DRIFT</>';
                    }
                    $cols[] = ($redisVal ?? '-') . $driftWarning;
                }
                return $cols;
            })->toArray()
        );

        // ── Totals ──
        $totals = collect($stats)->reduce(function ($carry, $row) {
            foreach (['initial', 'pushed', 'processing', 'failed', 'completed', 'total'] as $key) {
                $carry[$key] = ($carry[$key] ?? 0) + $row[$key];
            }
            return $carry;
        }, []);
        $this->line(sprintf(
            '  Total: %d items | Pending: %d | In-flight: %d | Failed: %d | Done: %d',
            $totals['total'],
            $totals['initial'],
            $totals['pushed'] + $totals['processing'],
            $totals['failed'],
            $totals['completed']
        ));
        $this->newLine();

        // ── Per request_id detail ──
        if ($detail) {
            $targetJob = $jobName;
            if (!$targetJob && count($stats) === 1) {
                $targetJob = $stats[0]['job'];
            }
            if (!$targetJob) {
                $this->warn('Use --job=JobName with --detail to see per-request breakdown.');
            } else {
                $this->info("Breakdown by request_id for [$targetJob]:");
                $requestStats = $repo->getStatsByRequestId($targetJob);
                $this->table(
                    ['Request ID', 'Pending', 'Pushed', 'Processing', 'Failed', 'Completed', 'Total'],
                    collect($requestStats)->map(function ($row) {
                        return [
                            $row['request_id'],
                            $row['initial'],
                            $row['pushed'],
                            $row['processing'],
                            $this->colorFailed($row['failed']),
                            $row['completed'],
                            $row['total'],
                        ];
                    })->toArray()
                );
            }
        }

        // ── Recent failures ──
        if ($failures) {
            $this->info('Recent Failures:');
            $failedItems = $repo->getRecentFailures($jobName, 20);

            if (empty($failedItems)) {
                $this->line('  No failures found.');
            } else {
                $this->table(
                    ['ID', 'Request', 'Job', 'Tries', 'Error', 'Failed At'],
                    collect($failedItems)->map(function ($row) {
                        return [
                            $row['id'],
                            $row['request_id'],
                            $row['job'],
                            $row['tries'],
                            \Illuminate\Support\Str::limit($row['error'] ?? '-', 60),
                            $row['failed_at'],
                        ];
                    })->toArray()
                );
            }
        }
    }

    private function outputJson(DistributionRepository $repo, DistributionCache $cache, ?string $jobName, bool $detail, bool $failures): void
    {
        $data = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'quota' => (int) config('distribution.quota'),
            'jobs' => $repo->getStats($jobName),
        ];

        if ($cache->isEnabled()) {
            $data['redis'] = ['pushed_counts' => []];
            foreach ($data['jobs'] as $row) {
                $data['redis']['pushed_counts'][$row['job']] = $cache->getRedisPushedCount($row['job']);
            }

            if (config('distribution.supervisor.enabled', false)) {
                $data['workers'] = $cache->getActiveWorkers();
            }
        }

        if ($detail && $jobName) {
            $data['requests'] = $repo->getStatsByRequestId($jobName);
        }

        if ($failures) {
            $data['failures'] = $repo->getRecentFailures($jobName);
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    private function colorFailed(int $count): string
    {
        return $count > 0 ? "<fg=red>$count</>" : (string) $count;
    }
}
