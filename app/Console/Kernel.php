<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
        // 毎日午前3時に実行
        $schedule->command('movies:fetch-japanese-boxoffice')
                ->dailyAt('03:00')
                ->withoutOverlapping();

        // グローバル映画データの取得を追加
        $schedule->command('movies:fetch-global-boxoffice')
                ->dailyAt('03:00')
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