<?php

use Illuminate\Support\Facades\Route;
use PLSys\DistrbutionQueue\App\Http\Controllers\DistributionMonitorController;

$prefix = config('distribution.monitor.prefix', 'distribution-monitor');
$middleware = config('distribution.monitor.middleware', []);

Route::prefix($prefix)->middleware($middleware)->group(function () {
    Route::get('/stats', [DistributionMonitorController::class, 'stats']);
    Route::get('/stats/{jobName}', [DistributionMonitorController::class, 'detail']);
    Route::get('/failures', [DistributionMonitorController::class, 'failures']);
});
