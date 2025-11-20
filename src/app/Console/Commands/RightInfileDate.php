<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;

class RightInfileDate extends Command
{
    protected $signature = 'rightInfileDate';
    protected $description = 'Append current timestamp to storage/logs/cron_test.log to verify cron execution';

    public function handle(): int
    {
        try {
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $path = storage_path('logs/cron_test.log');
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $line = $now.' rightInfileDate'.PHP_EOL;
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            $this->info('Appended: '.$line);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to write timestamp: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}