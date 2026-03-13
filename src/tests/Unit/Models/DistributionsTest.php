<?php

namespace PLSys\DistrbutionQueue\Tests\Unit\Models;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;

class DistributionsTest extends TestCase
{
    public function test_distributions_latest_state_relationship()
    {
        $dist = DistributionFactory::createDistribution();
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};

        // Initial state was created by factory
        $latestState = $dist->fresh()->latestState;
        $this->assertNotNull($latestState);
        $this->assertEquals(DistributionStates::DISTRIBUTION_STATES_INIT, $latestState->{DistributionStates::COL_DISTRIBUTION_STATE_VALUE});

        // Add pushed state
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PUSHED);

        $latestState = $dist->fresh()->latestState;
        $this->assertEquals(DistributionStates::DISTRIBUTION_STATES_PUSHED, $latestState->{DistributionStates::COL_DISTRIBUTION_STATE_VALUE});

        // Add processing state
        DistributionFactory::markState($id, DistributionStates::DISTRIBUTION_STATES_PROCESSING);

        $latestState = $dist->fresh()->latestState;
        $this->assertEquals(DistributionStates::DISTRIBUTION_STATES_PROCESSING, $latestState->{DistributionStates::COL_DISTRIBUTION_STATE_VALUE});
    }
}
