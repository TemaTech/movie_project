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
        // MySQLの接続を完全に削除
        DB::purge('mysql');
        
        // PostgreSQL接続を強制的に設定
        config(['database.default' => 'pgsql']);
        
        // 接続を再確立
        DB::reconnect();
        
        // 接続情報をログに出力（デバッグ用）
        \Log::info('Database Configuration:', [
            'connection' => config('database.default'),
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => config('database.connections.pgsql.database')
        ]);
    }
}
