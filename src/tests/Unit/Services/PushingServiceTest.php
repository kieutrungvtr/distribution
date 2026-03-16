<?php

namespace PLSys\DistrbutionQueue\Tests\Unit\Services;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;

use PLSys\DistrbutionQueue\App\Services\PushingService;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class PushingServiceTest extends TestCase
{
    private PushingService $service;
    private DistributionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DistributionRepository();
        $this->service = new PushingService($this->repo);
    }

    public function test_process_dispatches_to_correct_queue_name()
    {
        Queue::fake();
        DistributionFactory::createBatch(1, 1, 'PullDesignJob');

        // Create a mock job class
        $this->createMockJobClass('PullDesignJob');

        $this->service->process('PullDesignJob', 10);

        Queue::assertPushedOn('pull_design', \App\Jobs\PullDesignJob::class);
    }

    public function test_process_quota_blocks_when_full()
    {
        $this->app['config']->set('distribution.quota', 2);

        // Create 2 items already pushed
        $dist1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $dist2 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist1->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($dist2->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        // Create more pending items
        DistributionFactory::createBatch(3, 1, 'TestJob');

        $response = $this->service->process('TestJob', 10);
        $this->assertEquals(406, $response->getStatusCode());
        $this->assertStringContainsString('over quota', $response->getContent());
    }

    public function test_process_passes_remaining_slots_as_global_limit()
    {
        Queue::fake();
        $this->app['config']->set('distribution.quota', 5);

        // 2 already pushed
        $dist1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $dist2 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist1->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($dist2->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        // 10 pending
        DistributionFactory::createBatch(10, 1, 'TestJob');
        $this->createMockJobClass('TestJob');

        $response = $this->service->process('TestJob', 10);
        $this->assertEquals(200, $response->getStatusCode());
        // Only 3 should be dispatched (quota 5 - 2 pushed = 3 remaining)
        $this->assertStringContainsString('3 request pushed', $response->getContent());
    }

    public function test_process_returns_406_when_no_items()
    {
        $response = $this->service->process('TestJob', 10);
        $this->assertEquals(406, $response->getStatusCode());
        $this->assertStringContainsString('Have not request', $response->getContent());
    }

    public function test_process_sync_mode_bypasses_quota()
    {
        $this->app['config']->set('distribution.quota', 1);

        // Already 5 pushed
        for ($i = 0; $i < 5; $i++) {
            $d = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
            DistributionFactory::markState($d->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        }

        // 3 pending
        DistributionFactory::createBatch(3, 1, 'TestJob');
        $this->createMockJobClass('TestJob');

        $this->service->optionSync(true);
        $response = $this->service->process('TestJob', 10);

        // Sync mode should bypass quota and process all items
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_process_backlog_increments_tries()
    {
        Queue::fake();
        $dist = DistributionFactory::createDistribution([
            'distribution_job_name' => 'TestJob',
            'distribution_tries' => 0,
        ]);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));

        $this->createMockJobClass('TestJob');
        $this->service->backlogFlag(true);
        $response = $this->service->process('TestJob', 10, 3, 1);

        $this->assertEquals(200, $response->getStatusCode());

        $updated = Distributions::find($id);
        $this->assertEquals(1, $updated->{Distributions::COL_DISTRIBUTION_TRIES});
    }

    public function test_process_catches_dispatch_exception_marks_failed()
    {
        // Create a job class that throws on instantiation
        $this->createThrowingJobClass('BrokenJob');
        DistributionFactory::createBatch(1, 1, 'BrokenJob');

        $response = $this->service->process('BrokenJob', 10);

        // The dispatch should fail, item marked as failed
        $failedStates = DistributionStates::where(
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE,
            DistributionStates::DISTRIBUTION_STATES_FAILED
        )->count();
        $this->assertGreaterThanOrEqual(1, $failedStates);
    }

    public function test_process_dispatched_count_matches_response()
    {
        Queue::fake();
        DistributionFactory::createBatch(7, 1, 'TestJob');
        $this->createMockJobClass('TestJob');

        $response = $this->service->process('TestJob', 10);
        $this->assertStringContainsString('7 request pushed', $response->getContent());
    }

    public function test_process_atomic_pushed_then_dispatch()
    {
        Queue::fake();
        DistributionFactory::createBatch(3, 1, 'TestJob');
        $this->createMockJobClass('TestJob');

        $response = $this->service->process('TestJob', 10);
        $this->assertEquals(200, $response->getStatusCode());

        // Items should be marked as pushed via searchAndLock
        $pushedCount = DistributionStates::where(
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE,
            DistributionStates::DISTRIBUTION_STATES_PUSHED
        )->count();
        $this->assertEquals(3, $pushedCount);

        // Second call should find nothing
        $response2 = $this->service->process('TestJob', 10);
        $this->assertEquals(406, $response2->getStatusCode());
    }

    /**
     * Helper to create a mock job class at runtime.
     */
    private function createMockJobClass(string $name): void
    {
        if (!class_exists("\\App\\Jobs\\$name")) {
            $code = "namespace App\\Jobs; class $name implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }";
            eval($code);
        }
    }

    private function createThrowingJobClass(string $name): void
    {
        if (!class_exists("\\App\\Jobs\\$name")) {
            $code = "namespace App\\Jobs; class $name { public function __construct(\$data) { throw new \\RuntimeException('Job creation failed'); } }";
            eval($code);
        }
    }
}
