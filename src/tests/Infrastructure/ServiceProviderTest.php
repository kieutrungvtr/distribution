<?php

namespace PLSys\DistrbutionQueue\Tests\Infrastructure;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\App\Services\PushingService;
use Illuminate\Support\Facades\Artisan;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_registers_commands()
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('distribution:create-job', $commands);
        $this->assertArrayHasKey('distribution:pushing', $commands);
        $this->assertArrayHasKey('distribution:clean-up', $commands);
    }

    public function test_service_provider_publishes_config()
    {
        $config = config('distribution');
        $this->assertNotNull($config);
        $this->assertArrayHasKey('batch', $config);
        $this->assertArrayHasKey('quota', $config);
    }
}
