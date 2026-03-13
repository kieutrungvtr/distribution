<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Carbon\Carbon;

class DistributionSupervisor extends Command
{
    protected $signature = 'distribution:supervisor
        {--workers=3 : Number of worker processes}
        {--sleep=5 : Worker sleep between cycles}
        {--tries=3 : Max retry attempts}
        {--range=1 : Hours before retrying}
        {--memory= : Worker memory limit in MB (default from config)}';

    protected $description = 'Supervisor — spawns and monitors multiple distribution worker processes';

    private array $processes = [];
    private bool $shouldQuit = false;
    private $lockHandle = null;

    public function handle()
    {
        if (!config('distribution.supervisor.enabled', false)) {
            $this->error('Supervisor is not enabled. Set DISTRIBUTION_SUPERVISOR_ENABLED=true');
            return 1;
        }

        if (!config('distribution.cache.enabled', false)) {
            $this->error('Supervisor requires cache (Redis). Set DISTRIBUTION_CACHE_ENABLED=true');
            return 1;
        }

        // PID file lock — prevents duplicate supervisors
        if (!$this->acquireLock()) {
            $this->error('Another supervisor is already running. Remove ' . storage_path('distribution-supervisor.lock') . ' if this is incorrect.');
            return 1;
        }

        $workerCount = (int) $this->option('workers')
            ?: (int) config('distribution.supervisor.workers', 3);

        $this->registerSignalHandlers();

        $timestamp = Carbon::now()->toDateTimeString();
        $this->info("[$timestamp] Supervisor started with $workerCount workers.");

        for ($i = 0; $i < $workerCount; $i++) {
            $this->startWorker();
        }

        while (!$this->shouldQuit) {
            $this->monitorWorkers();
            sleep(1);
            $this->dispatchSignals();
        }

        $this->terminateAll();
        $this->releaseLock();
        return 0;
    }

    private function startWorker(): void
    {
        $id = Str::uuid()->toString();
        $artisan = base_path('artisan');
        $memory = $this->option('memory') ?? config('distribution.worker.memory_limit', 128);
        $cmd = [
            PHP_BINARY, $artisan, 'distribution:work',
            "--id={$id}",
            "--sleep={$this->option('sleep')}",
            "--tries={$this->option('tries')}",
            "--range={$this->option('range')}",
            "--memory={$memory}",
        ];

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->start(function ($type, $buffer) {
            // Forward worker output
            $this->output->write($buffer);
        });

        $this->processes[$process->getPid()] = [
            'process' => $process,
            'id' => $id,
            'started_at' => now(),
        ];

        $timestamp = Carbon::now()->toDateTimeString();
        $this->info("[$timestamp]   Worker $id started (PID: {$process->getPid()})");
    }

    private function monitorWorkers(): void
    {
        foreach ($this->processes as $pid => $info) {
            if (!$info['process']->isRunning()) {
                $timestamp = Carbon::now()->toDateTimeString();
                $this->warn("[$timestamp]   Worker {$info['id']} died (PID: $pid), restarting...");
                unset($this->processes[$pid]);
                if (!$this->shouldQuit) {
                    $this->startWorker();
                }
            }
        }
    }

    private function terminateAll(): void
    {
        $timestamp = Carbon::now()->toDateTimeString();
        $this->info("[$timestamp] Shutting down workers...");

        foreach ($this->processes as $info) {
            if ($info['process']->isRunning()) {
                $info['process']->signal(SIGTERM);
            }
        }

        $deadline = time() + 10;
        while (time() < $deadline) {
            $allStopped = true;
            foreach ($this->processes as $info) {
                if ($info['process']->isRunning()) {
                    $allStopped = false;
                }
            }
            if ($allStopped) {
                break;
            }
            usleep(200000);
        }

        foreach ($this->processes as $info) {
            if ($info['process']->isRunning()) {
                $info['process']->signal(SIGKILL);
            }
        }

        $timestamp = Carbon::now()->toDateTimeString();
        $this->info("[$timestamp] All workers stopped.");
    }

    /**
     * Acquire file lock to prevent duplicate supervisor instances.
     * Lock is auto-released by OS if process dies (even SIGKILL).
     */
    private function acquireLock(): bool
    {
        $lockFile = storage_path('distribution-supervisor.lock');
        $this->lockHandle = @fopen($lockFile, 'w');

        if (!$this->lockHandle) {
            return false;
        }

        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }

        fwrite($this->lockHandle, (string) getmypid());
        fflush($this->lockHandle);
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            @unlink(storage_path('distribution-supervisor.lock'));
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->info("\nReceived SIGTERM, shutting down...");
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGINT, function () {
            $this->info("\nReceived SIGINT, shutting down...");
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
