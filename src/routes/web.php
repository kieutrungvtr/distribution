<?php

use App\Jobs\PullDesignJob;
use App\Repositories\Sql\DataPushingRepository;
use App\Services\PushingService;
use Illuminate\Support\Facades\Route;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pull-desigin', function () {
    $batch = 2;
    $pushingService = new PushingService($batch);
    //$pushingService->process();

}); 