<?php

namespace App\Repositories\Sql;


use App\Models\Sql\DistributionStates;
use App\Repositories\BaseSqlRepository;
use Illuminate\Support\Facades\DB;

class DistributionStatesRepository extends BaseSqlRepository
{
    public function getModel()
    {
        return DistributionStates::class;
    }

}
