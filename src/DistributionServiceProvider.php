<?php

namespace PLSys\DistrbutionQueue;


use Illuminate\Support\ServiceProvider;

class DistributionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            Console\DistributionCleanUp::class,
            Console\DistributionCreateJob::class,
        ]);
    }

    /**
     * Register the application's event listeners.
     */
    public function boot(): void
    {
        
    }
}
