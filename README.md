Distribution Queue (Rabbitmq, Horizon) for Laravel
======================

## Installation

You can install this package via composer using this command:

```
composer require plsys/distrbution-queue
```

The package will automatically register itself.

### Un-official install
1. Add repo:
```
"repositories": [
  {
    "type": "git",
    "url": "https://github.com/kieutrungvtr/distribution.git"
  }
]
```
2. Add require:
```
"plsys/distrbution-queue": "dev-master"
```
4. Run composer:
```
composer require plsys/distrbution-queue
```

### Configuration

Config to `config/distribution.php`:

> This is the minimal config for the Distribution queue to work.

Add service provider: `config/app.php`
```
PLSys\DistrbutionQueue\DistributionServiceProvider::class
```

Vendor publish:

```
php artisan vendor:publish --provider="PLSys\DistrbutionQueue\DistributionServiceProvider" --tag=views
```

```
php artisan vendor:publish --provider="PLSys\DistrbutionQueue\DistributionServiceProvider" --tag=migrations
```

> Migration and template for the Distribution queue to work.


### Use your own Distribution Lib
1. Create tables for the Distribution queue:
```
php artisan migrate
```
2. Create `DistributionPullDesignProvideDataCommand` class and `PullDesignJob` class.
> Note: Queue name will be base on job name. Ex: Job name is PullDesignJob => Queue name: pull_design.
```
php artisan distribution:create-job PullDesign
```
3. Put your logic code into the predefined file.


### Horizon support

Starting with 8.0, this package supports [Laravel Horizon](https://laravel.com/docs/horizon) out of the box. Firstly,
install Horizon and then set `RABBITMQ_WORKER` to `horizon`.



```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        /* Set to "horizon" if you wish to use Laravel Horizon. */
       'worker' => env('RABBITMQ_WORKER', 'default'),
    ],

    // ...    
],
```
Config:
```
'defaults' => [
        'supervisor-pull-design' => [
            'connection' => 'rabbitmq',
            'queue' => 'pull_design',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => env('HORIZON_WORKER_MAX_PROCESS', 10),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => env('HORIZON_WORKER_TRIES', 3),
            'timeout' => env('HORIZON_WORKER_TIME_OUT', 240),
            'nice' => 0,
        ],
        'supervisor-pull-product' => [
            'connection' => 'rabbitmq',
            'queue' => 'pull_product',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => env('HORIZON_WORKER_MAX_PROCESS', 10),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => env('HORIZON_WORKER_TRIES', 3),
            'timeout' => env('HORIZON_WORKER_TIME_OUT', 240),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-pull-design' => [
                'minProcesses' => env('HORIZON_WORKER_MIN_PROCESS', 1),
                'maxProcesses' => env('HORIZON_WORKER_MAX_PROCESS', 10),
            ]
        ],

        'local' => [
            'supervisor-pull-design' => [
                'minProcesses' => env('HORIZON_WORKER_MIN_PROCESS', 1),
                'maxProcesses' => env('HORIZON_WORKER_MAX_PROCESS', 10),
            ],
            'supervisor-pull-product' => [
                'minProcesses' => env('HORIZON_WORKER_MIN_PROCESS', 1),
                'maxProcesses' => env('HORIZON_WORKER_MAX_PROCESS', 10),
            ]
        ],
    ],
```
