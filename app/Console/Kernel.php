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
        // 毎日午前2時30分に日本の映画データを取得
        $schedule->command('movies:fetch-japanese-boxoffice')
                ->dailyAt('02:30')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/japanese-boxoffice.log'));

        // 毎日午前3時30分にグローバル映画データを取得
        $schedule->command('movies:fetch-global-boxoffice')
                ->dailyAt('03:30')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/global-boxoffice.log'));
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