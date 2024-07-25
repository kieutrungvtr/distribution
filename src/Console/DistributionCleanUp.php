<?php

namespace PLSys\DistrbutionQueue\Console;

use PLSys\DistrbutionQueue\App\Services\PushingService;
use Illuminate\Console\Command;

class DistributionCleanUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'distribution:clean-up
                            {job : Job name need retry, must not be empty}
                            {--tries= : Number of retries. Default 3}
                            {--range= : Time range to get data retry. Default 1 hour}';

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
        $job = $this->argument('job');
        $tries = intval($this->option('tries') ?? 3);
        $timeRange = intval($this->option('range') ?? 1);
        $validator = \Illuminate\Support\Facades\Validator::make(
            [
                'job'   => $job,
                'tries' => $tries,
                'range' => $timeRange
            ],
            [
                'job'   => 'required',
                'tries' => 'nullable|integer',
                'range' => 'nullable|integer'
            ]
        );
        if ($validator->fails()) {
            $errors = implode("\n", data_get($validator->getMessageBag()->getMessages(), '*.0'));
            $this->error($errors);
            return 0;
        }
        $batch = intval(config('distribution.batch'));
        $pushingService = new PushingService();
        $pushingService->backlogFlag(true);
        $pushingService->process($job, $batch, $tries, $timeRange);
    }
}
