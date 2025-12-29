<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * These schedules are run in a single process, so avoid doing any heavy processing
     * inside the schedule. Instead, dispatch jobs to be run in the background using
     * the "command" line of the schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // 日本映画データ取得 (1日4回: 3時、9時、15時、21時)
        $schedule->command('movies:fetch-japanese-boxoffice')
                ->cron('0 3,9,15,21 * * *')
                ->withoutOverlapping();

        // グローバル映画データ取得 (1日4回: 3時15分、9時15分、15時15分、21時15分)
        $schedule->command('movies:fetch-global-boxoffice')
                ->cron('15 3,9,15,21 * * *')
                ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * The Artisan commands provided by your application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 