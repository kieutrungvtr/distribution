<?php

namespace PLSys\DistrbutionQueue\Tests\Feature;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use Carbon\Carbon;

class MonitorTest extends TestCase
{
    private DistributionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DistributionRepository();
    }

    public function test_get_stats_returns_all_jobs()
    {
        DistributionFactory::createBatch(3, 1, 'JobA');
        DistributionFactory::createBatch(2, 2, 'JobB');

        $stats = $this->repo->getStats();

        $this->assertCount(2, $stats);
        $jobNames = array_column($stats, 'job');
        $this->assertContains('JobA', $jobNames);
        $this->assertContains('JobB', $jobNames);
    }

    public function test_get_stats_filters_by_job()
    {
        DistributionFactory::createBatch(3, 1, 'JobA');
        DistributionFactory::createBatch(2, 2, 'JobB');

        $stats = $this->repo->getStats('JobA');
        $this->assertCount(1, $stats);
        $this->assertEquals('JobA', $stats[0]['job']);
        $this->assertEquals(3, $stats[0]['initial']);
    }

    public function test_get_stats_counts_per_state()
    {
        $d1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $d2 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $d3 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);

        DistributionFactory::markState($d1->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($d2->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_COMPLETED);
        // d3 stays at initial

        $stats = $this->repo->getStats('TestJob');
        $this->assertEquals(1, $stats[0]['initial']);
        $this->assertEquals(1, $stats[0]['pushed']);
        $this->assertEquals(1, $stats[0]['completed']);
        $this->assertEquals(3, $stats[0]['total']);
    }

    public function test_get_stats_by_request_id()
    {
        DistributionFactory::createBatch(5, 10, 'TestJob');
        DistributionFactory::createBatch(3, 20, 'TestJob');

        $stats = $this->repo->getStatsByRequestId('TestJob');
        $this->assertCount(2, $stats);

        $byId = collect($stats)->keyBy('request_id');
        $this->assertEquals(5, $byId[10]['initial']);
        $this->assertEquals(3, $byId[20]['initial']);
    }

    public function test_get_recent_failures()
    {
        $d1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState(
            $d1->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            Carbon::now(),
            'Connection refused'
        );

        $failures = $this->repo->getRecentFailures('TestJob');
        $this->assertCount(1, $failures);
        $this->assertEquals('Connection refused', $failures[0]['error']);
    }

    public function test_get_recent_failures_empty()
    {
        DistributionFactory::createBatch(3, 1, 'TestJob');
        $failures = $this->repo->getRecentFailures('TestJob');
        $this->assertEmpty($failures);
    }

    public function test_monitor_command_runs()
    {
        DistributionFactory::createBatch(3, 1, 'TestJob');

        $this->artisan('distribution:monitor')
             ->assertSuccessful();
    }

    public function test_monitor_command_json_output()
    {
        DistributionFactory::createBatch(2, 1, 'TestJob');

        $this->artisan('distribution:monitor', ['--json' => true])
             ->assertSuccessful();
    }

    public function test_monitor_api_stats()
    {
        DistributionFactory::createBatch(3, 1, 'JobA');
        DistributionFactory::createBatch(2, 2, 'JobB');

        $response = $this->getJson('/distribution-monitor/stats');

        $response->assertOk();
        $response->assertJsonStructure([
            'quota',
            'jobs' => [['job', 'initial', 'pushed', 'processing', 'failed', 'completed', 'total']],
        ]);
        $this->assertCount(2, $response->json('jobs'));
    }

    public function test_monitor_api_stats_filter_by_job()
    {
        DistributionFactory::createBatch(3, 1, 'JobA');
        DistributionFactory::createBatch(2, 2, 'JobB');

        $response = $this->getJson('/distribution-monitor/stats?job=JobA');

        $response->assertOk();
        $this->assertCount(1, $response->json('jobs'));
        $this->assertEquals('JobA', $response->json('jobs.0.job'));
    }

    public function test_monitor_api_detail()
    {
        DistributionFactory::createBatch(5, 10, 'TestJob');
        DistributionFactory::createBatch(3, 20, 'TestJob');

        $response = $this->getJson('/distribution-monitor/stats/TestJob');

        $response->assertOk();
        $this->assertCount(2, $response->json('requests'));
    }

    public function test_monitor_api_failures()
    {
        $d1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState(
            $d1->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            Carbon::now(),
            'Timeout'
        );

        $response = $this->getJson('/distribution-monitor/failures?job=TestJob');

        $response->assertOk();
        $this->assertCount(1, $response->json('failures'));
        $this->assertEquals('Timeout', $response->json('failures.0.error'));
    }
}
