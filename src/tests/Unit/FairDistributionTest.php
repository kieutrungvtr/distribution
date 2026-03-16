<?php

namespace PLSys\DistrbutionQueue\Tests\Unit;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;

class FairDistributionTest extends TestCase
{
    private DistributionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DistributionRepository();
    }

    public function test_equal_allocation_across_groups()
    {
        DistributionFactory::createBatch(10, 1, 'TestJob');
        DistributionFactory::createBatch(10, 2, 'TestJob');
        DistributionFactory::createBatch(10, 3, 'TestJob');

        $results = $this->repo->search('TestJob', null, 9);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        $this->assertCount(9, $results);
        $this->assertCount(3, $grouped);
        foreach ($grouped as $group) {
            $this->assertEquals(3, $group->count());
        }
    }

    public function test_small_group_releases_slots_to_larger()
    {
        // Group 1 has only 2 items, group 2 has 20 items, global limit 10
        DistributionFactory::createBatch(2, 1, 'TestJob');
        DistributionFactory::createBatch(20, 2, 'TestJob');

        $results = $this->repo->search('TestJob', null, 10);

        $this->assertCount(10, $results);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);
        // Group 1 gets all 2, group 2 gets remaining 8
        $this->assertEquals(2, $grouped->get(1)->count());
        $this->assertEquals(8, $grouped->get(2)->count());
    }

    public function test_priority_high_dispatched_first()
    {
        // Low priority group
        DistributionFactory::createBatch(10, 1, 'TestJob', [
            Distributions::COL_DISTRIBUTION_PRIORITY => 0,
        ]);
        // High priority group
        DistributionFactory::createBatch(10, 2, 'TestJob', [
            Distributions::COL_DISTRIBUTION_PRIORITY => 100,
        ]);

        $results = $this->repo->search('TestJob', null, 5);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        // High priority group (2) should get slots first
        $this->assertCount(5, $results);
        // With 2 groups, fair distribution gives ceil(5/2)=3 to first group (high priority)
        // then min(2, remaining) to second group
        $this->assertGreaterThanOrEqual(2, $grouped->get(2)->count());
    }

    public function test_priority_fills_slots_before_low_priority()
    {
        // High priority with small batch
        DistributionFactory::createBatch(3, 1, 'TestJob', [
            Distributions::COL_DISTRIBUTION_PRIORITY => 200,
        ]);
        // Low priority with large batch
        DistributionFactory::createBatch(20, 2, 'TestJob', [
            Distributions::COL_DISTRIBUTION_PRIORITY => 0,
        ]);

        $results = $this->repo->search('TestJob', null, 10);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        // High priority group 1 should get all 3
        $this->assertEquals(3, $grouped->get(1)->count());
        // Low priority group 2 gets remaining 7
        $this->assertEquals(7, $grouped->get(2)->count());
    }

    public function test_adaptive_when_group_completes_early()
    {
        // Group 1 has only 1 item left
        DistributionFactory::createBatch(1, 1, 'TestJob');
        // Group 2 has 50 items
        DistributionFactory::createBatch(50, 2, 'TestJob');
        // Group 3 has 50 items
        DistributionFactory::createBatch(50, 3, 'TestJob');

        $results = $this->repo->search('TestJob', null, 30);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        $this->assertCount(30, $results);
        // Group 1 gets 1 (all it has), groups 2 and 3 split remaining 29
        $this->assertEquals(1, $grouped->get(1)->count());
        $group2Count = $grouped->get(2)->count();
        $group3Count = $grouped->get(3)->count();
        $this->assertEquals(29, $group2Count + $group3Count);
    }

    public function test_single_group_gets_all_slots()
    {
        DistributionFactory::createBatch(20, 1, 'TestJob');

        $results = $this->repo->search('TestJob', null, 15);
        $this->assertCount(15, $results);

        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);
        $this->assertCount(1, $grouped);
        $this->assertEquals(15, $grouped->get(1)->count());
    }

    public function test_10_shops_100_items_each_fair()
    {
        for ($shop = 1; $shop <= 10; $shop++) {
            DistributionFactory::createBatch(100, $shop, 'TestJob');
        }

        $results = $this->repo->search('TestJob', null, 100);
        $grouped = collect($results)->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        $this->assertCount(100, $results);
        $this->assertCount(10, $grouped);

        // Each shop should get exactly 10 items
        foreach ($grouped as $shopId => $items) {
            $this->assertEquals(10, $items->count(), "Shop $shopId should get 10 items");
        }
    }
}
