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
        $where = [
            Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
            Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_INIT,
        ];
        if ($requestId) {
            $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
        }
        $data = Distributions::where($where)
        ->orderBy(Distributions::COL_DISTRIBUTION_PRIORITY, 'DESC')
        ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
        ->select(
            Distributions::COL_DISTRIBUTION_ID,
            Distributions::COL_DISTRIBUTION_REQUEST_ID,
            Distributions::COL_DISTRIBUTION_PAYLOAD,
            Distributions::COL_DISTRIBUTION_TRIES,
            Distributions::COL_DISTRIBUTION_JOB_NAME,
            Distributions::COL_DISTRIBUTION_PRIORITY,
        )
        ->when($globalLimit < PHP_INT_MAX / 10, function ($q) use ($globalLimit) {
            $q->limit(min($globalLimit * 10, 10000));
        })
        ->get();

        return $this->fairDistribute($data, $globalLimit)->toArray();
    }

    public function searchAndLock($jobName, $requestId = null, $globalLimit = PHP_INT_MAX)
    {
        return DB::transaction(function () use ($jobName, $requestId, $globalLimit) {
            $where = [
                Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
                Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_INIT,
            ];
            if ($requestId) {
                $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
            }

            // Step 1: SELECT without lock to get candidate IDs
            $candidates = Distributions::where($where)
            ->orderBy(Distributions::COL_DISTRIBUTION_PRIORITY, 'DESC')
            ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
            ->select(
                Distributions::COL_DISTRIBUTION_ID,
                Distributions::COL_DISTRIBUTION_REQUEST_ID,
                Distributions::COL_DISTRIBUTION_PAYLOAD,
                Distributions::COL_DISTRIBUTION_TRIES,
                Distributions::COL_DISTRIBUTION_JOB_NAME,
                Distributions::COL_DISTRIBUTION_PRIORITY,
            )
            ->when($globalLimit < PHP_INT_MAX / 10, function ($q) use ($globalLimit) {
                $q->limit(min($globalLimit * 10, 10000));
            })
            ->get();

            // Step 2: fairDistribute to pick the batch
            $distributed = $this->fairDistribute($candidates, $globalLimit);

            if ($distributed->isEmpty()) {
                return [];
            }

            // Step 3: Lock only the selected batch rows
            // SKIP LOCKED (MySQL 8.0+/PostgreSQL) prevents lock-wait cascading
            $ids = $distributed->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            $driver = DB::connection()->getDriverName();
            $lockQuery = Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $ids)
                ->where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, DistributionStates::DISTRIBUTION_STATES_INIT);

            if ($driver === 'sqlite') {
                $locked = $lockQuery->lockForUpdate()
                    ->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            } else {
                $locked = $lockQuery->lock('FOR UPDATE SKIP LOCKED')
                    ->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            }

            // Filter to only those still in 'initial' state
            $distributed = $distributed->filter(function ($item) use ($locked) {
                return in_array($item[Distributions::COL_DISTRIBUTION_ID], $locked);
            })->values();

            if ($distributed->isNotEmpty()) {
                $now = now();
                $states = $distributed->map(function ($item) use ($now) {
                    return [
                        DistributionStates::COL_FK_DISTRIBUTION_ID => $item[Distributions::COL_DISTRIBUTION_ID],
                        DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                        DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
                    ];
                })->toArray();
                DistributionStates::insert($states);

                // Write-through: update distribution_current_state + updated_at
                Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $locked)
                    ->update([
                        Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                        Distributions::COL_DISTRIBUTION_UPDATED_AT => $now,
                    ]);
            }

            return $distributed->toArray();
        }, 3); // Retry up to 3 times on deadlock
    }

    public function searchBackLog($jobName, $requestId = null, $tries = 3, $timeRange = 1, $globalLimit = PHP_INT_MAX)
    {
        $where = [
            Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
            Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_FAILED,
        ];
        if ($requestId) {
            $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
        }

        $hourAgo = Carbon::now()->subHours($timeRange);
        $data = Distributions::where($where)
        ->where(Distributions::COL_DISTRIBUTION_TRIES, '<', $tries)
        ->where(Distributions::COL_DISTRIBUTION_UPDATED_AT, '<', $hourAgo)
        ->orderBy(Distributions::COL_DISTRIBUTION_PRIORITY, 'DESC')
        ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
        ->select(
            Distributions::COL_DISTRIBUTION_ID,
            Distributions::COL_DISTRIBUTION_REQUEST_ID,
            Distributions::COL_DISTRIBUTION_PAYLOAD,
            Distributions::COL_DISTRIBUTION_TRIES,
            Distributions::COL_DISTRIBUTION_JOB_NAME,
            Distributions::COL_DISTRIBUTION_PRIORITY,
        )
        ->when($globalLimit < PHP_INT_MAX / 10, function ($q) use ($globalLimit) {
            $q->limit(min($globalLimit * 10, 10000));
        })
        ->get();

        return $this->fairDistribute($data, $globalLimit)->toArray();
    }

    public function searchBackLogAndLock($jobName, $requestId = null, $tries = 3, $timeRange = 1, $globalLimit = PHP_INT_MAX)
    {
        return DB::transaction(function () use ($jobName, $requestId, $tries, $timeRange, $globalLimit) {
            $where = [
                Distributions::COL_DISTRIBUTION_JOB_NAME => $jobName,
                Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_FAILED,
            ];
            if ($requestId) {
                $where[Distributions::COL_DISTRIBUTION_REQUEST_ID] = $requestId;
            }

            $hourAgo = Carbon::now()->subHours($timeRange);

            // Step 1: SELECT without lock
            $candidates = Distributions::where($where)
            ->where(Distributions::COL_DISTRIBUTION_TRIES, '<', $tries)
            ->where(Distributions::COL_DISTRIBUTION_UPDATED_AT, '<', $hourAgo)
            ->orderBy(Distributions::COL_DISTRIBUTION_PRIORITY, 'DESC')
            ->orderBy(Distributions::COL_DISTRIBUTION_CREATED_AT, 'ASC')
            ->select(
                Distributions::COL_DISTRIBUTION_ID,
                Distributions::COL_DISTRIBUTION_REQUEST_ID,
                Distributions::COL_DISTRIBUTION_PAYLOAD,
                Distributions::COL_DISTRIBUTION_TRIES,
                Distributions::COL_DISTRIBUTION_JOB_NAME,
                Distributions::COL_DISTRIBUTION_PRIORITY,
            )
            ->when($globalLimit < PHP_INT_MAX / 10, function ($q) use ($globalLimit) {
                $q->limit(min($globalLimit * 10, 10000));
            })
            ->get();

            // Step 2: fairDistribute
            $distributed = $this->fairDistribute($candidates, $globalLimit);

            if ($distributed->isEmpty()) {
                return [];
            }

            // Step 3: Lock only the batch rows
            // SKIP LOCKED (MySQL 8.0+/PostgreSQL) prevents lock-wait cascading
            $ids = $distributed->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            $driver = DB::connection()->getDriverName();
            $lockQuery = Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $ids)
                ->where(Distributions::COL_DISTRIBUTION_CURRENT_STATE, DistributionStates::DISTRIBUTION_STATES_FAILED);

            if ($driver === 'sqlite') {
                $locked = $lockQuery->lockForUpdate()
                    ->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            } else {
                $locked = $lockQuery->lock('FOR UPDATE SKIP LOCKED')
                    ->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();
            }

            $distributed = $distributed->filter(function ($item) use ($locked) {
                return in_array($item[Distributions::COL_DISTRIBUTION_ID], $locked);
            })->values();

            if ($distributed->isNotEmpty()) {
                $now = now();
                $states = $distributed->map(function ($item) use ($now) {
                    return [
                        DistributionStates::COL_FK_DISTRIBUTION_ID => $item[Distributions::COL_DISTRIBUTION_ID],
                        DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                        DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
                    ];
                })->toArray();
                DistributionStates::insert($states);

                // Write-through: update distribution_current_state + updated_at
                Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $locked)
                    ->update([
                        Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                        Distributions::COL_DISTRIBUTION_UPDATED_AT => $now,
                    ]);
            }

            return $distributed->toArray();
        }, 3); // Retry up to 3 times on deadlock
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

        $remaining = $globalLimit;
        $result = collect();

        // Pass 1: fair allocation — each group gets ceil(remaining / groupsLeft)
        $groupsLeft = $groups->count();
        $groupRemainders = [];

        foreach ($groups as $requestId => $group) {
            $slotsPerGroup = $remaining >= PHP_INT_MAX
                ? $group->count()
                : (int) ceil($remaining / max($groupsLeft, 1));
            $take = (int) min($slotsPerGroup, $group->count(), $remaining);
            $result = $result->concat($group->take($take));
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
        if ($remaining > 0 && !empty($groupRemainders)) {
            foreach ($groupRemainders as $leftover) {
                if ($remaining <= 0) {
                    break;
                }
                $take = (int) min($leftover->count(), $remaining);
                $result = $result->concat($leftover->take($take));
                $remaining -= $take;
            }
        }

        return $result->map(function ($item) {
            return $item instanceof \Illuminate\Database\Eloquent\Model ? $item->toArray() : $item;
        })->values();
    }

    public function getStats(string $jobName = null): array
    {
        $query = Distributions::select(
                Distributions::COL_DISTRIBUTION_JOB_NAME . ' as job',
                Distributions::COL_DISTRIBUTION_CURRENT_STATE . ' as state',
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy(Distributions::COL_DISTRIBUTION_JOB_NAME, Distributions::COL_DISTRIBUTION_CURRENT_STATE);

        if ($jobName) {
            $query->where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName);
        }

        $rows = $query->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->job][$row->state] = $row->cnt;
        }

        $result = [];
        foreach ($grouped as $job => $stateCounts) {
            $row = ['job' => $job];
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

    public function getStatsByRequestId(string $jobName): array
    {
        $rows = Distributions::where(Distributions::COL_DISTRIBUTION_JOB_NAME, $jobName)
            ->select(
                Distributions::COL_DISTRIBUTION_REQUEST_ID . ' as request_id',
                Distributions::COL_DISTRIBUTION_CURRENT_STATE . ' as state',
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy(Distributions::COL_DISTRIBUTION_REQUEST_ID, Distributions::COL_DISTRIBUTION_CURRENT_STATE)
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->request_id][$row->state] = $row->cnt;
        }

        $result = [];
        foreach ($grouped as $requestId => $stateCounts) {
            $row = ['request_id' => $requestId];
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

    public function getRecentFailures(string $jobName = null, int $limit = 20): array
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
                $ids = [];
                foreach ($distributions as $distribution) {
                    $insertResponse = Distributions::create($distribution);
                    if ($insertResponse) {
                        $ids[] = $insertResponse->{Distributions::COL_DISTRIBUTION_ID};
                    }
                }

                // Batch insert all states at once (1 query instead of N)
                if (!empty($ids)) {
                    $states = array_map(function ($id) use ($now) {
                        return [
                            DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
                            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_INIT,
                            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
                        ];
                    }, $ids);

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
