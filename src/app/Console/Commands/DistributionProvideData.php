<?php

namespace App\Console\Commands;

use App\Http\Requests\DistributionRequest;
use App\Models\Sql\DesignImportRequests;
use App\Models\Sql\Distributions;
use App\Services\PushingService;
use Illuminate\Console\Command;

class DistributionProvideData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'distribution:provide-data';

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
        $designRequest = DesignImportRequests::where(
            [
                DesignImportRequests::COL_STATUS => 'initial'
            ]
        )->take(10)->get();
        $data = new DistributionRequest();
        foreach ($designRequest as $key => $value) {
            $requestId = $value->{DesignImportRequests::COL_ID};
            $payload = null;
            $jobName = "PullDesignJob";
            $tmp['distribution_request'][] = [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => $requestId,
                Distributions::COL_DISTRIBUTION_PAYLOAD => $payload ?? '{}',
                Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
            ];
        }
        $data = $data->merge($tmp);
        $pushingService = new PushingService();
        $pushingService->init($data);
    }
}
