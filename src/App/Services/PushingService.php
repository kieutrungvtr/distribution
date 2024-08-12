<?php

namespace PLSys\DistrbutionQueue\App\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PLSys\DistrbutionQueue\App\Http\Requests\DistributionRequest;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionStatesRepository;

class PushingService
{
    private $distributionRepository;

    private $distributionStateRepository;

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
    public function __construct()
    {
        $this->distributionRepository = new DistributionRepository();
        $this->distributionStateRepository = new DistributionStatesRepository();
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
        return $this->distributionRepository->initDistributionData($arrDistributions);
    }

    public function pre($id)
    {
        return $this->distributionStateRepository->create(
            [
                DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
                DistributionStates::COL_DISTRIBUTION_STATE_VALUE => DistributionStates::DISTRIBUTION_STATES_PUSHED,
                DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => now()
            ]
        ); 
         
    }

    public function post($id, $status, $log = null)
    {
        return $this->distributionStateRepository->create(
            [
                DistributionStates::COL_FK_DISTRIBUTION_ID => $id,
                DistributionStates::COL_DISTRIBUTION_STATE_VALUE => $status,
                DistributionStates::COL_DISTRIBUTION_STATE_LOG => $log,
                DistributionStates::COL_DISTRIBUTION_STATE_CREATED_AT => now(),
            ]
        ); 
    }

    /**
     * Note: Queue name will be base on job name. Ex: Job name is PullDesignJob => Queue name: pull_design.
     */
    public function process($jobName, $batch = 10, $backlogTries = 3, $backlogTimeRange = 1)
    {
        $itemPushed = $this->distributionRepository->countByStatus(
            DistributionStates::DISTRIBUTION_STATES_PUSHED
        );
        $quota = intval(config('distribution.quota'));
        if ($itemPushed >= $quota) {
            return Response::make("Over quota $quota", 406);
        }
        if ($this->backLogFlag) {
            $dataGroupById = $this->distributionRepository->searchBackLog(
                $jobName, $this->optionRequestId, $backlogTries, $backlogTimeRange, $batch
            );
        } else {
            $dataGroupById = $this->distributionRepository->search($jobName, $this->optionRequestId, $batch);
        }
        $distributions = Arr::flatten($dataGroupById->toArray(), 1);
        if (count($distributions) == 0) {
            return Response::make("Have not request to be process", 406);
        }
        try {
            foreach ($distributions as $key => $distribution) {
                $distribution = (array)$distribution;
                $countRequest = $key + 1;
                $jobInstance = "\\App\\Jobs\\$jobName";
                $jobs = new $jobInstance($distribution);
                if ($this->optionSync) {
                    dispatch_sync($jobs);
                } else {
                    Queue::pushOn($this->queueName($jobName), $jobs);
                }
                $this->pre($distribution[Distributions::COL_DISTRIBUTION_ID]);
                if ($this->backLogFlag) {
                    $currTries = $distribution[Distributions::COL_DISTRIBUTION_TRIES];
                    $this->distributionRepository->update(
                        $distribution[Distributions::COL_DISTRIBUTION_ID],
                        [
                            Distributions::COL_DISTRIBUTION_TRIES =>  $currTries + 1
                        ]
                    );
                }
            }
            return Response::make("$countRequest request pushed", 200);
        } catch (\Exception $e) {
            return Response::make($e->getMessage(), 503);
        }
    }
}
