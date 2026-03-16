<?php

namespace PLSys\DistrbutionQueue\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PLSys\DistrbutionQueue\DistributionServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            DistributionServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('distribution.batch', 10);
        $app['config']->set('distribution.unique_for', 3600);
        $app['config']->set('distribution.quota', 10);
    }
}
