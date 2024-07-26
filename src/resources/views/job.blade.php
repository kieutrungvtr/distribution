<?php echo "
<?php

namespace App\Jobs;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PLSys\DistrbutionQueue\App\Services\PushingService;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

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

    // public function middleware()
    // {
    //     return [(new WithoutOverlapping(\$this->data[Distributions::COL_DISTRIBUTION_ID]))->dontRelease()];
    // }

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
