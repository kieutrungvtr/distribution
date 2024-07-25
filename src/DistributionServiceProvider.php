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
            Console\DistributionCreateJob::class,
            Console\DistributionPushing::class,
            Console\DistributionCleanUp::class
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
            __DIR__.'/resources/views' => base_path('resources/views/vendor/distribution-queue'),
        ], 'views');

        $this->publishes([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'migrations');
    }
}
