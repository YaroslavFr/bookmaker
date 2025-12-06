<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    app(\App\Http\Controllers\BetController::class)->autoSettleDue(new \Illuminate\Http\Request());
})->everyThirtyMinutes()->name('cron:autoSettleDue')->withoutOverlapping();
