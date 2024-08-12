<?php

namespace PLSys\DistrbutionQueue\App\Repositories\Sql;

use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\BaseSqlRepository;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class DistributionRepository extends BaseSqlRepository
{
    public function getModel()
    {
        return Distributions::class;
    }

    public function search($jobName, $requestId = null, $limit = 10)
    {
        $arrStatus = [
            DistributionStates::DISTRIBUTION_STATES_PUSHED,
            DistributionStates::DISTRIBUTION_STATES_COMPLETED,
            DistributionStates::DISTRIBUTION_STATES_FAILED,
            DistributionStates::DISTRIBUTION_STATES_PROCESSING
        ];
        $where = [
            Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName
        ];
        if ($requestId) {
            $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
        }
        $data = Distributions::whereDoesntHave('states', function($query) use ($arrStatus) {
            $query->whereIn(DistributionStates::COL_DISTRIBUTION_STATE_VALUE, $arrStatus);
        })
        ->where($where)
        ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
        ->select(
            Distributions::COL_DISTRIBUTION_ID,
            Distributions::COL_DISTRIBUTION_REQUEST_ID,
            Distributions::COL_DISTRIBUTION_PAYLOAD,
            Distributions::COL_DISTRIBUTION_TRIES,
            Distributions::COL_DISTRIBUTION_JOB_NAME,
        )
        ->get()
        ->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID)
        ->map(function($value) use ($limit) {
            return $value->take($limit);
        });

        return $data;
    }

    public function searchBackLog($jobName, $requestId = null, $tries = 3, $timeRange = 1, $limit = 10)
    {
        $arrStatus = [
            DistributionStates::DISTRIBUTION_STATES_FAILED
        ];
        $where = [
            Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName
        ];
        if ($requestId) {
            $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
        }

        $hourAgo = Carbon::now()->subHours($timeRange);        ;
        $data = Distributions::whereHas('states', function ($query) use ($hourAgo) {
            $query->where(DistributionStates::COL_DISTRIBUTION_STATE_VALUE, DistributionStates::DISTRIBUTION_STATES_FAILED)
                  ->where(DistributionStates::COL_DISTRIBUTION_STATE_UPDATED_AT, '<', $hourAgo);
        })
        ->whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                  ->from(DistributionStates::TABLE_NAME)
                  ->whereColumn(DistributionStates::COL_FK_DISTRIBUTION_ID, Distributions::COL_DISTRIBUTION_ID)
                  ->where(DistributionStates::COL_DISTRIBUTION_STATE_VALUE, DistributionStates::DISTRIBUTION_STATES_COMPLETED);
        })
        ->where(Distributions::COL_DISTRIBUTION_TRIES, '<', $tries)
        ->select(
            Distributions::COL_DISTRIBUTION_ID,
            Distributions::COL_DISTRIBUTION_REQUEST_ID,
            Distributions::COL_DISTRIBUTION_PAYLOAD,
            Distributions::COL_DISTRIBUTION_TRIES,
            Distributions::COL_DISTRIBUTION_JOB_NAME,
        )
        ->get()
        ->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID)
        ->map(function($value) use ($limit) {
            return $value->take($limit);
        });
        return $data;
    }

    public function countByStatus($status)
    {
        $data = Distributions::whereHas('states', function ($query) use ($status) {
            $query->where(DistributionStates::COL_DISTRIBUTION_STATE_VALUE, $status);
        })->whereDoesntHave('states', function ($query) {
            $query->whereIn(
                DistributionStates::COL_DISTRIBUTION_STATE_VALUE,
                [
                    DistributionStates::DISTRIBUTION_STATES_FAILED,
                    DistributionStates::DISTRIBUTION_STATES_COMPLETED
                ]
            );
        })->get();

        return $data->count();
    }

    public function initDistributionData($distributions)
    {
        array_walk($distributions, function (&$subArray) {
            $subArray[Distributions::COL_DISTRIBUTION_CREATED_AT] = now();
        });
        try {
            DB::beginTransaction();
            foreach ($distributions as $distribution) {
                $insertResponse = Distributions::create($distribution);
                if ($insertResponse) {
                DistributionStates::insert(
                    [
                        DistributionStates::COL_FK_DISTRIBUTION_ID => $insertResponse->{Distributions::COL_DISTRIBUTION_ID},
                        DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_INIT,
                        DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => now()
                    ]
                );
            }
            }
            DB::commit();
            return Response::make("Distribution data initialization successful", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return Response::make("Distribution data initialization failed" . $e->getMessage(), 400);
        }
    }
}
