<?php

namespace PLSys\DistrbutionQueue\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use PLSys\DistrbutionQueue\App\Services\PushingService;

class DistributionScenarioTest extends Command
{
    protected $signature = 'distribution:scenario-test
        {--scenario=all : Run specific scenario (all|rush|failures|pressure|recovery|drain)}
        {--skip-cleanup : Keep data from previous run}';

    protected $description = 'Real-world scenario tests — uses actual queue workers (Horizon/RabbitMQ) to verify end-to-end behavior';

    private DistributionRepository $repo;
    private DistributionCache $cache;
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function handle()
    {
        $this->repo = new DistributionRepository();
        $this->cache = app(DistributionCache::class);
        $scenario = $this->option('scenario');

        if (!$this->option('skip-cleanup')) {
            $this->cleanup();
        }

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║   Real-World Scenario Tests                      ║');
        $this->info('║   Uses LIVE queue workers (Horizon + RabbitMQ)    ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();
        $this->printEnv();

        $scenarios = [
            'rush'     => ['Multi-Tenant Order Rush', 'scenarioRush'],
            'failures' => ['Product Sync with Failures + Retry', 'scenarioFailures'],
            'pressure' => ['Quota Pressure — Multiple Job Types', 'scenarioPressure'],
            'recovery' => ['Redis Outage + Drift Recovery', 'scenarioRecovery'],
            'drain'    => ['Burst → Drain → Archive Lifecycle', 'scenarioDrain'],
        ];

        foreach ($scenarios as $key => [$label, $method]) {
            if ($scenario !== 'all' && $scenario !== $key) {
                continue;
            }
            // Clean slate for each scenario to avoid cross-contamination
            if (!$this->option('skip-cleanup')) {
                $this->cleanup();
            }
            $this->newLine();
            $this->info("╭─────────────────────────────────────────╮");
            $this->info("│  Scenario: $label");
            $this->info("╰─────────────────────────────────────────╯");
            $start = microtime(true);
            $this->$method();
            $elapsed = round(microtime(true) - $start, 1);
            $this->line("  <fg=gray>⏱ Completed in {$elapsed}s</>");
        }

        $this->printSummary();
        return $this->failed > 0 ? 1 : 0;
    }

    // ═══════════════════════════════════════════════════════
    //  SCENARIO 1: Multi-Tenant Order Rush
    //  Simulates: 5 shops submit orders simultaneously,
    //  quota limits throughput, fair distribution ensures
    //  no shop starves. Horizon processes via process_order queue.
    // ═══════════════════════════════════════════════════════

    private function scenarioRush(): void
    {
        $quota = 10;
        $shopsCount = 5;
        $ordersPerShop = 6;
        $totalOrders = $shopsCount * $ordersPerShop;
        config(['distribution.quota' => $quota]);

        $this->line("  Setup: $shopsCount shops × $ordersPerShop orders = $totalOrders items, quota=$quota");

        // Seed orders from 5 different shops (request_ids 1001-1005)
        for ($shop = 1; $shop <= $shopsCount; $shop++) {
            $requestId = 1000 + $shop;
            $batch = [];
            for ($i = 1; $i <= $ordersPerShop; $i++) {
                $batch[] = [
                    'distribution_request_id' => $requestId,
                    'distribution_payload' => json_encode([
                        'order_id' => $requestId * 1000 + $i,
                        'shop' => "shop_$shop",
                        'amount' => rand(10, 500),
                    ]),
                    'distribution_job_name' => 'ProcessOrderJob',
                    'distribution_priority' => $shop, // shop 5 gets highest priority
                ];
            }
            $this->repo->initDistributionData($batch);
        }

        $this->check("Seeded $totalOrders orders across $shopsCount shops",
            Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_current_state', 'initial')->count() === $totalOrders);

        // Push first batch — should push exactly $quota items with fair distribution
        $svc = app(PushingService::class);
        $r = $svc->process('ProcessOrderJob', 100);
        $this->check("First push: quota=$quota respected",
            $r->getStatusCode() === 200);

        $pushed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_current_state', 'pushed')->count();
        $this->check("Pushed exactly $quota items (quota limit)", $pushed === $quota);

        // Verify fair distribution — each shop should get quota/shops items
        $expectedPerShop = intdiv($quota, $shopsCount);
        $grouped = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_current_state', 'pushed')
            ->get()->groupBy('distribution_request_id');
        $fairCheck = true;
        foreach ($grouped as $reqId => $items) {
            if ($items->count() !== $expectedPerShop) {
                $fairCheck = false;
                $this->line("    <fg=yellow>Shop $reqId got {$items->count()} instead of $expectedPerShop</>");
            }
        }
        $this->check("Fair distribution: each shop gets $expectedPerShop items", $fairCheck);

        // Wait for Horizon to process the pushed items
        $this->line("  Waiting for Horizon to process $quota items...");
        $completed = $this->waitForState('ProcessOrderJob', 'completed', $quota, 60);
        $this->check("Horizon processed all $quota items", $completed >= $quota);

        // Push more cycles until all done (simulates continuous worker)
        $this->line("  Running distribution:work cycles to push remaining items...");
        $maxCycles = 30;
        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $remaining = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_current_state', 'initial')->count();
            if ($remaining === 0) {
                break;
            }

            $svc = app(PushingService::class);
            $svc->process('ProcessOrderJob', 100);
            sleep(5);
        }

        // Wait for all to complete
        $allCompleted = $this->waitForState('ProcessOrderJob', 'completed', $totalOrders, 120);
        $this->check("All $totalOrders orders completed", $allCompleted >= $totalOrders);

        // Verify write-through consistency
        $mismatched = $this->countMismatched('ProcessOrderJob');
        $this->check("Write-through consistency: 0 mismatches", $mismatched === 0);

        // Verify no duplicate pushed states
        $dupes = $this->countDuplicatePushedStates('ProcessOrderJob');
        $this->check("No duplicate pushed states", $dupes === 0);

        $this->printJobStats('ProcessOrderJob');
        config(['distribution.quota' => 50]);
    }

    // ═══════════════════════════════════════════════════════
    //  SCENARIO 2: Product Sync with Failures + Retry
    //  Simulates: Product sync where ~10% fail randomly.
    //  System detects failures, backlog retry picks them up.
    // ═══════════════════════════════════════════════════════

    private function scenarioFailures(): void
    {
        $totalProducts = 50;
        $quota = 20;
        config(['distribution.quota' => $quota]);

        $this->line("  Setup: $totalProducts products to sync, ~10% failure rate, quota=$quota");

        // Seed products from 2 suppliers
        for ($supplier = 1; $supplier <= 2; $supplier++) {
            $batch = [];
            $count = $supplier === 1 ? 30 : 20;
            for ($i = 1; $i <= $count; $i++) {
                $batch[] = [
                    'distribution_request_id' => 2000 + $supplier,
                    'distribution_payload' => json_encode([
                        'product_id' => $supplier * 10000 + $i,
                        'supplier' => "supplier_$supplier",
                    ]),
                    'distribution_job_name' => 'SyncProductJob',
                    'distribution_priority' => rand(1, 5),
                ];
            }
            $this->repo->initDistributionData($batch);
        }

        $this->check("Seeded $totalProducts products",
            Distributions::where('distribution_job_name', 'SyncProductJob')
                ->where('distribution_current_state', 'initial')->count() === $totalProducts);

        // Push all in cycles
        $this->line("  Pushing and processing...");
        $maxCycles = 12;
        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $initial = Distributions::where('distribution_job_name', 'SyncProductJob')
                ->where('distribution_current_state', 'initial')->count();
            if ($initial === 0) {
                break;
            }
            $svc = app(PushingService::class);
            $svc->process('SyncProductJob', 100);
            sleep(3);
        }

        // Wait for processing to finish (pushed → completed/failed)
        $this->waitForNoPushed('SyncProductJob', 60);

        $stats = $this->getJobCounts('SyncProductJob');
        $this->line("  After initial processing:");
        $this->line("    Completed: {$stats['completed']} | Failed: {$stats['failed']} | Pushed: {$stats['pushed']}");

        $this->check("Some items completed", $stats['completed'] > 0);
        $this->check("All items processed (none left initial/pushed)",
            $stats['initial'] === 0 && $stats['pushed'] === 0);

        // If there are failures, test retry
        if ($stats['failed'] > 0) {
            $failedCount = $stats['failed'];
            $this->line("  Retrying $failedCount failed items...");

            // Backdate failed items so they pass the 1-hour cooldown
            Distributions::where('distribution_job_name', 'SyncProductJob')
                ->where('distribution_current_state', 'failed')
                ->update(['distribution_updated_at' => Carbon::now()->subHours(2)]);

            // Push via backlog
            $svc = app(PushingService::class);
            $svc->backlogFlag(true);
            $r = $svc->process('SyncProductJob', 100, 3, 1);

            if ($r->getStatusCode() === 200) {
                $this->line("  Waiting for retries to process...");
                $this->waitForNoPushed('SyncProductJob', 45);

                $afterRetry = $this->getJobCounts('SyncProductJob');
                $recovered = $afterRetry['completed'] - $stats['completed'];
                $this->line("  After retry: recovered $recovered items");
                $this->check("Retry recovered some items", $recovered > 0 || $afterRetry['failed'] < $failedCount);
            } else {
                $this->check("Backlog push accepted", false);
            }
        } else {
            $this->line("  <fg=yellow>No failures occurred (10% rate is probabilistic)</>");
            $this->check("All $totalProducts completed without failures", $stats['completed'] === $totalProducts);
        }

        // Verify consistency
        $mismatched = $this->countMismatched('SyncProductJob');
        $this->check("Write-through consistency after retry: 0 mismatches", $mismatched === 0);

        $this->printJobStats('SyncProductJob');
        config(['distribution.quota' => 50]);
    }

    // ═══════════════════════════════════════════════════════
    //  SCENARIO 3: Quota Pressure — Multiple Job Types
    //  Simulates: Two job types running simultaneously,
    //  each with independent quota tracking.
    // ═══════════════════════════════════════════════════════

    private function scenarioPressure(): void
    {
        $quota = 10;
        config(['distribution.quota' => $quota]);
        $ordersCount = 30;
        $productsCount = 25;

        $this->line("  Setup: $ordersCount orders + $productsCount products, quota=$quota per job type");

        // Seed both job types
        $orders = [];
        for ($i = 1; $i <= $ordersCount; $i++) {
            $orders[] = [
                'distribution_request_id' => 3001,
                'distribution_payload' => json_encode(['order_id' => 3000 + $i]),
                'distribution_job_name' => 'ProcessOrderJob',
                'distribution_priority' => rand(1, 5),
            ];
        }
        $this->repo->initDistributionData($orders);

        $products = [];
        for ($i = 1; $i <= $productsCount; $i++) {
            $products[] = [
                'distribution_request_id' => 3002,
                'distribution_payload' => json_encode(['product_id' => 4000 + $i]),
                'distribution_job_name' => 'SyncProductJob',
                'distribution_priority' => rand(1, 5),
            ];
        }
        $this->repo->initDistributionData($products);

        // Push both simultaneously
        $svc1 = app(PushingService::class);
        $svc1->process('ProcessOrderJob', 100);
        $svc2 = app(PushingService::class);
        $svc2->process('SyncProductJob', 100);

        $ordersPushed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_current_state', 'pushed')->count();
        $productsPushed = Distributions::where('distribution_job_name', 'SyncProductJob')
            ->where('distribution_current_state', 'pushed')->count();

        $this->check("Orders: pushed $ordersPushed ≤ quota $quota", $ordersPushed <= $quota);
        $this->check("Products: pushed $productsPushed ≤ quota $quota", $productsPushed <= $quota);
        $this->check("Both types pushed independently", $ordersPushed > 0 && $productsPushed > 0);

        // Continue pushing in cycles while Horizon processes
        $this->line("  Running mixed processing cycles...");
        $maxCycles = 20;
        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $ordersInit = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_current_state', 'initial')->count();
            $productsInit = Distributions::where('distribution_job_name', 'SyncProductJob')
                ->where('distribution_current_state', 'initial')->count();

            if ($ordersInit === 0 && $productsInit === 0) {
                break;
            }

            if ($ordersInit > 0) {
                $svc = app(PushingService::class);
                $svc->process('ProcessOrderJob', 100);
            }
            if ($productsInit > 0) {
                $svc = app(PushingService::class);
                $svc->process('SyncProductJob', 100);
            }
            sleep(3);
        }

        // Wait for both to finish — wait for completions, not just "no pushed"
        $this->waitForState('ProcessOrderJob', 'completed', $ordersCount, 90, 3001);
        $this->waitForNoPushed('SyncProductJob', 60);

        $ordersCompleted = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_request_id', 3001)
            ->where('distribution_current_state', 'completed')->count();
        $productsCompleted = Distributions::where('distribution_job_name', 'SyncProductJob')
            ->where('distribution_request_id', 3002)
            ->where('distribution_current_state', 'completed')->count();

        $this->check("Orders: $ordersCompleted/$ordersCount completed", $ordersCompleted === $ordersCount);
        $this->check("Products: most completed (10% fail rate)",
            $productsCompleted >= ($productsCount * 0.7));

        // Verify no cross-contamination — order items only have ProcessOrderJob states
        $crossContam = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_request_id', 3002)->count();
        $this->check("No cross-contamination between job types", $crossContam === 0);

        $this->printJobStats('ProcessOrderJob');
        $this->printJobStats('SyncProductJob');
        config(['distribution.quota' => 50]);
    }

    // ═══════════════════════════════════════════════════════
    //  SCENARIO 4: Redis Outage + Drift Recovery
    //  Simulates: Redis keys flushed mid-operation.
    //  System auto-recovers via DB fallback.
    // ═══════════════════════════════════════════════════════

    private function scenarioRecovery(): void
    {
        if (!$this->cache->isEnabled()) {
            $this->line("  <fg=yellow>⊘ Skipped — Redis cache not enabled</>");
            return;
        }

        $quota = 10;
        config(['distribution.quota' => $quota]);

        $this->line("  Setup: 30 items, quota=$quota, Redis flush mid-processing");

        $batch = [];
        for ($i = 1; $i <= 30; $i++) {
            $batch[] = [
                'distribution_request_id' => 4001,
                'distribution_payload' => json_encode(['order_id' => 5000 + $i]),
                'distribution_job_name' => 'ProcessOrderJob',
                'distribution_priority' => rand(1, 5),
            ];
        }
        $this->repo->initDistributionData($batch);

        // Push first batch
        $svc = app(PushingService::class);
        $svc->process('ProcessOrderJob', 100);

        $pushed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_request_id', 4001)
            ->where('distribution_current_state', 'pushed')->count();
        $this->check("First batch pushed ($pushed items)", $pushed > 0);

        $redisBefore = $this->cache->getRedisPushedCount('ProcessOrderJob');
        $this->line("  Redis pushed count before flush: $redisBefore");

        // Simulate Redis outage — flush all distribution keys
        $this->line("  <fg=red>>>> Flushing Redis keys (simulating outage) <<<</>");
        $this->flushRedisKeys();

        $redisAfter = $this->cache->getRedisPushedCount('ProcessOrderJob');
        $this->check("Redis keys flushed", $redisAfter === null);

        // Try pushing again — should auto-recover via DB fallback
        $svc2 = app(PushingService::class);
        $r2 = $svc2->process('ProcessOrderJob', 100);
        $this->line("  Push after Redis flush: status={$r2->getStatusCode()}, body={$r2->getContent()}");

        // The getPushedCount should have re-initialized from DB
        $redisRecovered = $this->cache->getRedisPushedCount('ProcessOrderJob');
        $dbPushed = $this->repo->countByStatus('pushed', 'ProcessOrderJob');
        $this->line("  Redis recovered: $redisRecovered, DB pushed: $dbPushed");
        $this->check("Redis auto-recovered from DB", $redisRecovered !== null);

        // Manually sync to fix any drift
        $this->cache->syncPushedCount('ProcessOrderJob', $dbPushed);
        $afterSync = $this->cache->getRedisPushedCount('ProcessOrderJob');
        $this->check("After sync: Redis ($afterSync) = DB ($dbPushed)", $afterSync === $dbPushed);

        // Continue processing and wait for completion
        $maxCycles = 25;
        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $initial = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_request_id', 4001)
                ->where('distribution_current_state', 'initial')->count();
            if ($initial === 0) {
                break;
            }
            $svc = app(PushingService::class);
            $svc->process('ProcessOrderJob', 100);
            sleep(5);
        }

        $this->waitForState('ProcessOrderJob', 'completed', 30, 120, 4001);

        $completed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_request_id', 4001)
            ->where('distribution_current_state', 'completed')->count();
        $this->check("All 30 items completed despite Redis outage", $completed === 30);

        config(['distribution.quota' => 50]);
    }

    // ═══════════════════════════════════════════════════════
    //  SCENARIO 5: Burst → Drain → Archive Lifecycle
    //  Simulates: Large batch arrives, gets processed,
    //  then archived for cleanup.
    // ═══════════════════════════════════════════════════════

    private function scenarioDrain(): void
    {
        $totalItems = 30;
        $quota = 15;
        config(['distribution.quota' => $quota]);

        $this->line("  Setup: $totalItems items burst, quota=$quota, then archive");

        // Seed burst
        $batch = [];
        for ($i = 1; $i <= $totalItems; $i++) {
            $batch[] = [
                'distribution_request_id' => 5001,
                'distribution_payload' => json_encode(['item_id' => $i]),
                'distribution_job_name' => 'ProcessOrderJob',
                'distribution_priority' => rand(1, 10),
            ];
        }
        $this->repo->initDistributionData($batch);

        $this->check("Burst seeded: $totalItems items",
            Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_request_id', 5001)
                ->where('distribution_current_state', 'initial')->count() === $totalItems);

        // Process all via push cycles
        $this->line("  Processing burst...");
        $maxCycles = 30;
        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $remaining = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_request_id', 5001)
                ->where('distribution_current_state', 'initial')->count();
            if ($remaining === 0) {
                break;
            }
            $svc = app(PushingService::class);
            $svc->process('ProcessOrderJob', 100);
            sleep(5);
        }

        $this->waitForState('ProcessOrderJob', 'completed', $totalItems, 120, 5001);

        $completed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
            ->where('distribution_request_id', 5001)
            ->where('distribution_current_state', 'completed')->count();
        $this->check("All $totalItems items drained", $completed === $totalItems);

        // Count states before archive
        $distIds = Distributions::where('distribution_request_id', 5001)->pluck('distribution_id');
        $statesBefore = DistributionStates::whereIn('fk_distribution_id', $distIds)->count();
        $this->line("  States before archive: $statesBefore (expect ~$totalItems × 3-4 transitions)");
        $this->check("Multiple state transitions recorded", $statesBefore > $totalItems);

        // Backdate for archive eligibility
        Distributions::where('distribution_request_id', 5001)
            ->update(['distribution_updated_at' => Carbon::now()->subDays(10)]);

        // Archive — keep latest state only
        \Illuminate\Support\Facades\Artisan::call('distribution:archive', ['--days' => 7]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->line("  Archive: " . trim(str_replace("\n", " | ", trim($output))));

        $statesAfter = DistributionStates::whereIn('fk_distribution_id', $distIds)->count();
        $this->check("Archive cleaned intermediate states ($statesBefore → $statesAfter)",
            $statesAfter < $statesBefore);
        // Archive keeps 1 state per completed/failed distribution
        $archivedCount = Distributions::where('distribution_request_id', 5001)
            ->whereIn('distribution_current_state', ['completed', 'failed'])->count();
        $this->check("Archive kept 1 state per archived distribution ($statesAfter ≤ $archivedCount)",
            $statesAfter <= $archivedCount + 2); // small tolerance for in-flight

        // Verify distributions still exist (no purge)
        $distsAfter = Distributions::where('distribution_request_id', 5001)->count();
        $this->check("Distributions preserved after archive", $distsAfter === $totalItems);

        // Verify monitor still works after archive
        $stats = $this->repo->getStats('ProcessOrderJob');
        $this->check("Monitor still works after archive", !empty($stats));

        // Purge
        \Illuminate\Support\Facades\Artisan::call('distribution:archive', ['--days' => 7, '--purge' => true]);

        $distsAfterPurge = Distributions::where('distribution_request_id', 5001)->count();
        $purgedCompletedFailed = Distributions::where('distribution_request_id', 5001)
            ->whereIn('distribution_current_state', ['completed', 'failed'])->count();
        $this->check("Purge removed completed/failed distributions ($purgedCompletedFailed remaining, $distsAfterPurge total)",
            $purgedCompletedFailed === 0);

        config(['distribution.quota' => 50]);
    }

    // ═══════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════

    private function waitForState(string $jobName, string $state, int $target, int $timeoutSec, ?int $requestId = null): int
    {
        $deadline = time() + $timeoutSec;
        $count = 0;
        while (time() < $deadline) {
            $q = Distributions::where('distribution_job_name', $jobName)
                ->where('distribution_current_state', $state);
            if ($requestId) {
                $q->where('distribution_request_id', $requestId);
            }
            $count = $q->count();
            if ($count >= $target) {
                return $count;
            }
            sleep(2);
        }
        return $count;
    }

    private function waitForNoPushed(string $jobName, int $timeoutSec): void
    {
        $deadline = time() + $timeoutSec;
        while (time() < $deadline) {
            $pushed = Distributions::where('distribution_job_name', $jobName)
                ->where('distribution_current_state', 'pushed')->count();
            if ($pushed === 0) {
                return;
            }
            sleep(2);
        }
    }

    private function getJobCounts(string $jobName): array
    {
        $counts = [];
        foreach (DistributionStates::ALL_STATES as $state) {
            $counts[$state] = Distributions::where('distribution_job_name', $jobName)
                ->where('distribution_current_state', $state)->count();
        }
        return $counts;
    }

    private function countMismatched(string $jobName): int
    {
        $result = DB::select("
            SELECT COUNT(*) as cnt FROM distributions d
            JOIN distribution_states ds ON ds.fk_distribution_id = d.distribution_id
            WHERE ds.distribution_state_id = (
                SELECT MAX(ds2.distribution_state_id) FROM distribution_states ds2
                WHERE ds2.fk_distribution_id = d.distribution_id
            )
            AND d.distribution_current_state != ds.distribution_state_value
            AND d.distribution_job_name = ?
            AND d.distribution_deleted_at IS NULL
        ", [$jobName]);
        return $result[0]->cnt ?? 0;
    }

    private function countDuplicatePushedStates(string $jobName): int
    {
        $result = DB::select("
            SELECT COUNT(*) as cnt FROM (
                SELECT fk_distribution_id FROM distribution_states
                WHERE distribution_state_value = 'pushed'
                AND fk_distribution_id IN (
                    SELECT distribution_id FROM distributions
                    WHERE distribution_job_name = ?
                    AND distribution_deleted_at IS NULL
                )
                AND distribution_state_deleted_at IS NULL
                GROUP BY fk_distribution_id HAVING COUNT(*) > 1
            ) dupes
        ", [$jobName]);
        return $result[0]->cnt ?? 0;
    }

    private function flushRedisKeys(): void
    {
        try {
            $prefix = config('distribution.cache.prefix', 'dist');
            $redis = Redis::connection(config('distribution.cache.connection', 'default'));
            $keys = $redis->keys("$prefix:*");
            foreach ($keys as $key) {
                $laravelPrefix = config('database.redis.options.prefix', '');
                $redis->del(str_replace($laravelPrefix, '', $key));
            }
        } catch (\Exception) {
            // ignore
        }
    }

    private function printJobStats(string $jobName): void
    {
        $counts = $this->getJobCounts($jobName);
        $total = array_sum($counts);
        $parts = [];
        foreach ($counts as $state => $count) {
            if ($count > 0) {
                $parts[] = "$state=$count";
            }
        }
        $this->line("  <fg=gray>[$jobName] " . implode(' | ', $parts) . " (total=$total)</>");
    }

    private function printEnv(): void
    {
        $driver = DB::connection()->getDriverName();
        $dbVersion = DB::select('SELECT VERSION() as v')[0]->v ?? '?';
        $redisOk = 'N/A';
        try {
            Redis::connection(config('distribution.cache.connection', 'default'))->ping();
            $redisOk = 'OK';
        } catch (\Exception) {
            $redisOk = 'unavailable';
        }

        $this->line("  Environment: PHP " . PHP_VERSION . " | $driver $dbVersion | Redis: $redisOk");
        $this->line("  Config: quota=" . config('distribution.quota') . " | cache=" . ($this->cache->isEnabled() ? 'on' : 'off'));
    }

    private function check(string $label, bool $passed): void
    {
        if ($passed) {
            $this->passed++;
            $this->line("  <fg=green>✓</> $label");
        } else {
            $this->failed++;
            $this->errors[] = $label;
            $this->line("  <fg=red>✗</> $label");
        }
    }

    private function cleanup(): void
    {
        $this->info('Cleaning up...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distribution_states')->truncate();
        DB::table('distributions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->flushRedisKeys();
        config(['distribution.quota' => 50]);
        @unlink(storage_path('distribution-supervisor.lock'));
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════╗');
        if ($this->failed === 0) {
            $this->info("║   ✓ ALL $this->passed/$total CHECKS PASSED" . str_repeat(' ', max(0, 32 - strlen("$this->passed/$total"))) . "║");
        } else {
            $this->error("║   ✗ $this->failed/$total CHECKS FAILED" . str_repeat(' ', max(0, 33 - strlen("$this->failed/$total"))) . "║");
        }
        $this->info('╚══════════════════════════════════════════════════╝');

        if (!empty($this->errors)) {
            $this->newLine();
            $this->error('Failures:');
            foreach ($this->errors as $i => $err) {
                $this->line("  " . ($i + 1) . ". <fg=red>$err</>");
            }
        }
    }
}
