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

        // Daily refresh for stats cache
        $schedule->command('stats:refresh')->dailyAt('03:30');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}