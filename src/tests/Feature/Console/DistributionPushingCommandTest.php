<?php

namespace PLSys\DistrbutionQueue\Tests\Feature\Console;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use Illuminate\Support\Facades\Queue;

class DistributionPushingCommandTest extends TestCase
{
    public function test_pushing_command_runs_successfully()
    {
        Queue::fake();
        DistributionFactory::createBatch(3, 1, 'TestPushJob');

        if (!class_exists("\\App\\Jobs\\TestPushJob")) {
            eval("namespace App\\Jobs; class TestPushJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Bus\\Queueable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\SerializesModels; public \$data; public function __construct(\$data) { \$this->data = \$data; } public function handle() {} }");
        }

        $this->artisan('distribution:pushing', ['job' => 'TestPushJob'])
             ->assertSuccessful();
    }
}
