<?php

namespace PLSys\DistrbutionQueue\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PLSys\DistrbutionQueue\App\Repositories\Sql\DistributionRepository;

class DistributionMonitorController extends Controller
{
    private DistributionRepository $repo;

    public function __construct(DistributionRepository $repo)
    {
        $this->repo = $repo;
    }

    public function stats(Request $request): JsonResponse
    {
        $jobName = $request->query('job');

        return response()->json([
            'quota' => (int) config('distribution.quota'),
            'jobs' => $this->repo->getStats($jobName),
        ]);
    }

    public function detail(Request $request, string $jobName): JsonResponse
    {
        return response()->json([
            'job' => $jobName,
            'requests' => $this->repo->getStatsByRequestId($jobName),
        ]);
    }

    public function failures(Request $request): JsonResponse
    {
        $jobName = $request->query('job');
        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'failures' => $this->repo->getRecentFailures($jobName, $limit),
        ]);
    }
}
