<?php

namespace PLSys\DistrbutionQueue\App\Repositories\Sql;

use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\BaseSqlRepository;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class DistributionRepository extends BaseSqlRepository
{
    public function getModel()
    {
        return Distributions::class;
    }

    public function search($jobName, $requestId = null, $globalLimit = PHP_INT_MAX)
    {
        $scope = $this->initScope($jobName, $requestId);
        $candidates = $this->buildCandidates($scope, $globalLimit);

        return $this->fairDistribute($candidates, $globalLimit)->toArray();
    }

    public function searchAndLock($jobName, $requestId = null, $globalLimit = PHP_INT_MAX)
    {
        return DB::transaction(function () use ($jobName, $requestId, $globalLimit) {
            $scope = $this->initScope($jobName, $requestId);
            $candidates = $this->buildCandidates($scope, $globalLimit);
            $distributed = $this->fairDistribute($candidates, $globalLimit);

            return $this->lockAndTransition($distributed, DistributionStates::DISTRIBUTION_STATES_INIT);
        }, 3);
    }

    public function searchBackLog($jobName, $requestId = null, $tries = 3, $timeRange = 1, $globalLimit = PHP_INT_MAX)
    {
        $scope = $this->backlogScope($jobName, $requestId, $tries, $timeRange);
        $candidates = $this->buildCandidates($scope, $globalLimit);

        return $this->fairDistribute($candidates, $globalLimit)->toArray();
    }

    public function searchBackLogAndLock($jobName, $requestId = null, $tries = 3, $timeRange = 1, $globalLimit = PHP_INT_MAX)
    {
        return DB::transaction(function () use ($jobName, $requestId, $tries, $timeRange, $globalLimit) {
            $scope = $this->backlogScope($jobName, $requestId, $tries, $timeRange);
            $candidates = $this->buildCandidates($scope, $globalLimit);
            $distributed = $this->fairDistribute($candidates, $globalLimit);

            return $this->lockAndTransition($distributed, DistributionStates::DISTRIBUTION_STATES_FAILED);
        }, 3);
    }

    // ── Private helpers ──────────────────────────────────────

    private const MAX_QUERY_LIMIT = 10000;

    private function initScope(string $jobName, ?string $requestId): \Closure
    {
        return function ($q) use ($jobName, $requestId) {
            $q->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName)
              ->where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, DistributionStates::DISTRIBUTION_STATES_INIT);
            if ($requestId) {
                $q->where(Distributions::COL_DISTRIBUTION_REQUEST_ID, $requestId);
            }
        };
    }

    private function backlogScope(string $jobName, ?string $requestId, int $tries, int $timeRange): \Closure
    {
        $hourAgo = Carbon::now()->subHours($timeRange);

        return function ($q) use ($jobName, $requestId, $tries, $hourAgo) {
            $q->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName)
              ->where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, DistributionStates::DISTRIBUTION_STATES_FAILED)
              ->where(Distributions::COL_DISTRIBUTION_TRIES, '<', $tries)
              ->where(Distributions::COL_DISTRIBUTION_UPDATED_AT, '<', $hourAgo);
            if ($requestId) {
                $q->where(Distributions::COL_DISTRIBUTION_REQUEST_ID, $requestId);
            }
        };
    }

    private function buildCandidates(callable $scope, int $globalLimit): Collection
    {
        $query = Distributions::query()
            ->orderBy(Distributions::COL_DISTRIBUTION_PRIORITY, 'DESC')
            ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
            ->select(
                Distributions::COL_DISTRIBUTION_ID,
                Distributions::COL_DISTRIBUTION_REQUEST_ID,
                Distributions::COL_DISTRIBUTION_PAYLOAD,
                Distributions::COL_DISTRIBUTION_TRIES,
                Distributions::COL_DISTRIBUTION_JOB_NAME,
                Distributions::COL_DISTRIBUTION_PRIORITY,
            );

        $scope($query);

        if ($globalLimit < PHP_INT_MAX / 10) {
            $query->limit(min($globalLimit * 10, self::MAX_QUERY_LIMIT));
        }

        return $query->get();
    }

    private function lockAndTransition(Collection $distributed, string $expectedState): array
    {
        if ($distributed->isEmpty()) {
            return [];
        }

        $ids = $distributed->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
        $driver = DB::connection()->getDriverName();

        $lockQuery = Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $ids)
            ->where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, $expectedState);

        $locked = ($driver === 'sqlite')
            ? $lockQuery->lockForUpdate()->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray()
            : $lockQuery->lock('FOR UPDATE SKIP LOCKED')->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();

        $lockedSet = array_flip($locked);
        $distributed = $distributed->filter(function ($item) use ($lockedSet) {
            return isset($lockedSet[$item[Distributions::COL_DISTRIBUTION_ID]]);
        })->values();

        if ($distributed->isNotEmpty()) {
            $now = now();

            DistributionStates::insert($distributed->map(function ($item) use ($now) {
                return [
                    DistributionStates::COL_FK_DISTRIBUTION_ID => $item[Distributions::COL_DISTRIBUTION_ID],
                    DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                    DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
                ];
            })->toArray());

            Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $locked)
                ->update([
                    Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                    Distributions::COL_DISTRIBUTION_UPDATED_AT => $now,
                ]);
        }

        return $distributed->toArray();
    }

    public function countByStatus($status, $jobName = null)
    {
        $query = Distributions::where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, $status);

        if ($jobName) {
            $query->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName);
        }
        return $query->count();
    }

    public function fairDistribute(Collection $items, int $globalLimit = PHP_INT_MAX): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $groups = $items->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID);

        // Sort groups by max priority DESC so high-priority groups fill slots first
        $groups = $groups->sortByDesc(function ($group) {
            return $group->max(Distributions::COL_DISTRIBUTION_PRIORITY);
        });

        // Clamp to item count — eliminates overflow when globalLimit = PHP_INT_MAX
        $remaining = min($globalLimit, $items->count());
        $selected = [];

        // Pass 1: fair allocation — each group gets ceil(remaining / groupsLeft)
        $groupsLeft = $groups->count();
        $groupRemainders = [];

        foreach ($groups as $requestId => $group) {
            $perGroup = (int) ceil($remaining / max($groupsLeft, 1));
            $take = min($perGroup, $group->count(), $remaining);

            array_push($selected, ...$group->take($take)->all());
            $remaining -= $take;
            $groupsLeft--;

            if ($take < $group->count()) {
                $groupRemainders[$requestId] = $group->slice($take);
            }

            if ($remaining <= 0) {
                break;
            }
        }

        // Pass 2: redistribute leftover slots to groups with remaining items
        foreach ($groupRemainders as $leftover) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($leftover->count(), $remaining);
            array_push($selected, ...$leftover->take($take)->all());
            $remaining -= $take;
        }

        return collect($selected)->map(function ($item) {
            return $item instanceof \Illuminate\Database\Eloquent\Model ? $item->toArray() : $item;
        })->values();
    }

    public function getStats(?string $jobName = null): array
    {
        // GROUP BY order matches index (current_state, job_name) → index-only scan
        $query = Distributions::select(
                Distributions::COL_DISTRIBUTION_JOB_NAME . ' as group_key',
                Distributions::COL_DISTRIBUTION_CURRENT_STATE . ' as state',
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy(Distributions::COL_DISTRIBUTION_CURRENT_STATE, Distributions::COL_DISTRIBUTION_JOB_NAME);

        if ($jobName) {
            $query->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName);
        }

        return $this->aggregateStats($query->get(), 'job');
    }

    public function getStatsByRequestId(string $jobName): array
    {
        $rows = Distributions::where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName)
            ->select(
                Distributions::COL_DISTRIBUTION_REQUEST_ID . ' as group_key',
                Distributions::COL_DISTRIBUTION_CURRENT_STATE . ' as state',
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID, Distributions::COL_DISTRIBUTION_CURRENT_STATE)
            ->get();

        return $this->aggregateStats($rows, 'request_id');
    }

    private function aggregateStats(Collection $rows, string $label): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->group_key][$row->state] = $row->cnt;
        }

        $result = [];
        foreach ($grouped as $key => $stateCounts) {
            $row = [$label => $key];
            $total = 0;
            foreach (DistributionStates::ALL_STATES as $state) {
                $count = $stateCounts[$state] ?? 0;
                $row[$state] = $count;
                $total += $count;
            }
            $row['total'] = $total;
            $result[] = $row;
        }

        return $result;
    }

    public function getRecentFailures(?string $jobName = null, int $limit = 20): array
    {
        $query = Distributions::where(
            Distributions::COL_DISTRIBUTION_CURRENT_STATE,
            DistributionStates::DISTRIBUTION_STATES_FAILED
        );

        if ($jobName) {
            $query->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName);
        }

        return $query->with(['latestState'])
            ->orderByDesc(Distributions::COL_DISTRIBUTION_UPDATED_AT)
            ->limit($limit)
            ->get()
            ->map(function ($dist) {
                return [
                    'id' => $dist->{Distributions::COL_DISTRIBUTION_ID},
                    'request_id' => $dist->{Distributions::COL_DISTRIBUTION_REQUEST_ID},
                    'job' => $dist->{Distributions::COL_DISTRIBUTION_JOB_NAME},
                    'tries' => $dist->{Distributions::COL_DISTRIBUTION_TRIES},
                    'error' => $dist->latestState?->{DistributionStates::COL_DISTRIBUTION_STATE_LOG},
                    'failed_at' => $dist->latestState?->{DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT},
                ];
            })
            ->toArray();
    }

    public function getActiveJobNames(): array
    {
        return Distributions::whereIn(
                Distributions::COL_DISTRIBUTION_CURRENT_STATE,
                DistributionStates::ACTIVE_STATES
            )
            ->distinct()
            ->pluck(Distributions::COL_DISTRIBUTION_JOB_NAME)
            ->toArray();
    }

    public function initDistributionData($distributions)
    {
        if (empty($distributions)) {
            return Response::make("Distribution data initialization successful", 200);
        }

        $now = now();
        array_walk($distributions, function (&$subArray) use ($now) {
            $subArray[Distributions::COL_DISTRIBUTION_CREATED_AT] = $now;
            $subArray[Distributions::COL_DISTRIBUTION_CURRENT_STATE] = DistributionStates::DISTRIBUTION_STATES_INIT;
        });

        try {
            return DB::transaction(function () use ($distributions, $now) {
                // Batch insert distributions in chunks (1 query per 500 instead of N queries)
                $allIds = [];
                foreach (array_chunk($distributions, 500) as $chunk) {
                    Distributions::insert($chunk);

                    // Retrieve inserted IDs — last N auto-increment IDs
                    $lastId = DB::getPdo()->lastInsertId();
                    $count = count($chunk);
                    for ($i = 0; $i < $count; $i++) {
                        $allIds[] = $lastId + $i;
                    }
                }

                // Batch insert all states
                if (!empty($allIds)) {
                    $states = array_map(function ($id) use ($now) {
                        return [
                            DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
                            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_INIT,
                            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
                        ];
                    }, $allIds);

                    foreach (array_chunk($states, 500) as $chunk) {
                        DistributionStates::insert($chunk);
                    }
                }

                return Response::make("Distribution data initialization successful", 200);
            });
        } catch (\Exception $e) {
            return Response::make("Distribution data initialization failed" . $e->getMessage(), 400);
        }
    }
}
