<?php

namespace PLSys\DistrbutionQueue\App\Services;

class WorkerCoordinator
{
    private DistributionCache $cache;
    private string $workerId;
    private int $sleepSeconds;
    private int $rebalanceInterval;
    private float $lastRebalance = 0;
    private array $assignedJobs = [];

    public function __construct(DistributionCache $cache, string $workerId, int $sleepSeconds)
    {
        $this->cache = $cache;
        $this->workerId = $workerId;
        $this->sleepSeconds = $sleepSeconds;
        $this->rebalanceInterval = (int) config('distribution.supervisor.rebalance_interval', 10);
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * Send heartbeat to Redis.
     */
    public function heartbeat(): void
    {
        $this->cache->registerWorker($this->workerId, $this->sleepSeconds);
    }

    /**
     * Unregister this worker.
     */
    public function shutdown(): void
    {
        $this->cache->unregisterWorker($this->workerId);
    }

    /**
     * Filter job names to only those assigned to this worker.
     * Uses consistent hashing — all workers compute the same result independently.
     */
    public function filterAssignedJobs(array $allJobNames): array
    {
        $now = microtime(true);

        if (($now - $this->lastRebalance) >= $this->rebalanceInterval || empty($this->assignedJobs)) {
            $this->rebalance($allJobNames);
            $this->lastRebalance = $now;
        }

        return array_values(array_intersect($allJobNames, $this->assignedJobs));
    }

    /**
     * Rebalance: assign jobs to this worker based on consistent hashing.
     * Uses TTL based on sleep time to detect dead workers.
     */
    private function rebalance(array $allJobNames): void
    {
        // Use 3× sleep as TTL — worker is stale if 3 consecutive heartbeats missed
        $workers = $this->cache->getActiveWorkers($this->sleepSeconds * 3);

        if (empty($workers)) {
            // No workers registered (Redis down?) — take all jobs
            $this->assignedJobs = $allJobNames;
            return;
        }

        $myIndex = array_search($this->workerId, $workers);
        if ($myIndex === false) {
            // This worker is not yet registered — take all jobs as safety
            $this->assignedJobs = $allJobNames;
            return;
        }

        sort($allJobNames);
        $workerCount = count($workers);
        $this->assignedJobs = [];

        foreach ($allJobNames as $i => $name) {
            if ($i % $workerCount === $myIndex) {
                $this->assignedJobs[] = $name;
            }
        }
    }
}
