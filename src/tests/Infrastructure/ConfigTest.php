<?php

namespace PLSys\DistrbutionQueue\Tests\Infrastructure;

use PLSys\DistrbutionQueue\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_config_has_required_keys()
    {
        $config = config('distribution');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('batch', $config);
        $this->assertArrayHasKey('unique_for', $config);
        $this->assertArrayHasKey('quota', $config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertArrayHasKey('supervisor', $config);

        $this->assertEquals(10, $config['batch']);
        $this->assertEquals(3600, $config['unique_for']);
        $this->assertEquals(10, $config['quota']);

        // Worker defaults
        $this->assertEquals(128, $config['worker']['memory_limit']);

        // Cache defaults
        $this->assertFalse($config['cache']['enabled']);
        $this->assertEquals('default', $config['cache']['connection']);
        $this->assertEquals('dist', $config['cache']['prefix']);

        // Supervisor defaults
        $this->assertFalse($config['supervisor']['enabled']);
        $this->assertEquals(3, $config['supervisor']['workers']);
        $this->assertEquals(10, $config['supervisor']['rebalance_interval']);
    }
}
