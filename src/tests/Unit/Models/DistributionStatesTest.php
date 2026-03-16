<?php

namespace PLSys\DistrbutionQueue\Tests\Unit\Models;

use PLSys\DistrbutionQueue\Tests\TestCase;
use PLSys\DistrbutionQueue\Tests\Helpers\DistributionFactory;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;

class DistributionStatesTest extends TestCase
{
    public function test_state_belongs_to_distribution()
    {
        $dist = DistributionFactory::createDistribution();
        $id = $dist->{Distributions::COL_DISTRIBUTION_ID};

        $state = DistributionStates::where(DistributionStates::COL_FK_DISTRIBUTION_ID, $id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->distributions);
        $this->assertEquals($id, $state->distributions->{Distributions::COL_DISTRIBUTION_ID});
    }
}
