<?php

namespace PLSys\DistrbutionQueue\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use PLSys\DistrbutionQueue\App\Services\PushingService;

class DistributionStressTest extends Command
{
    protected $signature = 'distribution:stress-test
        {--scenario=all : Run specific scenario (all|happy|bad|stress|consistency|quota|retry|concurrent)}
        {--cleanup : Clean up test data before running}';

    protected $description = 'Production-level stress test for distribution system';

    private DistributionRepository $repo;
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function handle()
    {
        $this->repo = new DistributionRepository();
        $scenario = $this->option('scenario');

        // Always cleanup before running to prevent stale data from previous runs
        $this->cleanup();

        $this->newLine();
        $this->info('========================================');
        $this->info('  Distribution Stress Test Suite');
        $this->info('========================================');
        $this->newLine();

        $scenarios = [
            'happy'       => 'Happy Path Tests',
            'bad'         => 'Bad Case Tests',
            'quota'       => 'Quota & Throttling Tests',
            'retry'       => 'Retry & Backlog Tests',
            'consistency' => 'Write-Through Consistency Tests',
            'concurrent'  => 'Concurrent / Double-Dispatch Tests',
            'stress'      => 'High Volume Stress Tests',
        ];

        foreach ($scenarios as $key => $label) {
            if ($scenario !== 'all' && $scenario !== $key) {
                continue;
            }
            $this->info("── $label ──");
            $method = 'scenario' . ucfirst($key);
            $this->$method();
            $this->newLine();
        }

        $this->printSummary();
        return $this->failed > 0 ? 1 : 0;
    }

    // ═══════════════════════════════════════════
    //  HAPPY PATH
    // ═══════════════════════════════════════════

    private function scenarioHappy(): void
    {
        // 1. Large batch init — single job type, single request
        $this->test('Init 500 items (single job, single request)', function () {
            $data = $this->buildBatch(500, 'SimpleTestJob', 100);
            $response = $this->repo->initDistributionData($data);
            $this->assertEq(200, $response->getStatusCode(), 'init response');
            $this->assertEq(500, Distributions::where('distribution_job_name', 'SimpleTestJob')
                ->where('distribution_current_state', 'initial')
                ->where('distribution_request_id', 100)
                ->count(), 'count initial');
        });

        // 2. Multiple job types, multiple requests
        $this->test('Init mixed: 3 job types × 5 requests × 100 items = 1500', function () {
            $jobs = ['ProcessOrderJob', 'SyncProductJob', 'FailingTestJob'];
            foreach ($jobs as $job) {
                for ($reqId = 200; $reqId < 205; $reqId++) {
                    $data = $this->buildBatch(100, $job, $reqId);
                    $response = $this->repo->initDistributionData($data);
                    $this->assertEq(200, $response->getStatusCode(), "init $job req=$reqId");
                }
            }
            // Verify each job type has exactly 500 items
            foreach ($jobs as $job) {
                $count = Distributions::where('distribution_current_state', 'initial')
                    ->where('distribution_job_name', $job)
                    ->whereIn('distribution_request_id', range(200, 204))
                    ->count();
                $this->assertEq(500, $count, "$job count");
            }
        });

        // 3. searchAndLock returns correct count with fair distribution
        $this->test('searchAndLock fair distributes across 5 requests (limit=50)', function () {
            $results = $this->repo->searchAndLock('ProcessOrderJob', null, 50);
            $this->assertEq(50, count($results), 'returned count');

            $grouped = collect($results)->groupBy('distribution_request_id');
            foreach ($grouped as $reqId => $items) {
                $this->assertEq(10, $items->count(), "fair dist req=$reqId");
            }
        });

        // 4. searchAndLock second call returns different items (no overlap)
        $this->test('Second searchAndLock returns 50 more non-overlapping items', function () {
            $firstIds = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_current_state', 'pushed')
                ->pluck('distribution_id')->toArray();

            $results = $this->repo->searchAndLock('ProcessOrderJob', null, 50);
            $this->assertEq(50, count($results), 'second batch count');

            $secondIds = collect($results)->pluck('distribution_id')->toArray();
            $overlap = array_intersect($firstIds, $secondIds);
            $this->assertEq(0, count($overlap), 'no overlap between batches');
        });

        // 5. getActiveJobNames returns all active types
        $this->test('getActiveJobNames returns all active job types', function () {
            $names = $this->repo->getActiveJobNames();
            $this->assertContains('SimpleTestJob', $names, 'contains SimpleTestJob');
            $this->assertContains('ProcessOrderJob', $names, 'contains ProcessOrderJob');
            $this->assertContains('SyncProductJob', $names, 'contains SyncProductJob');
            $this->assertContains('FailingTestJob', $names, 'contains FailingTestJob');
        });

        // 6. countByStatus accuracy
        $this->test('countByStatus returns accurate counts', function () {
            $pushed = $this->repo->countByStatus('pushed', 'ProcessOrderJob');
            $initial = $this->repo->countByStatus('initial', 'ProcessOrderJob');
            $this->assertEq(100, $pushed, 'pushed count');
            $this->assertEq(400, $initial, 'initial count'); // 500 - 100 pushed
        });
    }

    // ═══════════════════════════════════════════
    //  BAD CASES
    // ═══════════════════════════════════════════

    private function scenarioBad(): void
    {
        // 1. Init with empty array
        $this->test('Init empty array does not crash', function () {
            $response = $this->repo->initDistributionData([]);
            $this->assertEq(200, $response->getStatusCode(), 'empty init ok');
        });

        // 2. Init with invalid data rolls back
        $this->test('Init with bad data rolls back entire transaction', function () {
            $countBefore = Distributions::count();
            $data = [
                [
                    'distribution_request_id' => 999,
                    'distribution_payload' => '{}',
                    'distribution_job_name' => 'TestRollback',
                ],
                [
                    'distribution_request_id' => 999,
                    // Missing payload — should cause DB error
                ],
            ];
            $response = $this->repo->initDistributionData($data);
            $this->assertEq(400, $response->getStatusCode(), 'rollback response');
            $this->assertEq($countBefore, Distributions::count(), 'no rows leaked');
        });

        // 3. Search non-existent job returns empty
        $this->test('Search non-existent job returns empty', function () {
            $results = $this->repo->searchAndLock('NonExistentJob', null, 100);
            $this->assertEq(0, count($results), 'empty results');
        });

        // 4. Dispatch failure marks items as failed
        $this->test('Dispatch failure marks items as failed via post()', function () {
            $this->ensureMockJob('BadCaseJob');
            \Illuminate\Support\Facades\Queue::fake();

            $data = $this->buildBatch(3, 'BadCaseJob', 888);
            $this->repo->initDistributionData($data);

            $service = app(PushingService::class);
            $response = $service->process('BadCaseJob', 10);
            $this->assertEq(200, $response->getStatusCode(), 'pushed ok');

            // Simulate failure via post()
            $pushed = Distributions::where('distribution_job_name', 'BadCaseJob')
                ->where('distribution_request_id', 888)
                ->where('distribution_current_state', 'pushed')
                ->get();
            foreach ($pushed as $p) {
                $service->post($p->distribution_id, 'failed', 'Simulated failure');
            }

            $failedCount = Distributions::where('distribution_job_name', 'BadCaseJob')
                ->where('distribution_request_id', 888)
                ->where('distribution_current_state', 'failed')
                ->count();
            $this->assertEq(3, $failedCount, 'all 3 marked failed');
        });

        // 5. countByStatus on non-existent job
        $this->test('countByStatus for non-existent job returns 0', function () {
            $count = $this->repo->countByStatus('pushed', 'ZZZNonExistent');
            $this->assertEq(0, $count, 'zero count');
        });

        // 6. searchAndLock with globalLimit=0
        $this->test('searchAndLock with globalLimit=0 returns empty', function () {
            $results = $this->repo->searchAndLock('SimpleTestJob', null, 0);
            $this->assertEq(0, count($results), 'zero limit returns empty');
        });
    }

    // ═══════════════════════════════════════════
    //  QUOTA & THROTTLING
    // ═══════════════════════════════════════════

    private function scenarioQuota(): void
    {
        $originalQuota = config('distribution.quota');
        $this->ensureMockJob('QuotaTestJob');

        // 1. Quota blocks when full
        $this->test('Quota blocks pushes when reached', function () {
            config(['distribution.quota' => 5]);
            \Illuminate\Support\Facades\Queue::fake();

            $data = $this->buildBatch(20, 'QuotaTestJob', 300);
            $this->repo->initDistributionData($data);

            $svc = app(PushingService::class);
            $r1 = $svc->process('QuotaTestJob', 100);
            $this->assertEq(200, $r1->getStatusCode(), 'first batch pushed');
            $this->assertStringContains('5 request pushed', $r1->getContent(), 'exactly 5 pushed');

            $svc2 = app(PushingService::class);
            $r2 = $svc2->process('QuotaTestJob', 100);
            $this->assertEq(406, $r2->getStatusCode(), 'quota blocks second batch');
        });

        // 2. Quota frees up after completion
        $this->test('Quota frees up after items complete', function () {
            \Illuminate\Support\Facades\Queue::fake();

            // Complete 3 of the 5 pushed
            $pushed = Distributions::where('distribution_job_name', 'QuotaTestJob')
                ->where('distribution_current_state', 'pushed')
                ->take(3)->get();

            $svc = app(PushingService::class);
            foreach ($pushed as $p) {
                $svc->post($p->distribution_id, 'completed');
            }

            // Now 2 pushed, quota=5, should push 3 more
            $svc3 = app(PushingService::class);
            $r3 = $svc3->process('QuotaTestJob', 100);
            $this->assertEq(200, $r3->getStatusCode(), 'push after free');
            $this->assertStringContains('3 request pushed', $r3->getContent(), '3 more pushed');
        });

        // 3. Sync mode bypasses quota
        $this->test('Sync mode bypasses quota entirely', function () {
            $this->ensureMockJob('QuotaSyncJob');

            config(['distribution.quota' => 2]);

            $data = $this->buildBatch(10, 'QuotaSyncJob', 301);
            $this->repo->initDistributionData($data);

            // Fill quota first
            \Illuminate\Support\Facades\Queue::fake();
            $svc = app(PushingService::class);
            $r1 = $svc->process('QuotaSyncJob', 100);
            $this->assertEq(200, $r1->getStatusCode(), 'first push ok');

            // Normal mode should be blocked
            $svc2 = app(PushingService::class);
            $r2 = $svc2->process('QuotaSyncJob', 100);
            $this->assertEq(406, $r2->getStatusCode(), 'normal blocked by quota');

            // Sync mode should bypass
            $svc3 = app(PushingService::class);
            $svc3->optionSync(true);
            $r3 = $svc3->process('QuotaSyncJob', 100);
            $this->assertEq(200, $r3->getStatusCode(), 'sync bypasses quota');
        });

        config(['distribution.quota' => $originalQuota]);
    }

    // ═══════════════════════════════════════════
    //  RETRY & BACKLOG
    // ═══════════════════════════════════════════

    private function scenarioRetry(): void
    {
        // 1. Failed items appear in backlog after cooldown
        $this->test('Failed items found in backlog after cooldown', function () {
            $data = $this->buildBatch(10, 'RetryTestJob', 400);
            $this->repo->initDistributionData($data);

            // Mark all as failed 2 hours ago
            $dists = Distributions::where('distribution_job_name', 'RetryTestJob')
                ->where('distribution_request_id', 400)->get();
            $twoHoursAgo = Carbon::now()->subHours(2);
            foreach ($dists as $d) {
                $d->update([
                    'distribution_current_state' => 'failed',
                    'distribution_updated_at' => $twoHoursAgo,
                ]);
                DistributionStates::create([
                    'fk_distribution_id' => $d->distribution_id,
                    'distribution_state_value' => 'failed',
                    'distribution_state_created_at' => $twoHoursAgo,
                ]);
            }

            $results = $this->repo->searchBackLogAndLock('RetryTestJob', null, 3, 1, 100);
            $this->assertEq(10, count($results), 'all 10 found in backlog');
        });

        // 2. Recent failures excluded from backlog
        $this->test('Recent failures (within cooldown) excluded from backlog', function () {
            $data = $this->buildBatch(5, 'RecentFailJob', 401);
            $this->repo->initDistributionData($data);

            $dists = Distributions::where('distribution_job_name', 'RecentFailJob')
                ->where('distribution_request_id', 401)->get();
            $tenMinAgo = Carbon::now()->subMinutes(10);
            foreach ($dists as $d) {
                $d->update([
                    'distribution_current_state' => 'failed',
                    'distribution_updated_at' => $tenMinAgo,
                ]);
                DistributionStates::create([
                    'fk_distribution_id' => $d->distribution_id,
                    'distribution_state_value' => 'failed',
                    'distribution_state_created_at' => $tenMinAgo,
                ]);
            }

            $results = $this->repo->searchBackLog('RecentFailJob', null, 3, 1);
            $this->assertEq(0, count($results), 'recent failures excluded');
        });

        // 3. Tries exhausted — no more retries
        $this->test('Items with tries >= max are excluded from backlog', function () {
            $data = $this->buildBatch(5, 'ExhaustedJob', 402);
            $this->repo->initDistributionData($data);

            $dists = Distributions::where('distribution_job_name', 'ExhaustedJob')
                ->where('distribution_request_id', 402)->get();
            $twoHoursAgo = Carbon::now()->subHours(2);
            foreach ($dists as $d) {
                $d->update([
                    'distribution_current_state' => 'failed',
                    'distribution_tries' => 3,
                    'distribution_updated_at' => $twoHoursAgo,
                ]);
                DistributionStates::create([
                    'fk_distribution_id' => $d->distribution_id,
                    'distribution_state_value' => 'failed',
                    'distribution_state_created_at' => $twoHoursAgo,
                ]);
            }

            $results = $this->repo->searchBackLog('ExhaustedJob', null, 3, 1);
            $this->assertEq(0, count($results), 'exhausted tries excluded');
        });

        // 4. Batch tries increment
        $this->test('Backlog process increments tries in batch', function () {
            $data = $this->buildBatch(5, 'BatchTriesJob', 403);
            $this->repo->initDistributionData($data);

            $dists = Distributions::where('distribution_job_name', 'BatchTriesJob')
                ->where('distribution_request_id', 403)->get();
            $twoHoursAgo = Carbon::now()->subHours(2);
            foreach ($dists as $d) {
                $d->update([
                    'distribution_current_state' => 'failed',
                    'distribution_tries' => 0,
                    'distribution_updated_at' => $twoHoursAgo,
                ]);
                DistributionStates::create([
                    'fk_distribution_id' => $d->distribution_id,
                    'distribution_state_value' => 'failed',
                    'distribution_state_created_at' => $twoHoursAgo,
                ]);
            }

            // Create a simple mock for this job
            if (!class_exists('\\App\\Jobs\\BatchTriesJob')) {
                eval("namespace App\\Jobs; class BatchTriesJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }");
            }

            \Illuminate\Support\Facades\Queue::fake();
            $svc = app(PushingService::class);
            $svc->backlogFlag(true);
            $r = $svc->process('BatchTriesJob', 100, 3, 1);
            $this->assertEq(200, $r->getStatusCode(), 'backlog pushed');

            // All 5 should have tries=1
            $allTries = Distributions::where('distribution_job_name', 'BatchTriesJob')
                ->where('distribution_request_id', 403)
                ->pluck('distribution_tries')->toArray();
            foreach ($allTries as $t) {
                $this->assertEq(1, $t, 'tries incremented');
            }
        });
    }

    // ═══════════════════════════════════════════
    //  WRITE-THROUGH CONSISTENCY
    // ═══════════════════════════════════════════

    private function scenarioConsistency(): void
    {
        // 1. After init: current_state matches states table
        $this->test('After init: distribution_current_state = initial', function () {
            $data = $this->buildBatch(50, 'ConsistencyJob', 500);
            $this->repo->initDistributionData($data);

            $mismatched = Distributions::where('distribution_job_name', 'ConsistencyJob')
                ->where('distribution_request_id', 500)
                ->where('distribution_current_state', '!=', 'initial')
                ->count();
            $this->assertEq(0, $mismatched, 'all initial');
        });

        // 2. After searchAndLock: current_state = pushed
        $this->test('After searchAndLock: distribution_current_state = pushed', function () {
            $results = $this->repo->searchAndLock('ConsistencyJob', null, 20);
            $this->assertEq(20, count($results), 'locked 20');

            $ids = collect($results)->pluck('distribution_id')->toArray();
            $mismatched = Distributions::whereIn('distribution_id', $ids)
                ->where('distribution_current_state', '!=', 'pushed')
                ->count();
            $this->assertEq(0, $mismatched, 'all pushed in current_state');

            // Verify states table also has pushed
            foreach ($ids as $id) {
                $latestState = DistributionStates::where('fk_distribution_id', $id)
                    ->orderByDesc('distribution_state_id')
                    ->first();
                $this->assertEq('pushed', $latestState->distribution_state_value, "state table pushed id=$id");
            }
        });

        // 3. After post(completed): current_state = completed
        $this->test('After post(completed): current_state matches', function () {
            $pushed = Distributions::where('distribution_job_name', 'ConsistencyJob')
                ->where('distribution_current_state', 'pushed')
                ->take(10)->get();

            $svc = app(PushingService::class);
            foreach ($pushed as $p) {
                $svc->post($p->distribution_id, 'processing');
                $svc->post($p->distribution_id, 'completed');
            }

            $ids = $pushed->pluck('distribution_id')->toArray();
            $mismatched = Distributions::whereIn('distribution_id', $ids)
                ->where('distribution_current_state', '!=', 'completed')
                ->count();
            $this->assertEq(0, $mismatched, 'all completed');
        });

        // 4. After post(failed): current_state = failed
        $this->test('After post(failed): current_state matches', function () {
            $pushed = Distributions::where('distribution_job_name', 'ConsistencyJob')
                ->where('distribution_current_state', 'pushed')
                ->take(5)->get();

            $svc = app(PushingService::class);
            foreach ($pushed as $p) {
                $svc->post($p->distribution_id, 'failed', 'test error');
            }

            $ids = $pushed->pluck('distribution_id')->toArray();
            $allFailed = Distributions::whereIn('distribution_id', $ids)
                ->where('distribution_current_state', 'failed')
                ->count();
            $this->assertEq(5, $allFailed, 'all failed');
        });

        // 5. Full consistency audit — current_state matches latest state in states table
        $this->test('Full audit: distribution_current_state matches latest state for ALL rows', function () {
            $mismatched = DB::select("
                SELECT d.distribution_id, d.distribution_current_state, ds.distribution_state_value as latest_state
                FROM distributions d
                JOIN distribution_states ds ON ds.fk_distribution_id = d.distribution_id
                WHERE ds.distribution_state_id = (
                    SELECT MAX(ds2.distribution_state_id)
                    FROM distribution_states ds2
                    WHERE ds2.fk_distribution_id = d.distribution_id
                )
                AND d.distribution_current_state != ds.distribution_state_value
                AND d.distribution_deleted_at IS NULL
            ");
            $this->assertEq(0, count($mismatched), 'zero mismatches across all rows');
            if (count($mismatched) > 0) {
                foreach (array_slice($mismatched, 0, 5) as $row) {
                    $this->warn("  Mismatch: id={$row->distribution_id} current_state={$row->distribution_current_state} latest={$row->latest_state}");
                }
            }
        });
    }

    // ═══════════════════════════════════════════
    //  CONCURRENT / DOUBLE-DISPATCH
    // ═══════════════════════════════════════════

    private function scenarioConcurrent(): void
    {
        // 1. Two searchAndLock calls never return same items
        $this->test('Concurrent searchAndLock returns disjoint sets', function () {
            $data = $this->buildBatch(100, 'ConcurrentJob', 600);
            $this->repo->initDistributionData($data);

            $batch1 = $this->repo->searchAndLock('ConcurrentJob', null, 30);
            $batch2 = $this->repo->searchAndLock('ConcurrentJob', null, 30);

            $ids1 = collect($batch1)->pluck('distribution_id')->toArray();
            $ids2 = collect($batch2)->pluck('distribution_id')->toArray();

            $overlap = array_intersect($ids1, $ids2);
            $this->assertEq(0, count($overlap), 'no overlap');
            $this->assertEq(30, count($batch1), 'batch1 count');
            $this->assertEq(30, count($batch2), 'batch2 count');
        });

        // 2. After all items locked, searchAndLock returns empty
        $this->test('searchAndLock returns empty when all items are locked', function () {
            // Lock remaining 40
            $batch3 = $this->repo->searchAndLock('ConcurrentJob', null, 100);
            $this->assertEq(40, count($batch3), 'remaining 40');

            $batch4 = $this->repo->searchAndLock('ConcurrentJob', null, 100);
            $this->assertEq(0, count($batch4), 'nothing left');
        });

        // 3. No duplicate pushed states
        $this->test('No duplicate pushed states per distribution', function () {
            $duplicates = DB::select("
                SELECT fk_distribution_id, COUNT(*) as cnt
                FROM distribution_states
                WHERE distribution_state_value = 'pushed'
                AND fk_distribution_id IN (
                    SELECT distribution_id FROM distributions
                    WHERE distribution_job_name = 'ConcurrentJob'
                    AND distribution_deleted_at IS NULL
                )
                AND distribution_state_deleted_at IS NULL
                GROUP BY fk_distribution_id
                HAVING cnt > 1
            ");
            $this->assertEq(0, count($duplicates), 'no duplicate pushed states');
        });
    }

    // ═══════════════════════════════════════════
    //  HIGH VOLUME STRESS
    // ═══════════════════════════════════════════

    private function scenarioStress(): void
    {
        // 1. Insert 5000 items across 10 job types
        $this->test('Init 5000 items across 10 job types × 10 requests', function () {
            $jobTypes = [];
            for ($j = 1; $j <= 10; $j++) {
                $jobTypes[] = "StressJob{$j}";
            }

            $totalInserted = 0;
            foreach ($jobTypes as $job) {
                for ($req = 700; $req < 710; $req++) {
                    $data = $this->buildBatch(50, $job, $req);
                    $response = $this->repo->initDistributionData($data);
                    if ($response->getStatusCode() === 200) {
                        $totalInserted += 50;
                    }
                }
            }
            $this->assertEq(5000, $totalInserted, 'inserted 5000');
        });

        // 2. getActiveJobNames performance — should use index
        $this->test('getActiveJobNames with 10+ job types < 50ms', function () {
            $start = microtime(true);
            $names = $this->repo->getActiveJobNames();
            $elapsed = (microtime(true) - $start) * 1000;

            $this->assertTrue(count($names) >= 10, "found " . count($names) . " job types");
            $this->assertTrue($elapsed < 50, "elapsed={$elapsed}ms (should < 50ms)");
        });

        // 3. countByStatus performance
        $this->test('countByStatus with 5000+ rows < 50ms', function () {
            $start = microtime(true);
            $count = $this->repo->countByStatus('initial');
            $elapsed = (microtime(true) - $start) * 1000;

            $this->assertTrue($count > 0, "count=$count");
            $this->assertTrue($elapsed < 50, "elapsed={$elapsed}ms (should < 50ms)");
        });

        // 4. searchAndLock 500 items — fair distribution across 10 requests
        $this->test('searchAndLock 500 items fair across 10 requests', function () {
            $start = microtime(true);
            $results = $this->repo->searchAndLock('StressJob1', null, 500);
            $elapsed = (microtime(true) - $start) * 1000;

            $this->assertEq(500, count($results), 'got 500');
            $this->assertTrue($elapsed < 500, "elapsed={$elapsed}ms (should < 500ms)");

            $grouped = collect($results)->groupBy('distribution_request_id');
            $this->assertEq(10, $grouped->count(), '10 request groups');
            foreach ($grouped as $g) {
                $this->assertEq(50, $g->count(), 'fair 50 per request');
            }
        });

        // 5. getStats performance with many job types
        $this->test('getStats with 10+ job types < 100ms', function () {
            $start = microtime(true);
            $stats = $this->repo->getStats();
            $elapsed = (microtime(true) - $start) * 1000;

            $this->assertTrue(count($stats) >= 10, "stats count=" . count($stats));
            $this->assertTrue($elapsed < 100, "elapsed={$elapsed}ms (should < 100ms)");
        });

        // 6. Bulk state transitions — simulate 500 completions
        $this->test('Bulk 500 completions — current_state stays consistent', function () {
            $pushed = Distributions::where('distribution_job_name', 'StressJob1')
                ->where('distribution_current_state', 'pushed')
                ->take(500)->pluck('distribution_id')->toArray();

            $svc = app(PushingService::class);
            $start = microtime(true);
            foreach ($pushed as $id) {
                $svc->post($id, 'completed');
            }
            $elapsed = (microtime(true) - $start) * 1000;

            $completed = Distributions::where('distribution_job_name', 'StressJob1')
                ->where('distribution_current_state', 'completed')
                ->count();
            $this->assertEq(500, $completed, 'all completed');
            $this->line("    (500 completions in {$elapsed}ms)");
        });
    }

    // ═══════════════════════════════════════════
    //  REDIS CACHE TESTS
    // ═══════════════════════════════════════════

    // (included in scenarioHappy via getActiveJobNames which uses cache)

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function ensureMockJob(string $name): void
    {
        if (!class_exists("\\App\\Jobs\\$name")) {
            eval("namespace App\\Jobs; class $name implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }");
        }
    }

    private function buildBatch(int $count, string $jobName, int $requestId): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'distribution_request_id' => $requestId,
                'distribution_payload' => json_encode([
                    'item' => $i,
                    'job' => $jobName,
                    'request' => $requestId,
                    'order_id' => $requestId * 10000 + $i,
                    'product_id' => $requestId * 10000 + $i,
                    'shop' => "shop_{$requestId}",
                ]),
                'distribution_job_name' => $jobName,
            ];
        }
        return $batch;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            $this->line("  <fg=green>✓</> $name");
        } catch (\Throwable $e) {
            $this->failed++;
            $msg = $e->getMessage();
            $this->errors[] = "$name: $msg";
            $this->line("  <fg=red>✗</> $name");
            $this->line("    <fg=red>→ $msg</>");
        }
    }

    private function assertEq($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("$label: expected=$expected actual=$actual");
        }
    }

    private function assertTrue(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException("$label: assertion failed");
        }
    }

    private function assertContains($needle, array $haystack, string $label): void
    {
        if (!in_array($needle, $haystack)) {
            throw new \RuntimeException("$label: '$needle' not found in [" . implode(',', $haystack) . "]");
        }
    }

    private function assertStringContains(string $needle, string $haystack, string $label): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new \RuntimeException("$label: '$needle' not found in '$haystack'");
        }
    }

    private function cleanup(): void
    {
        $this->info('Cleaning up test data...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distribution_states')->truncate();
        DB::table('distributions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Clear all Redis cache keys with the distribution prefix
        try {
            $prefix = config('distribution.cache.prefix', 'dist');
            $connection = config('distribution.cache.connection', 'default');
            $redis = \Illuminate\Support\Facades\Redis::connection($connection);
            // keys() returns with Laravel's redis prefix, so use pattern matching
            $keys = $redis->keys("$prefix:*");
            foreach ($keys as $key) {
                // Strip Laravel prefix for del() since the driver auto-prepends it
                $laravelPrefix = config('database.redis.options.prefix', '');
                $cleanKey = str_replace($laravelPrefix, '', $key);
                $redis->del($cleanKey);
            }
        } catch (\Exception $e) {
            // ignore
        }

        // Reset config to defaults
        config(['distribution.quota' => 50]);

        $this->info('Cleanup done.');
        $this->newLine();
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $this->info('========================================');
        if ($this->failed === 0) {
            $this->info("  ALL $total TESTS PASSED");
        } else {
            $this->error("  $this->failed / $total TESTS FAILED");
            $this->newLine();
            foreach ($this->errors as $err) {
                $this->line("  <fg=red>•</> $err");
            }
        }
        $this->info('========================================');
    }
}
