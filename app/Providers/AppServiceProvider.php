<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // デフォルトの接続を強制的に設定
        DB::purge('mysql');
        config(['database.default' => 'pgsql']);
        DB::reconnect();
    }
}
