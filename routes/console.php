<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// 毎日午前2:30に日本の映画データを取得
Artisan::command('schedule:japanese-movies', function () {
    $this->call('movies:fetch-japanese-boxoffice');
})->purpose('Fetch Japanese box office data')->dailyAt('02:30');

// 毎日午前3:30にグローバル映画データを取得
Artisan::command('schedule:global-movies', function () {
    $this->call('movies:fetch-global-boxoffice');
})->purpose('Fetch global box office data')->dailyAt('03:30');
