<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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
        // 生成するURLを正規ドメインに固定し、常にHTTPSを使用
        if (config('app.url')) {
            URL::forceRootUrl(rtrim(config('app.url'), '/'));
        }
        if (app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }
    }
}
