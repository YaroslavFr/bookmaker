<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    app(\App\Http\Controllers\BetController::class)->autoSettleDue(new \Illuminate\Http\Request());
})->everyMinute()->name('cron:autoSettleDue')->withoutOverlapping();

Schedule::command('leagues:sync-upcoming --limit=15 --year='.date('Y'))
    ->everyMinute()
    ->name('cron:leaguesSync')
    ->withoutOverlapping();
