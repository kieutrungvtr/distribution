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
