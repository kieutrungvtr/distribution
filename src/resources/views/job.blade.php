<?php echo "
<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Services\PushingService;
use App\Models\Sql\Distributions;
use App\Models\Sql\DistributionStates;

class {$className} implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected \$data;

    /**
     * Create a new job instance.
     */
    public function __construct(array \$data = [])
    {
        \$this->data = \$data;
    }

    public function middleware()
    {
        return [(new WithoutOverlapping(\$this->data[Distributions::COL_DISTRIBUTION_ID]))->dontRelease()];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            \$pushingService = new PushingService();
            \$distributionId = \$this->data[Distributions::COL_DISTRIBUTION_ID];
            \$pushingService->post(\$distributionId, DistributionStates::DISTRIBUTION_STATES_PROCESSING);
            #---- Enter your code here, enjoy! -----#
    




            #---- Ended block -----#
            \$pushingService->post(\$distributionId, DistributionStates::DISTRIBUTION_STATES_COMPLETED);
        } catch (Exception \$e) {
            \$pushingService->post(\$distributionId, DistributionStates::DISTRIBUTION_STATES_FAILED, \$e->getMessage());

        }

    }

}";
