<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Toggle sync via env SYNC_ENABLED (default: disabled for temporary pause)
        if (env('SYNC_ENABLED', false)) {
            // Sync EPL results every 10 minutes
            $schedule->command('epl:sync-results')->everyTenMinutes();
            // Optional: odds sync daily
            $schedule->command('epl:sync-odds --limit=10')->dailyAt('06:00');
        }

        // Мультилиговая синхронизация предстоящих матчей каждые 5 минут
        $schedule->command('leagues:sync-upcoming --limit=15')->everyFiveMinutes()->withoutOverlapping()->name('leagues:sync-upcoming');

        // Daily refresh for stats cache
        $schedule->command('stats:refresh')->dailyAt('03:30');

        if (env('SETTLE_ENABLED', true)) {
            $schedule->call(function() {
                app(\App\Http\Controllers\BetController::class)->autoSettleDue();
            })->everyTwoMinutes()->name('bets:auto-settle')->withoutOverlapping();
            $schedule->call(function() {
                app(\App\Http\Controllers\BetController::class)->processDueScheduled100();
            })->everyMinute()->name('events:process-due')->withoutOverlapping();
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}