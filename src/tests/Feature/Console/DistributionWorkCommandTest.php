<?php

namespace PLSys\DistrbutionQueue\Tests\Feature\Console;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class DistributionWorkCommandTest extends TestCase
{
    private function createMockJobClass(string $name): void
    {
        if (!class_exists("\\App\\Jobs\\$name")) {
            eval("namespace App\\Jobs; class $name implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }");
        }
    }

    public function test_work_command_discovers_and_pushes_multiple_job_types()
    {
        Queue::fake();
        $this->createMockJobClass('AlphaJob');
        $this->createMockJobClass('BetaJob');
        $this->createMockJobClass('GammaJob');

        DistributionFactory::createBatch(3, 1, 'AlphaJob');
        DistributionFactory::createBatch(2, 2, 'BetaJob');
        DistributionFactory::createBatch(4, 3, 'GammaJob');

        $this->artisan('distribution:work', ['--once' => true])
             ->assertSuccessful();

        // All 9 items should be pushed
        $pushedCount = DistributionStates::where(
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE,
            DistributionStates::DISTRIBUTION_STATES_PUSHED
        )->count();
        $this->assertEquals(9, $pushedCount);
    }

    public function test_work_command_retries_failed_jobs()
    {
        Queue::fake();
        $this->createMockJobClass('RetryWorkJob');

        $dist = DistributionFactory::createDistribution([
            'distribution_job_name' => 'RetryWorkJob',
            'distribution_tries' => 0,
        ]);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));

        $this->artisan('distribution:work', ['--once' => true, '--tries' => 3, '--range' => 1])
             ->assertSuccessful();

        $updated = Distributions::find($id);
        $this->assertEquals(1, $updated->{Distributions::COL_DISTRIBUTION_TRIES});
    }

    public function test_work_command_skips_when_no_active_jobs()
    {
        $this->artisan('distribution:work', ['--once' => true])
             ->assertSuccessful();
    }

    public function test_get_active_job_names_returns_correct_list()
    {
        $repo = new DistributionRepository();

        DistributionFactory::createBatch(2, 1, 'JobX');
        DistributionFactory::createBatch(3, 2, 'JobY');

        // Completed job should not appear
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'JobZ']);
        DistributionFactory::markState(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_COMPLETED
        );

        $names = $repo->getActiveJobNames();

        $this->assertContains('JobX', $names);
        $this->assertContains('JobY', $names);
        $this->assertNotContains('JobZ', $names);
    }

    public function test_get_active_job_names_empty_when_all_completed()
    {
        $repo = new DistributionRepository();

        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'DoneJob']);
        DistributionFactory::markState(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_COMPLETED
        );

        $names = $repo->getActiveJobNames();
        $this->assertEmpty($names);
    }
}
