<?php

namespace PLSys\DistrbutionQueue\Tests\Feature\Console;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class DistributionCleanUpCommandTest extends TestCase
{
    public function test_cleanup_command_runs_successfully()
    {
        Queue::fake();

        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'CleanupTestJob']);
        DistributionFactory::markState(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            Carbon::now()->subHours(2)
        );

        if (!class_exists("\\App\\Jobs\\CleanupTestJob")) {
            eval("namespace App\\Jobs; class CleanupTestJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }");
        }

        $this->artisan('distribution:clean-up', [
            'job' => 'CleanupTestJob',
            '--tries' => 3,
            '--range' => 1,
        ])->assertSuccessful();
    }
}
