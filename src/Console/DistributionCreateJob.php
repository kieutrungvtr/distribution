<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DistributionCreateJob extends Command
{
    protected $signature = 'distribution:create-job {job}';

    protected $description = 'Create job';

    public function handle()
    {
        $commandPath = app()->basePath() . '/app/Console/Commands';
        $this->info('Creating command');
        self::createComamnd($commandPath);
        $this->info('Create command completed');
        
        $this->info(PHP_EOL);

        $jobPath = app()->basePath() . '/app/Jobs';
        $this->info('Creating job');
        self::createJob($jobPath);
        $this->info('Create job completed');
    }

    private function createComamnd($path)
    {
        $job = $this->argument('job');
        $view = view('vendor.distribution-queue.command');
        $view->className = sprintf(
            'Distribution%sProvideDataCommand',
            $job
        );
        $view->signature = sprintf(
            'distribution:%s-provide-data-command',
            Str::kebab($job)
        );
        $content = $view->render();
        file_put_contents($path . '/' . $view->className . '.php', $content);
    }

    private function createJob($path)
    {
        $view = view('vendor.distribution-queue.job');
        $view->className = sprintf(
            'Distribution%sJob',
            $this->argument('job')
        );;
        $content = $view->render();
        file_put_contents($path . '/' . $view->className . '.php', $content);
            
    }
}
