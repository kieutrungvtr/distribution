<?php

namespace PLSys\DistrbutionQueue\Console;

use App\Libs\ELogger;
use App\Services\PushingService;
use Illuminate\Console\Command;

class DistributionPushing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'distribution:pushing 
                            {job : Job name, must not be empty}
                            {--request_id=} {--sync=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $jobName = $this->argument('job') ?? null;
        $requestId = $this->option('request_id') ?? null;
        $sync = $this->option('sync') ?? null;
        $batch = intval(config('distribution.batch'));
        $pushingService = new PushingService();
        if ($requestId) {
            $pushingService->optionRequestId($requestId);
        }
        if ($sync) {
            $pushingService->optionSync($sync);
        }
        $pushingService->process($jobName, $batch);
    }

}
