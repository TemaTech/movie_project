@extends('layouts.app')

@php
    $isJapan = request()->is('japan');
    $isGlobal = request()->is('global');
    $baseTitle = $isJapan ? '日本映画興行収入ランキング' : ($isGlobal ? '世界映画興行収入ランキング' : '最新映画興行収入ランキング');
    $fullTitle = $baseTitle . ' - 世界と日本の映画売上データ';
    $metaDesc = $isJapan
        ? '日本の映画興行収入ランキングを最新順で掲載。ジャンル別の分析や制作費との比較も可能。'
        : ($isGlobal
            ? '世界の映画興行収入ランキングを最新順で掲載。ジャンル別の分析や制作費との比較も可能。'
            : '世界と日本の映画興行収入データをリアルタイムで提供。ジャンル別の分析や制作費との比較も可能な映画統計データベース。');
@endphp
@section('title', $fullTitle)
@section('meta_description', $metaDesc)
@section('meta_keywords', '映画,興行収入,ランキング,世界,日本,最新,ボックスオフィス,映画データ,興行収入ランキング,映画興行収入,映画ランキング')
@section('canonical')
<link rel="canonical" href="{{ url()->current() }}" />
@endsection
@section('breadcrumbs')
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {"@type": "ListItem", "position": 1, "item": {"@id": "{{ rtrim(config('app.url'), '/') }}/", "name": "ホーム"}},
    {"@type": "ListItem", "position": 2, "item": {"@id": "{{ url()->current() }}", "name": "{{ $isJapan ? '日本興行収入ランキング' : ($isGlobal ? '世界興行収入ランキング' : '映画興行収入ランキング') }}"}}
  ]
}
</script>
@endsection
@section('og')
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}/">
    <meta property="og:image" content="{{ asset('images/ogp.png') }}">
@endsection
@section('twitter')
    <meta name="twitter:card" content="summary_large_image">
@endsection
@section('content')

<style>
    body {
        background: #f5f7fa;
    }

    .gradient-bg {
        background: #f5f7fa;
        min-height: 100vh;
        padding: 2rem 0;
    }

    .custom-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        margin-bottom: 2rem;
    }

    .custom-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    }

    .nav-tabs {
        border: none;
        margin-bottom: 2rem;
        display: flex;
        justify-content: center;
        width: 100%;
        gap: 1rem;
        padding: 0;
    }

    .nav-tabs .nav-item {
        flex: 1;
        max-width: none;
    }

    .nav-tabs .nav-link {
        border: none;
        color: #6c757d;
        border-radius: 12px;
        padding: 0.75rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: white;
        position: relative;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .nav-tabs .nav-link:hover {
        background: #f8f9fa;
        transform: translateY(-2px);
        color: #2c3e50;
    }

    .nav-tabs .nav-link.active {
        background: #2c3e50;
        color: white;
        box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
    }

    .nav-tabs .nav-link .emoji {
        font-size: 1.2em;
    }

    .nav-tabs .nav-link::after {
        display: none;
    }

    .tab-pane {
        transition: all 0.3s ease-in-out;
    }

    .tab-pane.fade {
        opacity: 0;
        transform: translateY(10px);
    }

    .tab-pane.fade.show {
        opacity: 1;
        transform: translateY(0);
    }

    .table thead th {
        background: #2c3e50;
        color: white;
        font-weight: 500;
        padding: 1rem;
        border: none;
        white-space: nowrap;
        text-align: center;
    }

    .table tbody tr {
        transition: all 0.2s ease;
    }

    .table tbody tr:hover {
        background: #f8f9fa;
    }

    .table td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid #f1f3f5;
    }

    .table td:first-child {
        text-align: center;
        white-space: nowrap;
        width: 80px;
    }

    .table td:nth-child(2),
    .table td:nth-child(3) {
        white-space: normal;
        min-width: 180px;
        max-width: 350px;
    }

    .table td:nth-child(2) {
        min-width: 250px;
        max-width: 500px;
    }

    .table th:nth-child(4),
    .table th:nth-child(5) {
        min-width: 150px;
    }

    .table th:nth-child(6) {
        min-width: 100px;
    }

    h1 {
        color: #2c3e50;
        font-weight: 600;
        margin-bottom: 2.5rem;
        font-size: 2.2rem;
    }

    .custom-card .pagination {
        margin: 2rem 0 0;
        gap: 0.5rem;
    }

    .custom-card .page-item {
        margin: 0 2px;
    }

    .custom-card .page-link {
        color: #2c3e50;
        border: 1px solid #e9ecef;
        padding: 0.6rem 1rem;
        border-radius: 8px;
        background: #fff;
        transition: all 0.2s ease;
        font-size: 0.9rem;
        min-width: 40px;
        text-align: center;
        line-height: 1.2;
    }

    .custom-card .page-item:first-child .page-link,
    .custom-card .page-item:last-child .page-link {
        font-size: 0.8rem;
        padding: 0.6rem 0.8rem;
    }

    .custom-card .page-item:first-child .page-link::before {
        content: "←";
        font-family: system-ui;
    }

    .custom-card .page-item:last-child .page-link::before {
        content: "→";
        font-family: system-ui;
    }

    .custom-card .page-item:first-child .page-link svg,
    .custom-card .page-item:last-child .page-link svg {
        display: none;
    }

    .custom-card .page-link:hover {
        background: #f8f9fa;
        color: #2c3e50;
        border-color: #dee2e6;
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .custom-card .page-item.active .page-link {
        background-color: #2c3e50;
        border-color: #2c3e50;
        color: #fff;
        font-weight: 500;
    }

    .custom-card .page-item.disabled .page-link {
        background-color: #f8f9fa;
        border-color: #e9ecef;
        color: #6c757d;
        pointer-events: none;
    }

    .custom-card nav[role="navigation"] {
        width: 100%;
        margin: 2rem 0;
    }

    .custom-card .flex.justify-between.sm\:hidden {
        display: none;
    }

    .custom-card .hidden.sm\:flex-1.sm\:flex.sm\:items-center.sm\:justify-between {
        display: flex !important;
        flex-direction: column;
        align-items: center;
    }

    .custom-card .text-sm.text-gray-700.leading-5 {
        display: none;
    }

    .custom-card .relative.z-0.inline-flex.rtl\:flex-row-reverse {
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }

    .custom-card .relative.inline-flex.items-center,
    .custom-card span[aria-current="page"] {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        padding: 0 8px;
        margin: 0;
        font-size: 14px;
        font-weight: 500;
        color: #4a5568;
        background: #ffffff;
        border: none;
        border-right: 1px solid #e2e8f0;
        transition: all 0.2s ease;
        text-decoration: none !important;
    }

    .custom-card .relative.inline-flex.items-center a {
        text-decoration: none !important;
    }

    .custom-card .relative.inline-flex.items-center:last-child {
        border-right: none;
    }

    .custom-card .relative.inline-flex.items-center:hover:not([aria-current="page"]) {
        background: #f7fafc;
        color: #2d3748;
    }

    .custom-card span[aria-current="page"] {
        background: #3182ce;
        color: white;
        font-weight: 600;
    }

    .custom-card svg.w-5.h-5 {
        width: 16px;
        height: 16px;
        stroke-width: 3;
    }

    .custom-card .pagination-info {
        margin-top: 1rem;
        font-size: 14px;
        color: #718096;
    }

    .custom-card .relative.inline-flex.items-center[aria-disabled="true"] {
        color: #a0aec0;
        background: #f7fafc;
        cursor: not-allowed;
    }

    .custom-card a {
        text-decoration: none !important;
    }

    .table-responsive {
        background: white;
        border-radius: 12px;
        /* 横スクロールのみ許可し、縦方向はページ本体に委ねる */
        overflow-y: visible;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        touch-action: pan-x;
        margin: 0;
        height: auto;
        max-height: none;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead {
        position: sticky;
        top: 0;
        z-index: 1;
        background: #2c3e50;
    }

    .table thead th {
        background: #2c3e50;
        color: white;
        font-weight: 500;
        padding: 1rem;
        border: none;
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .table thead::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: -5px;
        height: 5px;
        background: linear-gradient(to bottom, rgba(0,0,0,0.2), transparent);
        pointer-events: none;
    }

    .table-responsive::-webkit-scrollbar {
        height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: transparent;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background-color: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
    }

    .table-responsive {
        margin: 0 -15px;
        padding: 0 15px;
        width: calc(100% + 30px);
    }

    @media (max-width: 768px) {
        /* モバイルではジャンル・制作費を非表示にし、横スクロール不要へ */
        .col-genre, .col-budget { display: none !important; }

        /* カードの白背景レイヤーを削除して余白を確保 */
        .custom-card { background: transparent; box-shadow: none; border-radius: 0; }
        .custom-card .card-body { padding: 0; }

        /* テーブルは画面幅にフィットさせ、横スクロールを抑止 */
        .table-responsive { margin: 0; padding: 0; width: 100%; overflow-x: hidden; background: transparent; border-radius: 0; }
        .table { table-layout: fixed; }
        .table thead th { padding: 8px 6px; font-size: 0.8rem; }
        .table td { padding: 8px 6px; font-size: 0.8rem; }
        .table th, .table td { min-width: 0 !important; }

        /* 列幅の最適化（合計100%） */
        .col-rank { width: 13%; white-space: nowrap; }
        .col-title { width: 49%; max-width: 100%; white-space: normal; word-break: break-word; hyphens: auto; }
        .col-boxoffice { width: 25%; white-space: nowrap; }
        .col-year { width: 13%; white-space: nowrap; }

        /* 追加情報（円換算）の小さな文字はモバイルでは非表示にして圧縮 */
        .col-boxoffice small { display: none; }

        #filterForm {
            flex-direction: column;
            gap: 10px;
        }

        #filterForm select {
            max-width: 100% !important;
            width: 100%;
        }

        #filterForm button {
            width: 100%;
        }

        .pagination {
            flex-wrap: wrap;
            justify-content: center;
            gap: 5px;
        }

        .page-link {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            min-width: 35px;
        }
    }

    .genre-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.8rem;
        transition: all 0.2s ease;
        border: none;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .genre-badge:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
    }

    .data-source-info {
        font-size: 0.8rem;
        color: #6c757d;
        padding: 1.5rem;
        margin-top: 1rem;
        border-top: 1px solid #f1f3f5;
        text-align: center;
        background: #f8f9fa;
        border-radius: 0 0 12px 12px;
    }

    .data-source-info a {
        color: #2c3e50;
        text-decoration: none;
        font-weight: 500;
    }

    .data-source-info a:hover {
        text-decoration: underline;
    }

    .badge {
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.8rem;
        transition: all 0.2s ease;
        border: none;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .badge:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
    }

    .attribution {
        background: white;
        padding: 1rem;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        margin: 2rem auto;
        width: 100%;
        text-align: center;
    }

    .attribution img {
        height: 1.2rem;
        width: auto;
        vertical-align: middle;
    }

    [data-genre="アクション"], .genre-action { background-color: #ffcccc !important; }
    [data-genre="アドベンチャー"], .genre-adventure { background-color: #ffe4b5 !important; }
    [data-genre="コメディ"], .genre-comedy { background-color: #f0e68c !important; }
    [data-genre="ドラマ"], .genre-drama { background-color: #add8e6 !important; }
    [data-genre="ロマンス"], .genre-romance { background-color: #ffb3d1 !important; }
    [data-genre="ファミリー"], .genre-family { background-color: #fff4b8 !important; }
    [data-genre="歴史"], .genre-history { background-color: #fffacd !important; }

    [data-genre="アニメ"], .genre-anime { background-color: #e0ffe0 !important; }
    [data-genre="ファンタジー"], .genre-fantasy { background-color: #e8e0ff !important; }
    [data-genre="ホラー"], .genre-horror { background-color: #d3d3d3 !important; }
    [data-genre="ミステリー"], .genre-mystery { background-color: #b2e0e0 !important; }
    [data-genre="SF"], .genre-sf { background-color: #bae1ff !important; }
    [data-genre="スリラー"], .genre-thriller { background-color: #b0e0e6 !important; }
    [data-genre="サスペンス"], .genre-suspense { background-color: #d3d3d3 !important; }

    /* サイトロゴのスタイル */
    .site-logo {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        color: #2c3e50;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s ease;
    }

    .site-logo:hover {
        transform: translateY(-2px);
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .site-logo small {
        display: block;
        font-size: 0.9rem;
        font-weight: 400;
        color: #6c757d;
        margin-top: 0.2rem;
    }

    /* Google Fontsの追加 */
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap');
</style>

<template id="genre-badge-template">
    <span class="genre-badge" data-genre=""></span>
</template>

<script>
    function createGenreBadge(genre) {
        const template = document.getElementById('genre-badge-template');
        const badge = template.content.cloneNode(true).querySelector('.genre-badge');
        badge.textContent = genre;
        badge.setAttribute('data-genre', genre);
        return badge;
    }

    function updateGenreList(genres, container) {
        container.innerHTML = '';
        genres.forEach(genre => {
            const badge = createGenreBadge(genre);
            container.appendChild(badge);
        });
    }
</script>

<div class="gradient-bg py-5">
    <div class="container">
        <h1 class="text-center mb-4">
            <a href="/" class="site-logo">
                ムビラン
                <small class="fs-5 text-muted">{{ $baseTitle }}</small>
            </a>
        </h1>
        
        <div class="mb-4">
            <div class="d-flex justify-content-center align-items-center gap-2" id="filterForm" aria-label="映画ランキングの絞り込み">
                <input type="hidden" name="tab" value="{{ request()->get('tab', 'global') }}" id="currentTab">
                <label for="genreSelect" class="visually-hidden">ジャンル</label>
                <select name="genre" class="form-select" style="max-width: 200px;" id="genreSelect" aria-label="ジャンルを選択">
                    <option value="">すべてのジャンル</option>
                    @foreach($availableGenres as $genre)
                        <option value="{{ $genre }}" {{ $selectedGenre == $genre ? 'selected' : '' }}>
                            {{ $genre }}
                        </option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-primary" id="filterButton" aria-label="選択したジャンルで絞り込む">絞り込み</button>
                <button type="button" class="btn btn-outline-secondary" id="clearFilter" style="display: none;" aria-label="絞り込みをクリア">
                    クリア
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4" id="movieTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="global-tab" data-bs-toggle="tab" data-bs-target="#global" type="button" role="tab">
                    <span class="emoji">🌏</span>
                    <span>世界興行収入</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="japan-tab" data-bs-toggle="tab" data-bs-target="#japan" type="button" role="tab">
                    <span class="emoji">🗾</span>
                    <span>日本興行収入</span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="global" role="tabpanel">
            <div class="custom-card" itemscope itemtype="https://schema.org/ItemList">
                <meta itemprop="name" content="世界映画興行収入ランキング">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center col-rank">順位</th>
                                        <th class="col-title">タイトル</th>
                                        <th class="d-none d-md-table-cell col-genre">ジャンル</th>
                                        <th class="text-end col-boxoffice">興行収入</th>
                                        <th class="text-end col-budget">制作費</th>
                                        <th class="text-center col-year">公開年</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if($globalMovies->isEmpty())
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                検索結果がありません
                                            </td>
                                        </tr>
                                    @else
                                        @foreach ($globalMovies as $movie)
                                        <tr itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                                            <meta itemprop="position" content="{{ $movie->rank }}">
                                            <td class="text-center fw-bold col-rank" itemprop="position">{{ $movie->rank }}</td>
                                            <td class="col-title">
                                                @php
                                                    $tmdbId = null;
                                                    if (isset($movie->movie_id) && is_string($movie->movie_id) && str_starts_with($movie->movie_id, 'global_')) {
                                                        $tmdbId = str_replace('global_', '', $movie->movie_id);
                                                    }
                                                @endphp
                                                @if($tmdbId)
                                                    <a href="{{ rtrim(config('app.url'), '/') }}/movies/{{ $tmdbId }}" class="text-decoration-none" itemprop="url">
                                                        <span itemprop="name">{{ $movie->title }}</span>
                                                    </a>
                                                @else
                                                    <span itemprop="name">{{ $movie->title }}</span>
                                                @endif
                                            </td>
                                            <td class="d-none d-md-table-cell col-genre">
                                                @if($movie->genres && is_array($movie->genres) && count($movie->genres) > 0)
                                                    @foreach($movie->genres as $genre)
                                                        <span class="badge" data-genre="{{ $genre }}">{{ $genre }}</span>
                                                    @endforeach
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end col-boxoffice">
                                                {{ number_format($movie->box_office / 100000000, 2) }}億ドル
                                                <small class="text-muted">({{ $movie->box_office_billion }}億円)</small>
                                            </td>
                                            <td class="text-end col-budget">
                                                {{ number_format($movie->budget / 100000000, 2) }}億ドル<br>
                                                <small class="text-muted">({{ $movie->budget_billion ? $movie->budget_billion . '億円' : '-' }})</small>
                                            </td>
                                            <td class="text-center col-year">{{ \Carbon\Carbon::parse($movie->release_date)->format('Y') }}年</td>
                                        </tr>
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <small class="text-muted">最終更新: {{ $globalLastUpdated }}</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="japan" role="tabpanel">
            <div class="custom-card" itemscope itemtype="https://schema.org/ItemList">
                <meta itemprop="name" content="日本映画興行収入ランキング">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center col-rank">順位</th>
                                        <th class="col-title">タイトル</th>
                                        <th class="d-none d-md-table-cell col-genre">ジャンル</th>
                                        <th class="text-end col-boxoffice">興行収入</th>
                                        <th class="text-end col-budget">制作費</th>
                                        <th class="text-center col-year">公開年</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if($japanMovies->isEmpty())
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                検索結果がありません
                                            </td>
                                        </tr>
                                    @else
                                        @foreach ($japanMovies as $movie)
                                        <tr itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                                            <meta itemprop="position" content="{{ $movie->rank }}">
                                            <td class="text-center fw-bold col-rank" itemprop="position">{{ $movie->rank }}</td>
                                            <td class="col-title">
                                                <span itemprop="name">{{ $movie->title }}</span>
                                            </td>
                                            <td class="d-none d-md-table-cell col-genre">
                                                @if($movie->genres && is_array($movie->genres) && count($movie->genres) > 0)
                                                    @foreach($movie->genres as $genre)
                                                        <span class="badge" data-genre="{{ $genre }}">{{ $genre }}</span>
                                                    @endforeach
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end col-boxoffice">
                                                {{ $movie->box_office_billion }}億円
                                            </td>
                                            <td class="text-end col-budget">{{ $movie->budget_billion === '0.0' || !$movie->budget_billion ? '-' : $movie->budget_billion . '億円' }}</td>
                                            <td class="text-center col-year">{{ \Carbon\Carbon::parse($movie->release_date)->format('Y') }}年</td>
                                        </tr>
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <small class="text-muted">最終更新: {{ $japanLastUpdated }}</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="attribution d-flex align-items-center justify-content-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <small class="text-muted">世界の映画データ提供:</small>
                <a href="https://www.themoviedb.org/" target="_blank" rel="noopener" class="text-decoration-none">
                    <img src="https://www.themoviedb.org/assets/2/v4/logos/v2/blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg" 
                         alt="TMDb">
                </a>
            </div>
            <div class="d-flex align-items-center gap-2">
                <small class="text-muted">日本の映画データ提供:</small>
                <a href="https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
                    Wikipedia
                </a>
            </div>
            <small class="text-muted">（金額は1ドル = 150円で換算）</small>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

<script type="application/json" id="genreColorsData">{!! json_encode($genreColors, JSON_UNESCAPED_UNICODE) !!}</script>

<script>
    const genreColorsElement = document.getElementById('genreColorsData');
    const genreColors = genreColorsElement ? JSON.parse(genreColorsElement.textContent) : {};

    document.addEventListener('DOMContentLoaded', function() {
        const filterForm = document.getElementById('filterForm');
        const filterButton = document.getElementById('filterButton');
        const clearFilter = document.getElementById('clearFilter');
        const genreSelect = document.getElementById('genreSelect');
        const currentTab = document.getElementById('currentTab');
        const movieTabs = document.getElementById('movieTabs');

        if (!filterButton) console.warn('filterButton not found');
        if (!clearFilter) console.warn('clearFilter not found');
        if (!genreSelect) console.warn('genreSelect not found');
        if (!currentTab) console.warn('currentTab not found');
        if (!movieTabs) console.warn('movieTabs not found');

        const searchCache = {
            global: {
                timestamp: null,
                data: {}
            },
            japan: {
                timestamp: null,
                data: {}
            }
        };

        // URL・パスから初期タブを決定
        const urlParams = new URLSearchParams(window.location.search);
        const path = window.location.pathname;
        let activeTab = urlParams.get('tab') || (path.startsWith('/japan') ? 'japan' : 'global');

        // タブの初期化
        const tab = document.querySelector('#global-tab');
        if (tab) {
            const tabInstance = new bootstrap.Tab(tab);
            tabInstance.show();
        }

        currentTab.value = activeTab;

        const loadingSpinner = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                </td>
            </tr>
        `;

        function updateClearButtonVisibility() {
            if (clearFilter) {
                clearFilter.style.display = genreSelect.value && genreSelect.value !== '' ? 'block' : 'none';
            }
        }

        updateClearButtonVisibility();

        genreSelect.addEventListener('change', updateClearButtonVisibility);

        function setupGenreBadgeListeners() {
            document.querySelectorAll('.badge[data-genre]').forEach(badge => {
                badge.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const genre = this.dataset.genre;
                    genreSelect.value = genre;
                    updateClearButtonVisibility();
                    updateMovieList(false);
                });
            });
        }

        function clearSearchCache() {
            searchCache.global = {
                timestamp: null,
                data: {}
            };
            searchCache.japan = {
                timestamp: null,
                data: {}
            };
        }

        setupGenreBadgeListeners();

        function getCacheKey(genre) {
            // 空文字列、null、undefined、「すべてのジャンル」を全て 'all' として扱う
            return (!genre || genre === '' || genre === 'すべてのジャンル') ? 'all' : genre;
        }

        function updateCache(tab, genre, data) {
            const cacheKey = getCacheKey(genre);
            if (!searchCache[tab]) {
                searchCache[tab] = { timestamp: null, data: {} };
            }
            searchCache[tab].timestamp = Date.now();
            searchCache[tab].data[cacheKey] = data;
        }

        if (movieTabs) {
            movieTabs.addEventListener('shown.bs.tab', function(event) {
                const activeTab = event.target.getAttribute('data-bs-target').replace('#', '');
                currentTab.value = activeTab;
                
                const genre = genreSelect.value;
                const cacheKey = getCacheKey(genre);
                
                if (searchCache[activeTab].data[cacheKey] && isCacheValid(activeTab)) {
                    console.log('Using cached data for:', { activeTab, genre, cacheKey });
                    displayMovies(searchCache[activeTab].data[cacheKey], activeTab);
                } else {
                    console.log('Cache miss, fetching new data:', { activeTab, genre, cacheKey });
                    updateMovieList(false);
                }
                
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('tab', activeTab);
                if (!genre) {
                    currentUrl.searchParams.delete('genre');
                }
                window.history.replaceState({}, '', currentUrl);
            });
        }

        if (filterButton) {
            filterButton.addEventListener('click', function(e) {
                e.preventDefault();
                    console.log('Filter button clicked');
                const genre = genreSelect.value;
                const activeTab = currentTab.value;
                
                // キャッシュをクリア
                if (searchCache[activeTab]) {
                    searchCache[activeTab] = {
                        timestamp: null,
                        data: {}
                    };
                }
                
                updateMovieList(true);
            });
        }

        if (clearFilter) {
            clearFilter.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Clear button clicked');
                const activeTab = currentTab.value;
                const oldGenre = genreSelect.value;
                
                genreSelect.value = '';
                updateClearButtonVisibility();

                // すでに'all'のキャッシュがある場合はそれを使用
                const allCacheKey = getCacheKey('');
                if (searchCache[activeTab].data[allCacheKey] && isCacheValid(activeTab)) {
                    console.log('Using existing all cache');
                    displayMovies(searchCache[activeTab].data[allCacheKey], activeTab);
                } else {
                    // キャッシュがない場合のみ新しいデータを取得
                    updateMovieList(true);
                }
            });
        }

        function displayMovies(data, activeTab) {
            const tableBody = document.querySelector(`#${activeTab} tbody`);
            if (!tableBody) {
                console.error('Table body not found for tab:', activeTab);
                return;
            }

            if (!data.movies || !data.movies.data || data.movies.data.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center">
                            検索結果がありません
                        </td>
                    </tr>
                `;
                return;
            }

            tableBody.innerHTML = '';
            const currentGenre = genreSelect.value;
            data.movies.data.forEach((movie, index) => {
                const row = document.createElement('tr');
                const isFiltered = currentGenre && currentGenre !== '';
                const filteredRank = movie.rank;
                const originalRank = movie.original_rank;
                row.innerHTML = `
                    <td class="text-center fw-bold col-rank">
                        ${filteredRank}
                        ${isFiltered && originalRank ? `<br><small class="text-muted">(${originalRank})</small>` : ''}
                    </td>
                    <td class="col-title">${movie.title}</td>
                    <td class="d-none d-md-table-cell col-genre">
                        ${movie.genres && movie.genres.length > 0 ? 
                            movie.genres.map(genre => {
                                const backgroundColor = genreColors[genre] || '#f8f9fa';
                                return `<span class="badge" data-genre="${genre}" style="cursor: pointer; background-color: ${backgroundColor}; color: #2c3e50;">${genre}</span>`;
                            }).join('') : 
                            '<span class="text-muted">-</span>'
                        }
                    </td>
                    <td class="text-end col-boxoffice">
                        ${activeTab === 'global' ? 
                            `${(movie.box_office / 100000000).toFixed(2)}億ドル <small class="text-muted">(${movie.box_office_billion}億円)</small>` :
                            `${movie.box_office_billion}億円`
                        }
                    </td>
                    <td class="text-end col-budget">
                        ${activeTab === 'global' ? 
                            `${(movie.budget / 100000000).toFixed(2)}億ドル<br>
                            <small class="text-muted">(${movie.budget_billion ? movie.budget_billion + '億円' : '-'})</small>` :
                            `${!movie.budget_billion || movie.budget_billion === '0.0' ? '-' : movie.budget_billion + '億円'}`
                        }
                    </td>
                    <td class="text-center col-year">${movie.release_date ? new Date(movie.release_date).getFullYear() : '未定'}年</td>
                `;
                tableBody.appendChild(row);
            });

            setupGenreBadgeListeners();

            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('tab', activeTab);
            if (genreSelect.value) {
                currentUrl.searchParams.set('genre', genreSelect.value);
            } else {
                currentUrl.searchParams.delete('genre');
            }
            window.history.replaceState({}, '', currentUrl);
        }

        function isCacheValid(tab) {
            if (!searchCache[tab].timestamp) return false;
            const cacheAge = Date.now() - searchCache[tab].timestamp;
            return cacheAge < 10 * 60 * 1000; // 10分
        }

        async function updateMovieList(forceFetch = false) {
            const activeTab = currentTab.value;
            const genre = genreSelect.value;
            const cacheKey = getCacheKey(genre);
            console.log('Updating movie list:', { activeTab, genre, cacheKey, forceFetch });

            const tableBody = document.querySelector(`#${activeTab} tbody`);
            if (!tableBody) {
                console.error('Table body not found for tab:', activeTab);
                return;
            }

            // キャッシュチェック
            if (!forceFetch && searchCache[activeTab].data[cacheKey] && isCacheValid(activeTab)) {
                console.log('Using cached data for:', { activeTab, genre, cacheKey });
                displayMovies(searchCache[activeTab].data[cacheKey], activeTab);
                return;
            }

            tableBody.innerHTML = loadingSpinner;

            try {
                const response = await fetch(`/api/movies/filter?genre=${encodeURIComponent(genre)}&tab=${activeTab}`);
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                console.log('Received data:', data);

                if (data.success) {
                    // キャッシュを更新
                    updateCache(activeTab, genre, data);
                    displayMovies(data, activeTab);
                    return data;
                } else {
                    throw new Error(data.message || 'データの取得に失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center text-danger">
                            データの取得中にエラーが発生しました。<br>
                            <small>${error.message}</small>
                        </td>
                    </tr>
                `;
                throw error;
            }
        }

        if (genreSelect.value) {
            updateMovieList(false);
        } else {
            updateMovieList(false);
        }
    });
</script>

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    "name": "{{ $baseTitle }}",
    "description": "{{ $metaDesc }}",
    "url": "{{ url()->current() }}"
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {"@type": "Question", "name": "映画興行収入ランキングはいつ更新されますか？", "acceptedAnswer": {"@type": "Answer", "text": "データは定期的に更新されます。日本は公表値、世界はTMDBデータを基にしています。"}},
    {"@type": "Question", "name": "世界と日本のランキングの違いは？", "acceptedAnswer": {"@type": "Answer", "text": "世界はドル建ての収入、日本は円建ての収入を表示し、相互に換算値も併記しています。"}},
    {"@type": "Question", "name": "速報の扱いは？", "acceptedAnswer": {"@type": "Answer", "text": "公開直後の作品は速報として反映されることがありますが、確定値と区別して掲載します。"}}
  ]
}
</script>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "name": "映画興行収入ランキング",
    "description": "世界と日本の最新映画興行収入ランキングデータ",
    "numberOfItems": {{ $globalMovies->count() }},
    "itemListOrder": "https://schema.org/ItemListOrderDescending",
    "itemListElement": [
        @foreach($globalMovies->take(10) as $index => $movie)
        {
            "@type": "ListItem",
            "position": {{ $index + 1 }},
            "name": {!! json_encode($movie->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        }{{ !$loop->last ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endsection