<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 日本映画データ取得 (1日4回: 3時、9時、15時、21時)
Schedule::command('movies:fetch-japanese-boxoffice')
    ->cron('0 3,9,15,21 * * *')
    ->withoutOverlapping();

// グローバル映画データ取得 (1日4回: 3時15分、9時15分、15時15分、21時15分)
Schedule::command('movies:fetch-global-boxoffice')
    ->cron('15 3,9,15,21 * * *')
    ->withoutOverlapping();
