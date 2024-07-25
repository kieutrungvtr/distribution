<?php echo "
<?php

namespace App\Console\Commands;

use PLSys\DistrbutionQueue\App\Http\Requests\DistributionRequest;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Services\PushingService;
use Illuminate\Console\Command;

class {$className} extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected \$signature = '{$signature}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected \$description = 'Command description';

    public function handle()
    {
        \$data = new DistributionRequest();
        #---- Enter your code here, enjoy! -----#
        // Take our data and standardize it according to the required data type: DistributionRequest \$data
    




        #---- Ended session -----#
        \$pushingService = new PushingService();
        \$pushingService->init(\$data);
    }

}";
