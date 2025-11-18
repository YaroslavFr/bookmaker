<?php

$providers = [
    App\Providers\AppServiceProvider::class,
];

if (class_exists(Barryvdh\Debugbar\ServiceProvider::class)) {
    $providers[] = Barryvdh\Debugbar\ServiceProvider::class;
}

return $providers;
