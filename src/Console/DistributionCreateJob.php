<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;

class DistributionCreateJob extends Command
{
    protected $signature = 'distribution:create-job {job}';

    protected $description = 'Create job';

    public function handle()
    {
        $path = app('SYS_PATH') . '/app/Jobs';
        $this->info('Creating Job');
        self::createJob($path);
        $this->info('Create job completed');
    }

    private function createJob($path)
    {
        $view = view('vendor.distribution_queue.job');
        $view->className = $this->argument('job');
        $content = $view->render();
        file_put_contents($path . '/' . $view->className . '.php', $content);
            
    }
}
