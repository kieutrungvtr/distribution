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
        $this->app->bind('SYS_PATH', function ($app) {
            return __DIR__.'/../';
        });

        $this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/distribution_queue'),
        ], 'views');
    }
}
