<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Http;

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
        if (app()->environment('production')) {
            // ローカル環境（localhostなど）での動作を妨げないよう、特定のホスト以外でHTTPSを強制
            $host = request()->getHost();
            if ($host !== 'localhost' && $host !== '127.0.0.1' && !str_ends_with($host, '.test')) {
                URL::forceScheme('https');
            }
        }

        // Wikimedia（Wikipedia / Wikidata）向けのHTTPクライアントマクロ
        // ロボットポリシー準拠の明確な User-Agent（連絡先付き）を付与
        if (!method_exists(Http::class, 'wikimedia')) {
            Http::macro('wikimedia', function () {
                $appUrl = rtrim((string) config('app.url'), '/');
                $contact = 'mailto:' . config('app.contact_email');
                $userAgent = sprintf('MovieRankingBot/1.0 (+%s/; %s)', $appUrl ?: 'https://example.com', $contact);

                return Http::timeout(30)
                    ->retry(3, 500)
                    ->withHeaders([
                        'User-Agent' => $userAgent,
                        'Accept' => 'application/json',
                    ]);
            });
        }
    }
}
