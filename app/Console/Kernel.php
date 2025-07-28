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
        // デバッグ用ログ
        \Log::info('Kernel schedule method called');
        
        // テスト用：シンプルなechoコマンド
        $schedule->exec('echo "Test schedule working"')
                ->everyMinute()
                ->appendOutputTo(storage_path('logs/schedule-test.log'));
        
        // テスト用：毎分実行（すぐに動作確認ができる）
        $schedule->command('movies:fetch-japanese-boxoffice')
                ->everyMinute()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/japanese-boxoffice.log'));

        // テスト用：毎分実行（すぐに動作確認ができる）
        $schedule->command('movies:fetch-global-boxoffice')
                ->everyMinute()
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