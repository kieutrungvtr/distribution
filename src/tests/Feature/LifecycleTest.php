<?php

namespace PLSys\DistrbutionQueue\Tests\Feature;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;

use PLSys\DistrbutionQueue\App\Services\PushingService;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class LifecycleTest extends TestCase
{
    private PushingService $service;
    private DistributionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DistributionRepository();
        $this->service = new PushingService($this->repo);
    }

    private function createMockJobClass(string $name): void
    {
        if (!class_exists("\\App\\Jobs\\$name")) {
            $code = "namespace App\\Jobs; class $name implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }";
            eval($code);
        }
    }

    public function test_full_lifecycle_register_push_complete()
    {
        Queue::fake();
        $this->createMockJobClass('LifecycleJob');

        // Register
        $initData = [];
        for ($i = 1; $i <= 5; $i++) {
            $initData[] = [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
                Distributions::COL_DISTRIBUTION_PAYLOAD => json_encode(['item' => $i]),
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'LifecycleJob',
            ];
        }
        $this->repo->initDistributionData($initData);
        $this->assertDatabaseCount('distributions', 5);

        // Push
        $response = $this->service->process('LifecycleJob', 10);
        $this->assertEquals(200, $response->getStatusCode());

        // Simulate completion
        $distributions = Distributions::all();
        foreach ($distributions as $dist) {
            $this->service->post(
                $dist->{Distributions::COL_DISTRIBUTION_ID},
                DistributionStates::DISTRIBUTION_STATES_COMPLETED
            );
        }

        // Verify all completed
        $completedCount = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_COMPLETED, 'LifecycleJob');
        $this->assertEquals(5, $completedCount);

        $pushedCount = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'LifecycleJob');
        $this->assertEquals(0, $pushedCount);
    }

    public function test_fail_then_retry_success()
    {
        Queue::fake();
        $this->createMockJobClass('RetryJob');

        DistributionFactory::createBatch(1, 1, 'RetryJob');

        // First push
        $response = $this->service->process('RetryJob', 10);
        $this->assertEquals(200, $response->getStatusCode());

        // Simulate failure
        $dist = Distributions::first();
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        $this->service->post($id, DistributionStates::DISTRIBUTION_STATES_FAILED, 'Connection timeout');

        // Wait for cooldown (simulate)
        Carbon::setTestNow(Carbon::now()->addHours(2));

        // Retry via backlog
        $retryService = new PushingService($this->repo);
        $retryService->backlogFlag(true);
        $this->createMockJobClass('RetryJob');

        $response = $retryService->process('RetryJob', 10, 3, 1);
        $this->assertEquals(200, $response->getStatusCode());

        // Simulate success on retry
        $this->service->post($id, DistributionStates::DISTRIBUTION_STATES_COMPLETED);

        $completedCount = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_COMPLETED, 'RetryJob');
        $this->assertEquals(1, $completedCount);

        Carbon::setTestNow(); // Reset
    }

    public function test_max_retries_exhaustion()
    {
        Queue::fake();
        $this->createMockJobClass('ExhaustJob');

        $dist = DistributionFactory::createDistribution([
            'distribution_job_name' => 'ExhaustJob',
            'distribution_tries' => 2,
        ]);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));

        $this->service->backlogFlag(true);
        // tries=3, current tries=2, so one more try left
        $response = $this->service->process('ExhaustJob', 10, 3, 1);
        $this->assertEquals(200, $response->getStatusCode());

        // After this dispatch, tries becomes 3 — no more retries
        $updated = Distributions::find($id);
        $this->assertEquals(3, $updated->{Distributions::COL_DISTRIBUTION_TRIES});

        // Simulate failure again
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));

        Carbon::setTestNow(Carbon::now()->addHours(3));
        $retryService = new PushingService($this->repo);
        $retryService->backlogFlag(true);
        $response = $retryService->process('ExhaustJob', 10, 3, 1);
        $this->assertEquals(406, $response->getStatusCode());
        Carbon::setTestNow();
    }

    public function test_quota_throttling_across_rounds()
    {
        Queue::fake();
        $this->app['config']->set('distribution.quota', 3);
        $this->createMockJobClass('QuotaJob');

        DistributionFactory::createBatch(10, 1, 'QuotaJob');

        // Round 1: push 3
        $response = $this->service->process('QuotaJob', 10);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('3 request pushed', $response->getContent());

        // Round 2: quota full
        $service2 = new PushingService($this->repo);
        $response2 = $service2->process('QuotaJob', 10);
        $this->assertEquals(406, $response2->getStatusCode());

        // Complete 2 of the 3 pushed
        $pushed = Distributions::where(
            Distributions::COL_DISTRIBUTION_CURRENT_STATE,
            DistributionStates::DISTRIBUTION_STATES_PUSHED
        )->take(2)->get();

        foreach ($pushed as $p) {
            $this->service->post($p->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_COMPLETED);
        }

        // Round 3: 2 slots freed
        $service3 = new PushingService($this->repo);
        $response3 = $service3->process('QuotaJob', 10);
        $this->assertEquals(200, $response3->getStatusCode());
        $this->assertStringContainsString('2 request pushed', $response3->getContent());
    }

    public function test_multi_job_type_independent()
    {
        Queue::fake();
        $this->createMockJobClass('JobAlpha');
        $this->createMockJobClass('JobBeta');

        DistributionFactory::createBatch(5, 1, 'JobAlpha');
        DistributionFactory::createBatch(3, 2, 'JobBeta');

        $responseA = $this->service->process('JobAlpha', 10);
        $this->assertEquals(200, $responseA->getStatusCode());
        $this->assertStringContainsString('5 request pushed', $responseA->getContent());

        $service2 = new PushingService($this->repo);
        $responseB = $service2->process('JobBeta', 10);
        $this->assertEquals(200, $responseB->getStatusCode());
        $this->assertStringContainsString('3 request pushed', $responseB->getContent());
    }
}
