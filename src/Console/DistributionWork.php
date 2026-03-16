<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use PLSys\DistrbutionQueue\App\Services\PushingService;
use PLSys\DistrbutionQueue\App\Services\WorkerCoordinator;
use Carbon\Carbon;

class DistributionWork extends Command
{
    protected $signature = 'distribution:work
                            {--id= : Worker ID (auto-assigned by supervisor)}
                            {--sleep=5 : Seconds to wait between cycles}
                            {--tries=3 : Max retry attempts for failed jobs}
                            {--range=1 : Hours before retrying a failed job}
                            {--auto-retry : Enable automatic retry of failed jobs (default: from config)}
                            {--memory= : Memory limit in MB (default from config)}
                            {--once : Run one cycle then exit}';

    protected $description = 'Distribution worker daemon — auto-discovers and pushes all pending jobs';

    private bool $shouldQuit = false;
    private ?WorkerCoordinator $coordinator = null;
    private float $lastPushedCountSync = 0;

    public function handle()
    {
        // Prevent memory leak from query log accumulation
        DB::disableQueryLog();

        $sleep = (int) ($this->option('sleep') ?? config('distribution.worker.sleep', 5));
        $tries = (int) ($this->option('tries') ?? config('distribution.worker.tries', 3));
        $range = (int) ($this->option('range') ?? config('distribution.worker.range', 1));
        $autoRetry = $this->option('auto-retry') ?: config('distribution.worker.auto_retry', false);
        $once  = $this->option('once');
        $batch = (int) config('distribution.batch');
        $workerId = $this->option('id');
        $memoryLimit = (int) ($this->option('memory') ?? config('distribution.worker.memory_limit', 128));

        // Set up coordinator if running under supervisor with Redis
        $cache = app(DistributionCache::class);
        if ($workerId && config('distribution.supervisor.enabled', false) && $cache->isEnabled()) {
            $this->coordinator = new WorkerCoordinator($cache, $workerId, $sleep);
            $this->coordinator->heartbeat();
        }

        $this->registerSignalHandlers();
        $idLabel = $workerId ? " (ID: $workerId)" : '';
        $this->info('[' . Carbon::now()->toDateTimeString() . "] Distribution worker started{$idLabel}. Press Ctrl+C to stop.");

        while (!$this->shouldQuit) {
            if ($this->coordinator) {
                $this->coordinator->heartbeat();
            }

            $this->cycle($batch, $tries, $range, $autoRetry, $cache);

            if ($once) {
                break;
            }

            // Memory limit check — supervisor will auto-restart
            $memoryMB = memory_get_usage(true) / 1024 / 1024;
            if ($memoryMB > $memoryLimit) {
                $this->warn('[' . Carbon::now()->toDateTimeString() . "] Memory limit ({$memoryLimit}MB) exceeded ({$memoryMB}MB), restarting...");
                break;
            }

            sleep($sleep);
            $this->dispatchSignals();
        }

        if ($this->coordinator) {
            $this->coordinator->shutdown();
        }

        $this->info('[' . Carbon::now()->toDateTimeString() . '] Distribution worker stopped.');
    }

    private function cycle(int $batch, int $tries, int $range, bool $autoRetry, DistributionCache $cache): void
    {
        $repo = app(DistributionRepository::class);

        $jobNames = $cache->getActiveJobNames(function () use ($repo) {
            return $repo->getActiveJobNames();
        });

        if (empty($jobNames)) {
            return;
        }

        // If coordinator is active, only process assigned jobs
        if ($this->coordinator) {
            $jobNames = $this->coordinator->filterAssignedJobs($jobNames);
            if (empty($jobNames)) {
                return;
            }
        }

        // Periodic pushed count sync — correct any Redis drift every 60s
        if ($cache->isEnabled() && (microtime(true) - $this->lastPushedCountSync) >= 60) {
            $this->syncPushedCounts($cache, $repo, $jobNames);
            $this->lastPushedCountSync = microtime(true);
        }

        $timestamp = Carbon::now()->toDateTimeString();

        foreach ($jobNames as $jobName) {
            if ($this->shouldQuit) {
                break;
            }

            // Push pending items
            $pushService = app(PushingService::class);
            $response = $pushService->process($jobName, $batch);

            if ($response->getStatusCode() === 200) {
                $this->line("[$timestamp] [PUSH]  $jobName: " . $response->getContent());
            }

            // Retry failed items (only when explicitly enabled)
            if ($autoRetry) {
                $retryService = app(PushingService::class);
                $retryService->backlogFlag(true);
                $retryResponse = $retryService->process($jobName, $batch, $tries, $range);

                if ($retryResponse->getStatusCode() === 200) {
                    $this->line("[$timestamp] [RETRY] $jobName: " . $retryResponse->getContent());
                }
            }
        }
    }

    /**
     * Sync Redis pushed counts from DB to correct any drift.
     */
    private function syncPushedCounts(DistributionCache $cache, DistributionRepository $repo, array $jobNames): void
    {
        foreach ($jobNames as $jobName) {
            $dbCount = $repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, $jobName);
            $cache->syncPushedCount($jobName, $dbCount);
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->info("\nReceived SIGTERM, shutting down gracefully...");
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGINT, function () {
            $this->info("\nReceived SIGINT, shutting down gracefully...");
            $this->shouldQuit = true;
        });
    }

    private function dispatchSignals(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_signal_dispatch();
        }
    }
}
