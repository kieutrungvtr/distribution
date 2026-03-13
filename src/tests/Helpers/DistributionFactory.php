<?php

namespace PLSys\DistrbutionQueue\Tests\Helpers;

use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;

class DistributionFactory
{
    public static function createDistribution(array $overrides = []): Distributions
    {
        $defaults = [
            Distributions::COL_DISTRIBUTION_REQUEST_ID => 1,
            Distributions::COL_DISTRIBUTION_PAYLOAD => json_encode(['key' => 'value']),
            Distributions::COL_DISTRIBUTION_JOB_NAME => 'TestJob',
            Distributions::COL_DISTRIBUTION_TRIES => 0,
            Distributions::COL_DISTRIBUTION_PRIORITY => 0,
            Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_INIT,
            Distributions::COL_DISTRIBUTION_CREATED_AT => now(),
            Distributions::COL_DISTRIBUTION_UPDATED_AT => now(),
        ];

        $distribution = Distributions::create(array_merge($defaults, $overrides));

        DistributionStates::insert([
            DistributionStates::COL_FK_DISTRIBUTION_ID => $distribution->{Distributions::COL_DISTRIBUTION_ID},
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_INIT,
            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => now(),
        ]);

        return $distribution;
    }

    public static function createBatch(int $count, int $requestId, string $jobName = 'TestJob', array $overrides = []): array
    {
        $distributions = [];
        for ($i = 0; $i < $count; $i++) {
            $distributions[] = self::createDistribution(array_merge([
                Distributions::COL_DISTRIBUTION_REQUEST_ID => $requestId,
                Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
            ], $overrides));
        }
        return $distributions;
    }

    public static function markState(int $distributionId, string $state, $createdAt = null, string $log = null): DistributionStates
    {
        $timestamp = $createdAt ?? now();

        // Write-through: keep distribution_current_state in sync
        Distributions::where(Distributions::COL_DISTRIBUTION_ID, $distributionId)
            ->update([
                Distributions::COL_DISTRIBUTION_CURRENT_STATE => $state,
                Distributions::COL_DISTRIBUTION_UPDATED_AT => $timestamp,
            ]);

        return DistributionStates::create([
            DistributionStates::COL_FK_DISTRIBUTION_ID => $distributionId,
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => $state,
            DistributionStates::COL_DISTRIBUTION_STATE_LOG => $log,
            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $timestamp,
        ]);
    }
}
