<?php

namespace PLSys\DistrbutionQueue;


use Illuminate\Support\ServiceProvider;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Services\DistributionCache;
use PLSys\DistrbutionQueue\App\Services\PushingService;

class DistributionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/distribution.php', 'distribution');

        $this->commands([
            Console\DistributionCreateJob::class,
            Console\DistributionPushing::class,
            Console\DistributionCleanUp::class,
            Console\DistributionWork::class,
            Console\DistributionMonitor::class,
            Console\DistributionSupervisor::class,
            Console\DistributionStressTest::class,
            Console\DistributionArchive::class,
            Console\DistributionPreReleaseTest::class,
            Console\DistributionScenarioTest::class,
        ]);

        $this->app->singleton(DistributionCache::class, function () {
            return new DistributionCache();
        });

        $this->app->bind(PushingService::class, function ($app) {
            return new PushingService(
                $app->make(DistributionRepository::class),
                $app->make(DistributionCache::class)
            );
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'distribution-queue');

        if (config('distribution.monitor.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        }

        $this->publishes([
            __DIR__.'/resources/views' => base_path('resources/views/vendor/distribution-queue'),
        ], 'views');

        $this->publishes([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__.'/config/distribution.php' => config_path('distribution.php'),
        ], 'config');
    }
}
