<?php

return [
    'batch' => env('HORIZON_QUEUE_LIMIT_PER_BATCH', 10),
    'unique_for' => env('QUEUE_UNIQUE_FOR', 3600),
    'quota' => env('DISTRIBUTION_QUEUE_QUOTA', 10),

    'job_namespace' => env('DISTRIBUTION_JOB_NAMESPACE', '\\App\\Jobs\\'),

    'worker' => [
        'sleep' => env('DISTRIBUTION_WORKER_SLEEP', 5),
        'tries' => env('DISTRIBUTION_BACKLOG_TRIES', 3),
        'range' => env('DISTRIBUTION_BACKLOG_RANGE', 1),
        'auto_retry' => env('DISTRIBUTION_AUTO_RETRY', false),
        'memory_limit' => env('DISTRIBUTION_WORKER_MEMORY_LIMIT', 128), // MB
    ],

    'monitor' => [
        'enabled' => env('DISTRIBUTION_MONITOR_ENABLED', true),
        'prefix' => env('DISTRIBUTION_MONITOR_PREFIX', 'distribution-monitor'),
        'middleware' => [],
    ],

    'cache' => [
        'enabled' => env('DISTRIBUTION_CACHE_ENABLED', false),
        'connection' => env('DISTRIBUTION_CACHE_CONNECTION', 'default'),
        'prefix' => env('DISTRIBUTION_CACHE_PREFIX', 'dist'),
    ],

    'supervisor' => [
        'enabled' => env('DISTRIBUTION_SUPERVISOR_ENABLED', false),
        'workers' => env('DISTRIBUTION_SUPERVISOR_WORKERS', 3),
        'rebalance_interval' => env('DISTRIBUTION_REBALANCE_INTERVAL', 10),
    ],
];
