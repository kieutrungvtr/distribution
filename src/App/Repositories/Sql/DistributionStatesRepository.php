<?php

namespace PLSys\DistrbutionQueue\App\Repositories\Sql;


use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\BaseSqlRepository;
use Illuminate\Support\Facades\DB;

class DistributionStatesRepository extends BaseSqlRepository
{
    public function getModel()
    {
        return DistributionStates::class;
    }

}
