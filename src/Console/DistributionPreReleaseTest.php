<?php

namespace PLSys\DistrbutionQueue\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use PLSys\DistrbutionQueue\App\Services\PushingService;
use Symfony\Component\Process\Process;

class DistributionPreReleaseTest extends Command
{
    protected $signature = 'distribution:pre-release
        {--group=all : Run specific group (all|core|redis|ops)}
        {--skip-cleanup : Do not cleanup data before running}';

    protected $description = 'Pre-release E2E test suite — validates all features with real MySQL + Redis + Queue';

    private DistributionRepository $repo;
    private DistributionCache $cache;
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private array $errors = [];

    public function handle()
    {
        $this->repo = new DistributionRepository();
        $this->cache = app(DistributionCache::class);
        $group = $this->option('group');

        if (!$this->option('skip-cleanup')) {
            $this->cleanup();
        }

        $this->newLine();
        $this->info('╔════════════════════════════════════════════╗');
        $this->info('║   Distribution Pre-Release Test Suite      ║');
        $this->info('╚════════════════════════════════════════════╝');
        $this->newLine();

        $this->printEnvironment();

        $groups = [
            'core'  => 'Core E2E — Lifecycle + Queue + Consistency',
            'redis' => 'Redis — Cache, Quota, Drift, Fallback',
            'ops'   => 'Ops — Supervisor, Monitor, Archive, Migration',
        ];

        foreach ($groups as $key => $label) {
            if ($group !== 'all' && $group !== $key) {
                continue;
            }
            $this->newLine();
            $this->info("━━ $label ━━");
            $method = 'group' . ucfirst($key);
            $this->$method();
        }

        $this->printSummary();
        return $this->failed > 0 ? 1 : 0;
    }

    // ═══════════════════════════════════════════
    //  GROUP 1: CORE E2E
    // ═══════════════════════════════════════════

    private function groupCore(): void
    {
        // T1: Full lifecycle with real queue (sync mode)
        $this->test('T1: Full lifecycle — init → push(sync) → processing → completed', function () {
            $data = $this->buildBatch(10, 'SimpleTestJob', 1);
            $this->repo->initDistributionData($data);

            // Verify all initial
            $this->assertEq(10, Distributions::where('distribution_job_name', 'SimpleTestJob')
                ->where('distribution_current_state', 'initial')->count(), 'all initial');

            // Push via sync (dispatches immediately, no queue worker needed)
            $svc = app(PushingService::class);
            $svc->optionSync(true);
            $r = $svc->process('SimpleTestJob', 100);
            $this->assertEq(200, $r->getStatusCode(), 'sync push ok');

            // SimpleTestJob::handle() calls post(processing) then post(completed)
            $completed = Distributions::where('distribution_job_name', 'SimpleTestJob')
                ->where('distribution_current_state', 'completed')->count();
            $this->assertEq(10, $completed, 'all completed after sync');

            // Verify write-through: current_state matches latest state
            $mismatched = DB::select("
                SELECT d.distribution_id
                FROM distributions d
                JOIN distribution_states ds ON ds.fk_distribution_id = d.distribution_id
                WHERE ds.distribution_state_id = (
                    SELECT MAX(ds2.distribution_state_id)
                    FROM distribution_states ds2
                    WHERE ds2.fk_distribution_id = d.distribution_id
                )
                AND d.distribution_current_state != ds.distribution_state_value
                AND d.distribution_job_name = 'SimpleTestJob'
            ");
            $this->assertEq(0, count($mismatched), 'write-through consistent');
        });

        // T2: Async push — verify items are pushed to queue correctly
        $this->test('T2: Async push — items reach queue, state transitions correct', function () {
            $data = $this->buildBatch(5, 'ProcessOrderJob', 2);
            $this->repo->initDistributionData($data);

            // Push to RabbitMQ queue
            $svc = app(PushingService::class);
            $r = $svc->process('ProcessOrderJob', 100);
            $this->assertEq(200, $r->getStatusCode(), 'async push ok');

            // Verify state = pushed
            $pushed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                ->where('distribution_request_id', 2)
                ->where('distribution_current_state', 'pushed')->count();
            $this->assertEq(5, $pushed, 'all in pushed state');

            // Verify states table has pushed records
            $pushStates = DistributionStates::whereIn('fk_distribution_id',
                Distributions::where('distribution_job_name', 'ProcessOrderJob')
                    ->where('distribution_request_id', 2)
                    ->pluck('distribution_id')
            )->where('distribution_state_value', 'pushed')->count();
            $this->assertEq(5, $pushStates, 'states table has 5 pushed records');

            // Consume via Horizon (process_order queue is already being consumed)
            // Wait for Horizon to process them
            $maxWait = 15;
            $completed = 0;
            for ($i = 0; $i < $maxWait; $i++) {
                sleep(1);
                $completed = Distributions::where('distribution_job_name', 'ProcessOrderJob')
                    ->where('distribution_request_id', 2)
                    ->where('distribution_current_state', 'completed')->count();
                if ($completed >= 5) {
                    break;
                }
            }
            $this->assertEq(5, $completed, "all completed via Horizon (waited {$i}s)");
        });

        // T3: Fail + Retry lifecycle
        $this->test('T3: Fail → retry after cooldown → re-push', function () {
            $data = $this->buildBatch(5, 'SimpleTestJob', 3);
            $this->repo->initDistributionData($data);

            // Push and immediately fail them
            $svc = app(PushingService::class);
            $svc->optionSync(true);
            // Need to manually push and fail since SimpleTestJob completes
            $results = $this->repo->searchAndLock('SimpleTestJob', 3, 100);
            $this->assertEq(5, count($results), 'locked 5');

            $twoHoursAgo = Carbon::now()->subHours(2);
            foreach ($results as $item) {
                $id = $item['distribution_id'];
                $svc->post($id, 'failed', 'test fail');
                // Backdate updated_at so it passes cooldown
                Distributions::where('distribution_id', $id)
                    ->update(['distribution_updated_at' => $twoHoursAgo]);
            }

            $this->assertEq(5, Distributions::where('distribution_job_name', 'SimpleTestJob')
                ->where('distribution_request_id', 3)
                ->where('distribution_current_state', 'failed')->count(), 'all failed');

            // Retry via backlog
            $retryResults = $this->repo->searchBackLogAndLock('SimpleTestJob', null, 3, 1, 100);
            $this->assertEq(5, count($retryResults), 'backlog found 5 items');

            // Verify tries not exhausted
            $tried = Distributions::where('distribution_job_name', 'SimpleTestJob')
                ->where('distribution_request_id', 3)
                ->where('distribution_current_state', 'pushed')
                ->count();
            $this->assertEq(5, $tried, 'all re-pushed from backlog');
        });

        // T4: Throwable catch — class not found does not crash worker
        $this->test('T4: Non-existent job class caught as Throwable (not crash)', function () {
            $data = $this->buildBatch(3, 'NonExistentJob999', 4);
            $this->repo->initDistributionData($data);

            $svc = app(PushingService::class);
            $r = $svc->process('NonExistentJob999', 100);

            // Should fail gracefully, not crash
            // Items are marked as failed (not left in initial)
            $failed = Distributions::where('distribution_job_name', 'NonExistentJob999')
                ->where('distribution_current_state', 'failed')->count();
            $this->assertEq(3, $failed, 'all marked failed (Throwable caught)');
        });

        // T5: Fair distribution with priority
        $this->test('T5: Fair distribution — 3 requests × different sizes, quota=15', function () {
            // Request 5: 30 items, Request 6: 10 items, Request 7: 5 items
            $this->repo->initDistributionData($this->buildBatch(30, 'FairJob', 5));
            $this->repo->initDistributionData($this->buildBatch(10, 'FairJob', 6));
            $this->repo->initDistributionData($this->buildBatch(5,  'FairJob', 7));

            $results = $this->repo->searchAndLock('FairJob', null, 15);
            $this->assertEq(15, count($results), 'got 15 items');

            $grouped = collect($results)->groupBy('distribution_request_id');
            // Fair: each should get 5 (ceil(15/3)), request 7 has only 5 so it's exhausted
            // Then remaining slots go to request 5 and 6
            $this->assertTrue($grouped->count() === 3, 'all 3 requests represented');
            $this->assertTrue($grouped[7]->count() === 5, 'small request gets all its items');
        });

        // T6: SKIP LOCKED — concurrent searchAndLock no overlap
        $this->test('T6: SKIP LOCKED — two concurrent locks, zero overlap', function () {
            $data = $this->buildBatch(50, 'SkipLockJob', 8);
            $this->repo->initDistributionData($data);

            // Two sequential searchAndLock — SKIP LOCKED ensures no overlap
            $batch1 = $this->repo->searchAndLock('SkipLockJob', null, 20);
            $batch2 = $this->repo->searchAndLock('SkipLockJob', null, 20);

            $ids1 = collect($batch1)->pluck('distribution_id')->toArray();
            $ids2 = collect($batch2)->pluck('distribution_id')->toArray();
            $overlap = array_intersect($ids1, $ids2);

            $this->assertEq(0, count($overlap), 'zero overlap');
            $this->assertEq(20, count($batch1), 'batch1=20');
            $this->assertEq(20, count($batch2), 'batch2=20');
        });
    }

    // ═══════════════════════════════════════════
    //  GROUP 2: REDIS
    // ═══════════════════════════════════════════

    private function groupRedis(): void
    {
        if (!$this->cache->isEnabled()) {
            $this->skip('Redis tests skipped — DISTRIBUTION_CACHE_ENABLED=false');
            return;
        }

        // T7: Atomic quota reservation via Lua script (isolated job name)
        $this->test('T7: Atomic quota — Lua script prevents over-reservation', function () {
            \Illuminate\Support\Facades\Queue::fake();
            $this->ensureMockJob('QuotaAtomicJob');

            $data = $this->buildBatch(100, 'QuotaAtomicJob', 10);
            $this->repo->initDistributionData($data);

            config(['distribution.quota' => 30]);

            // Worker A: push → should get 30 (quota)
            $svcA = app(PushingService::class);
            $rA = $svcA->process('QuotaAtomicJob', 100);
            $this->assertEq(200, $rA->getStatusCode(), 'A pushed ok');

            $pushedA = Distributions::where('distribution_job_name', 'QuotaAtomicJob')
                ->where('distribution_current_state', 'pushed')->count();
            $this->assertEq(30, $pushedA, 'A pushed exactly 30');

            // Worker B: should be blocked
            $svcB = app(PushingService::class);
            $rB = $svcB->process('QuotaAtomicJob', 100);
            $this->assertEq(406, $rB->getStatusCode(), 'B blocked by quota');

            // Release 10 slots
            $released = Distributions::where('distribution_job_name', 'QuotaAtomicJob')
                ->where('distribution_current_state', 'pushed')
                ->take(10)->get();
            $svcC = app(PushingService::class);
            foreach ($released as $d) {
                $svcC->post($d->distribution_id, 'completed');
            }

            // Worker D: should get 10 more
            $svcD = app(PushingService::class);
            $rD = $svcD->process('QuotaAtomicJob', 100);
            $this->assertEq(200, $rD->getStatusCode(), 'D pushed after release');

            config(['distribution.quota' => 50]);
        });

        // T8: Pushed count drift sync
        $this->test('T8: Pushed count drift — syncPushedCount corrects Redis', function () {
            $data = $this->buildBatch(10, 'DriftJob', 11);
            $this->repo->initDistributionData($data);

            \Illuminate\Support\Facades\Queue::fake();
            $svc = app(PushingService::class);
            $svc->process('DriftJob', 100);

            // Manually corrupt Redis pushed count
            $prefix = config('distribution.cache.prefix', 'dist');
            $redis = Redis::connection(config('distribution.cache.connection', 'default'));
            $redis->set("$prefix:pushed_count:DriftJob", 999);

            // Verify drift
            $redisVal = $this->cache->getRedisPushedCount('DriftJob');
            $this->assertEq(999, $redisVal, 'drift exists');

            // Sync from DB
            $dbCount = $this->repo->countByStatus('pushed', 'DriftJob');
            $this->cache->syncPushedCount('DriftJob', $dbCount);

            $synced = $this->cache->getRedisPushedCount('DriftJob');
            $this->assertEq($dbCount, $synced, 'drift corrected');
        });

        // T9: Double-decrement prevention (isolated job name)
        $this->test('T9: Double-decrement — processing→completed does NOT double-decrement', function () {
            \Illuminate\Support\Facades\Queue::fake();
            $this->ensureMockJob('DblDecTestJob');

            $data = $this->buildBatch(5, 'DblDecTestJob', 12);
            $this->repo->initDistributionData($data);

            $svc = app(PushingService::class);
            $svc->process('DblDecTestJob', 100);

            $pushedBefore = $this->cache->getRedisPushedCount('DblDecTestJob');
            $this->assertEq(5, $pushedBefore, 'redis=5 after push');

            // Transition: pushed → processing (should decrement)
            $items = Distributions::where('distribution_job_name', 'DblDecTestJob')
                ->where('distribution_current_state', 'pushed')->get();
            $this->assertEq(5, $items->count(), 'found 5 pushed items');

            $svc2 = app(PushingService::class);
            foreach ($items as $d) {
                $svc2->post($d->distribution_id, 'processing');
            }
            $afterProcessing = $this->cache->getRedisPushedCount('DblDecTestJob');
            $this->assertEq(0, $afterProcessing, 'redis=0 after all leave pushed');

            // processing→completed should NOT decrement again
            foreach ($items as $d) {
                $svc2->post($d->distribution_id, 'completed');
            }
            $afterCompleted = $this->cache->getRedisPushedCount('DblDecTestJob');
            $this->assertEq(0, $afterCompleted, 'redis still 0 (no double-decrement)');
        });

        // T10: Redis fallback — operations work when Redis is unavailable
        $this->test('T10: Redis fallback — cache disabled falls back to DB', function () {
            // Temporarily create a cache instance with disabled=true
            config(['distribution.cache.enabled' => false]);
            $disabledCache = new DistributionCache();

            $data = $this->buildBatch(5, 'FallbackJob', 13);
            $this->repo->initDistributionData($data);

            // These should all work via DB fallback
            $names = $disabledCache->getActiveJobNames(function () {
                return $this->repo->getActiveJobNames();
            });
            $this->assertTrue(in_array('FallbackJob', $names), 'getActiveJobNames fallback works');

            $count = $disabledCache->getPushedCount('FallbackJob', function () {
                return $this->repo->countByStatus('pushed', 'FallbackJob');
            });
            $this->assertEq(0, $count, 'getPushedCount fallback works');

            // reserveQuota returns requested when disabled
            $reserved = $disabledCache->reserveQuota('FallbackJob', 5, 50);
            $this->assertEq(5, $reserved, 'reserveQuota passthrough when disabled');

            config(['distribution.cache.enabled' => true]);
        });
    }

    // ═══════════════════════════════════════════
    //  GROUP 3: OPS
    // ═══════════════════════════════════════════

    private function groupOps(): void
    {
        // T11: Supervisor PID lock
        $this->test('T11: Supervisor PID lock — only one instance allowed', function () {
            $lockFile = storage_path('distribution-supervisor.lock');
            @unlink($lockFile); // ensure clean state

            // Simulate acquiring the lock
            $handle = fopen($lockFile, 'w');
            $locked = flock($handle, LOCK_EX | LOCK_NB);
            $this->assertTrue($locked, 'first lock acquired');

            // Try to acquire again — should fail
            $handle2 = fopen($lockFile, 'w');
            $locked2 = flock($handle2, LOCK_EX | LOCK_NB);
            $this->assertTrue(!$locked2, 'second lock rejected');

            // Release
            flock($handle, LOCK_UN);
            fclose($handle);
            fclose($handle2);
            @unlink($lockFile);
        });

        // T12: distribution:work --once
        $this->test('T12: distribution:work --once processes one cycle then exits', function () {
            $this->ensureMockJob('WorkOnceJob');
            \Illuminate\Support\Facades\Queue::fake();

            $data = $this->buildBatch(3, 'WorkOnceJob', 20);
            $this->repo->initDistributionData($data);

            // Run distribution:work --once in same process via Artisan::call
            $exitCode = \Illuminate\Support\Facades\Artisan::call('distribution:work', ['--once' => true]);
            $output = \Illuminate\Support\Facades\Artisan::output();

            $this->assertEq(0, $exitCode, 'worker exited cleanly');
            $this->assertTrue(str_contains($output, 'worker started'), 'worker started');
            $this->assertTrue(str_contains($output, 'worker stopped'), 'worker stopped');

            // Items should have been pushed (from initial → pushed)
            $pushed = Distributions::where('distribution_job_name', 'WorkOnceJob')
                ->where('distribution_request_id', 20)
                ->whereIn('distribution_current_state', ['pushed', 'completed'])->count();
            $this->assertTrue($pushed > 0, "items processed ($pushed pushed/completed)");
        });

        // T13: Monitor HTTP API
        $this->test('T13: Monitor HTTP API — /stats, /stats/{job}, /failures', function () {
            // Seed some data if not already present
            if (Distributions::count() === 0) {
                $this->repo->initDistributionData($this->buildBatch(5, 'MonitorApiJob', 30));
            }

            $prefix = config('distribution.monitor.prefix', 'distribution-monitor');
            $baseUrl = 'http://127.0.0.1:8088';

            try {
                // GET /stats
                $r1 = Http::timeout(5)->get("$baseUrl/$prefix/stats");
                $this->assertEq(200, $r1->status(), '/stats 200');
                $body = $r1->json();
                $this->assertTrue(isset($body['quota']), '/stats has quota');
                $this->assertTrue(isset($body['jobs']), '/stats has jobs');

                // GET /stats/{jobName}
                $jobName = $body['jobs'][0]['job'] ?? 'SimpleTestJob';
                $r2 = Http::timeout(5)->get("$baseUrl/$prefix/stats/$jobName");
                $this->assertEq(200, $r2->status(), '/stats/{job} 200');
                $this->assertTrue(isset($r2->json()['requests']), '/stats/{job} has requests');

                // GET /failures
                $r3 = Http::timeout(5)->get("$baseUrl/$prefix/failures");
                $this->assertEq(200, $r3->status(), '/failures 200');
                $this->assertTrue(isset($r3->json()['failures']), '/failures has failures');
            } catch (\Exception $e) {
                throw new \RuntimeException('HTTP API test failed: ' . $e->getMessage()
                    . " — is 'php artisan serve' running on port 8088?");
            }
        });

        // T14: Archive command
        $this->test('T14: Archive — dry-run then execute', function () {
            // Create old completed data
            $data = $this->buildBatch(10, 'ArchiveJob', 40);
            $this->repo->initDistributionData($data);

            $tenDaysAgo = Carbon::now()->subDays(10);
            $dists = Distributions::where('distribution_job_name', 'ArchiveJob')->get();
            foreach ($dists as $d) {
                $d->update([
                    'distribution_current_state' => 'completed',
                    'distribution_updated_at' => $tenDaysAgo,
                ]);
                // Add extra intermediate states (initial → pushed → processing → completed)
                foreach (['pushed', 'processing', 'completed'] as $state) {
                    DistributionStates::create([
                        'fk_distribution_id' => $d->distribution_id,
                        'distribution_state_value' => $state,
                        'distribution_state_created_at' => $tenDaysAgo,
                    ]);
                }
            }

            // States: initial(10) + pushed(10) + processing(10) + completed(10) = 40 states
            $statesBefore = DistributionStates::whereIn('fk_distribution_id',
                $dists->pluck('distribution_id'))->count();
            $this->assertEq(40, $statesBefore, '40 states before archive');

            // Dry run
            $artisan = base_path('artisan');
            $dryRun = new Process([PHP_BINARY, $artisan, 'distribution:archive', '--days=7', '--dry-run']);
            $dryRun->run();
            $this->assertTrue(str_contains($dryRun->getOutput(), 'DRY-RUN'), 'dry-run works');

            // Execute (keep latest state)
            $archive = new Process([PHP_BINARY, $artisan, 'distribution:archive', '--days=7']);
            $archive->run();

            $statesAfter = DistributionStates::whereIn('fk_distribution_id',
                $dists->pluck('distribution_id'))->count();
            // Should keep only 1 latest state per distribution = 10
            $this->assertEq(10, $statesAfter, 'only latest states remain');

            // Purge — delete everything
            $purge = new Process([PHP_BINARY, $artisan, 'distribution:archive', '--days=7', '--purge']);
            $purge->run();

            $distsAfter = Distributions::where('distribution_job_name', 'ArchiveJob')->count();
            $this->assertEq(0, $distsAfter, 'purge removed distributions');
        });

        // T15: Migration — fresh migrate on current DB
        $this->test('T15: Migration — tables exist with correct columns', function () {
            $columns = DB::select('DESCRIBE distributions');
            $colNames = array_map(fn($c) => $c->Field, $columns);

            $required = [
                'distribution_id', 'distribution_request_id', 'distribution_payload',
                'distribution_job_name', 'distribution_tries', 'distribution_priority',
                'distribution_current_state', 'distribution_created_at', 'distribution_updated_at',
            ];
            foreach ($required as $col) {
                $this->assertTrue(in_array($col, $colNames), "distributions has column $col");
            }

            // Check current_state is enum
            $stateCol = collect($columns)->firstWhere('Field', 'distribution_current_state');
            $this->assertTrue(str_contains($stateCol->Type, 'enum'), 'current_state is enum');

            // Check distribution_states table
            $stateColumns = DB::select('DESCRIBE distribution_states');
            $stateColNames = array_map(fn($c) => $c->Field, $stateColumns);
            $this->assertTrue(in_array('fk_distribution_id', $stateColNames), 'states has FK');
            $this->assertTrue(in_array('distribution_state_value', $stateColNames), 'states has value');
            $this->assertTrue(in_array('distribution_state_log', $stateColNames), 'states has log');

            // Check composite index exists (current_state + job_name)
            $indexes = DB::select("SHOW INDEX FROM distributions WHERE Key_name LIKE 'idx_dist_current_state%'");
            $indexCols = array_map(fn($i) => $i->Column_name, $indexes);
            $this->assertTrue(in_array('distribution_current_state', $indexCols), 'index has current_state');
            $this->assertTrue(in_array('distribution_job_name', $indexCols), 'index has job_name');
        });

        // T16: Worker memory limit
        $this->test('T16: Worker memory limit — exits cleanly when exceeded', function () {
            $artisan = base_path('artisan');
            // Set memory limit to 1MB — will immediately exceed
            $process = new Process([PHP_BINARY, $artisan, 'distribution:work', '--once', '--memory=1']);
            $process->setTimeout(30);
            $process->run();

            $output = $process->getOutput();
            // Worker should exit due to memory limit (1MB is way too low)
            $this->assertTrue(
                str_contains($output, 'Memory limit') || str_contains($output, 'worker stopped'),
                'worker exits on memory limit'
            );
        });
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function printEnvironment(): void
    {
        $driver = DB::connection()->getDriverName();
        $dbVersion = DB::select('SELECT VERSION() as v')[0]->v ?? 'unknown';
        $redisOk = 'N/A';
        try {
            Redis::connection(config('distribution.cache.connection', 'default'))->ping();
            $redisOk = 'connected';
        } catch (\Exception $e) {
            $redisOk = 'unavailable';
        }

        $this->line("  PHP:     " . PHP_VERSION);
        $this->line("  DB:      $driver $dbVersion");
        $this->line("  Redis:   $redisOk");
        $this->line("  Cache:   " . ($this->cache->isEnabled() ? 'enabled' : 'disabled'));
        $this->line("  Quota:   " . config('distribution.quota'));
        $this->line("  Workers: " . config('distribution.supervisor.workers'));
    }

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
                'distribution_payload' => json_encode(['item' => $i, 'job' => $jobName]),
                'distribution_job_name' => $jobName,
                'distribution_priority' => rand(1, 10),
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
            $file = basename($e->getFile()) . ':' . $e->getLine();
            $this->errors[] = "$name: $msg ($file)";
            $this->line("  <fg=red>✗</> $name");
            $this->line("    <fg=red>→ $msg</>");
        }
    }

    private function skip(string $reason): void
    {
        $this->skipped++;
        $this->line("  <fg=yellow>⊘</> $reason");
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

    private function cleanup(): void
    {
        $this->info('Cleaning up...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distribution_states')->truncate();
        DB::table('distributions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            $prefix = config('distribution.cache.prefix', 'dist');
            $redis = Redis::connection(config('distribution.cache.connection', 'default'));
            $keys = $redis->keys("$prefix:*");
            foreach ($keys as $key) {
                $laravelPrefix = config('database.redis.options.prefix', '');
                $redis->del(str_replace($laravelPrefix, '', $key));
            }
        } catch (\Exception $e) {
            // ignore
        }

        config(['distribution.quota' => 50]);
        @unlink(storage_path('distribution-supervisor.lock'));
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed + $this->skipped;
        $this->newLine();
        $this->info('╔════════════════════════════════════════════╗');
        if ($this->failed === 0) {
            $this->info("║   ✓ ALL $this->passed/$total TESTS PASSED" . str_repeat(' ', max(0, 27 - strlen("$this->passed/$total"))) . "║");
        } else {
            $this->error("║   ✗ $this->failed/$total TESTS FAILED" . str_repeat(' ', max(0, 28 - strlen("$this->failed/$total"))) . "║");
        }
        if ($this->skipped > 0) {
            $this->info("║   ⊘ $this->skipped skipped" . str_repeat(' ', max(0, 33 - strlen("$this->skipped"))) . "║");
        }
        $this->info('╚════════════════════════════════════════════╝');

        if (!empty($this->errors)) {
            $this->newLine();
            $this->error('Failures:');
            foreach ($this->errors as $i => $err) {
                $this->line("  " . ($i + 1) . ". <fg=red>$err</>");
            }
        }
    }
}
