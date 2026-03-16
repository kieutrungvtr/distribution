<?php

namespace PLSys\DistrbutionQueue\App\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PLSys\DistrbutionQueue\App\Http\Requests\DistributionRequest;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;

class PushingService
{
    private $distributionRepository;
    private DistributionCache $cache;

    /**
     * Flag support process exception case: break internet, sever die,...
     */
    private $backLogFlag = false;

    private $optionRequestId = null;

    private $optionSync = false;

    public function backlogFlag($value)
    {
        $this->backLogFlag = $value;
    }

    public function optionRequestId($value)
    {
        $this->optionRequestId = $value;
    }

    public function optionSync($value)
    {
        $this->optionSync = $value;
    }

    private function queueName($jobName)
    {
        $queueName = substr(trim($jobName), 0, -3);
        return Str::snake($queueName);
    }

    /**
     * Create a new class instance.
     */
    public function __construct(DistributionRepository $distributionRepository = null, DistributionCache $cache = null)
    {
        $this->distributionRepository = $distributionRepository ?? new DistributionRepository();
        $this->cache = $cache ?? app(DistributionCache::class);
    }

    /**
     * @param  \App\Http\Requests\DistributionRequest  $request
     */
    public function init(DistributionRequest $request)
    {
        $validator = Validator::make($request->all(), $request->rules());
        if ($validator->fails()) {
            return $validator->messages()->toJson();
        }
        $arrDistributions = $request->input('distribution_request');
        $response = $this->distributionRepository->initDistributionData($arrDistributions);

        // Invalidate cache when new distributions are created
        $this->cache->invalidateActiveJobNames();

        return $response;
    }

    public function pre($id)
    {
        $state = DistributionStates::create([
            DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => now()
        ]);

        // Write-through: update current_state
        Distributions::where(Distributions::COL_DISTRIBUTION_ID, $id)
            ->update([
                Distributions::COL_DISTRIBUTION_CURRENT_STATE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                Distributions::COL_DISTRIBUTION_UPDATED_AT => now(),
            ]);

        return $state;
    }

    public function post($id, $status, $log = null)
    {
        // Read previous state BEFORE updating (for accurate cache decrement)
        $dist = Distributions::find($id);
        $previousState = $dist ? $dist->{Distributions::COL_DISTRIBUTION_CURRENT_STATE} : null;

        $now = now();
        $state = DistributionStates::create([
            DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
            DistributionStates::COL_DISTRIBUTION_STATE_VALUE => $status,
            DistributionStates::COL_DISTRIBUTION_STATE_LOG => $log,
            DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => $now,
        ]);

        // Write-through: update current_state and updated_at
        Distributions::where(Distributions::COL_DISTRIBUTION_ID, $id)
            ->update([
                Distributions::COL_DISTRIBUTION_CURRENT_STATE => $status,
                Distributions::COL_DISTRIBUTION_UPDATED_AT => $now,
            ]);

        // Decrement pushed count ONLY when leaving the pushed state
        // (prevents double-decrement on pushed→processing→completed chain)
        if ($previousState === DistributionStates::DISTRIBUTION_STATES_PUSHED
            && $status !== DistributionStates::DISTRIBUTION_STATES_PUSHED
            && $dist) {
            $this->cache->decrementPushedCount($dist->{Distributions::COL_DISTRIBUTION_JOB_NAME});
        }

        return $state;
    }

    /**
     * Note: Queue name will be base on job name. Ex: Job name is PullDesignJob => Queue name: pull_design.
     */
    public function process($jobName, $batch = 10, $backlogTries = 3, $backlogTimeRange = 1)
    {
        $repo = $this->distributionRepository;
        $quota = intval(config('distribution.quota'));

        if ($this->optionSync) {
            $remainingSlots = PHP_INT_MAX;
        } else {
            // Get pushed count (initializes Redis key from DB if needed)
            $itemPushed = $this->cache->getPushedCount($jobName, function () use ($repo, $jobName) {
                return $repo->countByStatus(DistributionStates::DISTRIBUTION_STATES_PUSHED, $jobName);
            });

            if ($itemPushed >= $quota) {
                return Response::make("Job $jobName over quota $quota", 406);
            }

            // Atomic reservation: prevents race condition between multiple workers
            $remainingSlots = $this->cache->reserveQuota($jobName, $quota - $itemPushed, $quota);
            if ($remainingSlots <= 0) {
                return Response::make("Job $jobName over quota $quota", 406);
            }
        }

        if ($this->backLogFlag) {
            $distributions = $repo->searchBackLogAndLock(
                $jobName, $this->optionRequestId, $backlogTries, $backlogTimeRange, $remainingSlots
            );
        } else {
            $distributions = $repo->searchAndLock(
                $jobName, $this->optionRequestId, $remainingSlots
            );
        }

        $actualLocked = count($distributions);

        // Release unused quota slots (reserved but not locked)
        if (!$this->optionSync && $actualLocked < $remainingSlots) {
            $this->cache->releaseQuota($jobName, $remainingSlots - $actualLocked);
        }

        if ($actualLocked == 0) {
            return Response::make("Have not request to be process", 406);
        }

        $countRequest = 0;
        $backlogIds = [];
        $failedCount = 0;

        foreach ($distributions as $key => $distribution) {
            $distribution = (array)$distribution;
            try {
                $jobNamespace = config('distribution.job_namespace', '\\App\\Jobs\\');
                $jobInstance = rtrim($jobNamespace, '\\') . '\\' . $jobName;
                $jobs = new $jobInstance($distribution);
                if ($this->optionSync) {
                    dispatch_sync($jobs);
                } else {
                    Queue::pushOn($this->queueName($jobName), $jobs);
                }
                $countRequest++;

                if ($this->backLogFlag) {
                    $backlogIds[] = $distribution[Distributions::COL_DISTRIBUTION_ID];
                }
            } catch (\Throwable $e) {
                $this->post(
                    $distribution[Distributions::COL_DISTRIBUTION_ID],
                    DistributionStates::DISTRIBUTION_STATES_FAILED,
                    $e->getMessage()
                );
                $failedCount++;
            }
        }

        // Batch tries update instead of N individual updates
        if (!empty($backlogIds)) {
            Distributions::whereIn(Distributions::COL_DISTRIBUTION_ID, $backlogIds)
                ->increment(Distributions::COL_DISTRIBUTION_TRIES);
        }

        if ($countRequest == 0) {
            return Response::make("All dispatches failed", 503);
        }

        return Response::make("$countRequest request pushed", 200);
    }
}
