<?php

namespace PLSys\DistrbutionQueue\Tests\Unit\Repositories;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use Carbon\Carbon;

class DistributionRepositoryTest extends TestCase
{
    private DistributionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DistributionRepository();
    }

    // ── A. Register (init) tests ──

    public function test_init_creates_distribution_and_initial_state()
    {
        $data = [
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
                Distributions::COL_DISTRIBUTION_PAYLOAD => json_encode(['foo' => 'bar']),
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'TestJob',
            ]
        ];

        $response = $this->repo->initDistributionData($data);
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertDatabaseCount('distributions', 1);
        $this->assertDatabaseCount('distribution_states', 1);

        $dist = Distributions::first();
        $state = DistributionStates::first();
        $this->assertEquals(1, $dist->{Distributions::COL_DISTRIBUTION_REQUEST_ID});
        $this->assertEquals(DistributionStates::DISTRIBUTION_STATES_INIT, $state->{DistributionStates::COL_DISTRIBUTION_STATE_VALUE});
    }

    public function test_init_batch_creates_multiple_in_transaction()
    {
        $data = [];
        for ($i = 1; $i <= 5; $i++) {
            $data[] = [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => $i,
                Distributions::COL_DISTRIBUTION_PAYLOAD => json_encode(['id' => $i]),
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'TestJob',
            ];
        }

        $response = $this->repo->initDistributionData($data);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertDatabaseCount('distributions', 5);
        $this->assertDatabaseCount('distribution_states', 5);
    }

    public function test_init_with_mixed_job_names()
    {
        $data = [
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
                Distributions::COL_DISTRIBUTION_PAYLOAD => '{}',
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'JobA',
            ],
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 2,
                Distributions::COL_DISTRIBUTION_PAYLOAD => '{}',
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'JobB',
            ],
        ];

        $response = $this->repo->initDistributionData($data);
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertEquals(1, Distributions::where(Distributions::COL_DISTRIBUTION_JOB_NAME, 'JobA')->count());
        $this->assertEquals(1, Distributions::where(Distributions::COL_DISTRIBUTION_JOB_NAME, 'JobB')->count());
    }

    public function test_init_validation_fails_missing_fields()
    {
        // Missing required fields — create will fail
        $data = [
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
                // missing payload and job_name
            ]
        ];

        // SQLite will actually fail on inserting without required columns
        $response = $this->repo->initDistributionData($data);
        // Should rollback — either 400 error or the payload column will cause an issue
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_init_validation_fails_empty_array()
    {
        $data = [];
        $response = $this->repo->initDistributionData($data);
        // Empty array means nothing inserted, still 200 (no error)
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_init_rollback_on_db_failure()
    {
        // First item valid, second will cause DB error (missing required fields)
        $data = [
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
                Distributions::COL_DISTRIBUTION_PAYLOAD => '{}',
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'TestJob',
            ],
            [
                // Missing required fields to cause failure
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 2,
            ],
        ];

        $response = $this->repo->initDistributionData($data);
        $this->assertEquals(400, $response->getStatusCode());
        // Transaction rolled back — no records
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_init_state_has_correct_fk_and_value()
    {
        $data = [
            [
                Distributions::COL_DISTRIBUTION_REQUEST_ID => 42,
                Distributions::COL_DISTRIBUTION_PAYLOAD => '{"test": true}',
                Distributions::COL_DISTRIBUTION_JOB_NAME => 'TestJob',
            ]
        ];

        $this->repo->initDistributionData($data);

        $dist = Distributions::first();
        $state = DistributionStates::first();

        $this->assertEquals(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            $state->{DistributionStates::COL_FK_DISTRIBUTION_ID}
        );
        $this->assertEquals(
            DistributionStates::DISTRIBUTION_STATES_INIT,
            $state->{DistributionStates::COL_DISTRIBUTION_STATE_VALUE}
        );
    }

    // ── B. Search tests ──

    public function test_search_returns_pending_items()
    {
        DistributionFactory::createBatch(3, 1, 'TestJob');
        $results = $this->repo->search('TestJob');
        $this->assertCount(3, $results);
    }

    public function test_search_excludes_pushed_items()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $results = $this->repo->search('TestJob');
        $this->assertCount(0, $results);
    }

    public function test_search_excludes_processing_items()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PROCESSING);

        $results = $this->repo->search('TestJob');
        $this->assertCount(0, $results);
    }

    public function test_search_excludes_completed_items()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_COMPLETED);

        $results = $this->repo->search('TestJob');
        $this->assertCount(0, $results);
    }

    public function test_search_excludes_failed_items()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_FAILED);

        $results = $this->repo->search('TestJob');
        $this->assertCount(0, $results);
    }

    public function test_search_filters_by_job_name()
    {
        DistributionFactory::createBatch(3, 1, 'JobA');
        DistributionFactory::createBatch(2, 1, 'JobB');

        $resultsA = $this->repo->search('JobA');
        $resultsB = $this->repo->search('JobB');

        $this->assertCount(3, $resultsA);
        $this->assertCount(2, $resultsB);
    }

    public function test_search_filters_by_request_id()
    {
        DistributionFactory::createBatch(3, 1, 'TestJob');
        DistributionFactory::createBatch(2, 2, 'TestJob');

        $results = $this->repo->search('TestJob', 1);
        $this->assertCount(3, $results);
    }

    public function test_search_fair_distribution_limit_per_group()
    {
        // 3 groups of 10 items each, global limit 9 → ~3 per group
        DistributionFactory::createBatch(10, 1, 'TestJob');
        DistributionFactory::createBatch(10, 2, 'TestJob');
        DistributionFactory::createBatch(10, 3, 'TestJob');

        $results = $this->repo->search('TestJob', null, 9);

        $this->assertCount(9, $results);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);
        foreach ($grouped as $group) {
            $this->assertEquals(3, $group->count());
        }
    }

    public function test_search_respects_global_limit()
    {
        DistributionFactory::createBatch(20, 1, 'TestJob');

        $results = $this->repo->search('TestJob', null, 5);
        $this->assertCount(5, $results);
    }

    public function test_search_and_lock_marks_pushed_atomically()
    {
        DistributionFactory::createBatch(3, 1, 'TestJob');

        $results = $this->repo->searchAndLock('TestJob', null, 10);

        $this->assertCount(3, $results);

        // Verify pushed states were created
        $pushedCount = DistributionStates::where(
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE,
            DistributionStates::DISTRIBUTION_STATES_PUSHED
        )->count();
        $this->assertEquals(3, $pushedCount);
    }

    public function test_search_and_lock_prevents_double_dispatch()
    {
        DistributionFactory::createBatch(5, 1, 'TestJob');

        $first = $this->repo->searchAndLock('TestJob', null, 10);
        $second = $this->repo->searchAndLock('TestJob', null, 10);

        $this->assertCount(5, $first);
        $this->assertCount(0, $second);
    }

    public function test_search_db_level_limit_prevents_oom()
    {
        // Create 50 items, search with globalLimit=5 → DB limit = min(25, 10000) = 25
        DistributionFactory::createBatch(50, 1, 'TestJob');

        $results = $this->repo->search('TestJob', null, 5);
        $this->assertCount(5, $results);
    }

    // ── C. countByStatus tests ──

    public function test_count_pushed_in_flight()
    {
        $dist1 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $dist2 = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState($dist1->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($dist2->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $count = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'TestJob');
        $this->assertEquals(2, $count);
    }

    public function test_count_excludes_completed_after_push()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_COMPLETED);

        // Latest state is completed, not pushed
        $count = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'TestJob');
        $this->assertEquals(0, $count);
    }

    public function test_count_handles_retried_items_latest_state()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};

        // Lifecycle: initial → pushed → failed → pushed (retry)
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED);
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $pushedCount = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'TestJob');
        $failedCount = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_FAILED, 'TestJob');

        $this->assertEquals(1, $pushedCount);
        $this->assertEquals(0, $failedCount);
    }

    public function test_count_filters_by_job_name()
    {
        $dist1 = DistributionFactory::createDistribution(['distribution_job_name' => 'JobA']);
        $dist2 = DistributionFactory::createDistribution(['distribution_job_name' => 'JobB']);
        DistributionFactory::markState($dist1->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($dist2->{Distributions::COL_DISTRIBUTION_ID}, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $this->assertEquals(1, $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'JobA'));
        $this->assertEquals(1, $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'JobB'));
    }

    public function test_count_returns_zero_when_empty()
    {
        $count = $this->repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, 'TestJob');
        $this->assertEquals(0, $count);
    }

    // ── D. SearchBackLog tests ──

    public function test_backlog_finds_failed_items_past_cooldown()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        $this->assertCount(1, $results);
    }

    public function test_backlog_excludes_completed()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_COMPLETED);

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        $this->assertCount(0, $results);
    }

    public function test_backlog_excludes_tries_exhausted()
    {
        $dist = DistributionFactory::createDistribution([
            'distribution_job_name' => 'TestJob',
            'distribution_tries' => 3,
        ]);
        DistributionFactory::markState(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            Carbon::now()->subHours(2)
        );

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        $this->assertCount(0, $results);
    }

    public function test_backlog_excludes_recent_failure()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        DistributionFactory::markState(
            $dist->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            Carbon::now()->subMinutes(10)  // Only 10 min ago, range is 1 hour
        );

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        $this->assertCount(0, $results);
    }

    public function test_backlog_checks_latest_state_not_any()
    {
        $dist = DistributionFactory::createDistribution(['distribution_job_name' => 'TestJob']);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        // Failed 2h ago, then pushed again (retry in progress)
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(2));
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        // Latest state is pushed, not failed → should NOT appear in backlog
        $this->assertCount(0, $results);
    }

    public function test_backlog_latest_failure_time_not_old_failure()
    {
        $dist = DistributionFactory::createDistribution([
            'distribution_job_name' => 'TestJob',
            'distribution_tries' => 1,
        ]);
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};
        // Old failure 3h ago
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subHours(3));
        // Recent failure 5min ago (latest)
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_FAILED, Carbon::now()->subMinutes(5));

        $results = $this->repo->searchBackLog('TestJob', null, 3, 1);
        // Latest failure is only 5min old, which is within the 1-hour range
        $this->assertCount(0, $results);
    }
}
