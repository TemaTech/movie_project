@extends('layouts.cinematic')

@section('title', '歴代映画興行収入ランキング | 世界・日本のヒット作を徹底分析 - MUBIRAN')

@section('head')
    @vite(['resources/css/movie-modal.css', 'resources/css/ai-analysis.css', 'resources/css/filter-modal.css'])
    {{-- 直接CSSを読み込み（Viteビルドが古い場合のフォールバック - ビルド再実行後は削除可） --}}
    <link rel="stylesheet" href="/css/movie-modal.css">
@endsection

@section('content')

<header>
    <a href="/" class="logo-link">
        <img src="{{ asset('images/logo.png') }}" alt="MUBIRAN" class="logo-img">
    </a>
    
    <!-- Toggle Switch (中央配置) -->
    <div class="header-controls">
        <div class="toggle-container">
            <a href="#" class="toggle-btn active" id="btn-global" onclick="switchTab('global'); return false;">世界興行収入</a>
            <a href="#" class="toggle-btn" id="btn-japan" onclick="switchTab('japan'); return false;">日本興行収入</a>
        </div>
        
        {{-- 絞り込みボタン（モバイルではタブの横に表示） --}}
        <button type="button" class="filter-trigger-btn filter-mobile" id="filterTriggerBtn">
            <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
            </svg>
            <span class="filter-text">絞り込み</span>
        </button>
    </div>
    
    {{-- 絞り込みボタン（PC版：右端に配置） --}}
    <button type="button" class="filter-trigger-btn filter-desktop" id="filterTriggerBtnDesktop">
        <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
        </svg>
        <span class="filter-text">絞り込み</span>
    </button>
    
    {{-- タブ状態保持用hidden input --}}
    <input type="hidden" id="tabInput" value="{{ request('tab', 'global') }}">
</header>

<div class="container">
    <h1 class="page-title" id="page-title">世界興行収入ランキング</h1>

    <!-- Global Ranking Container -->
    <div id="global-container" style="display: block;">
        @if($globalMovies->isEmpty())
            <p class="text-center text-white">データがありません。</p>
        @else
            <!-- Top 3 -->
            <div class="top-rankings">
                @foreach($globalMovies->filter(function($movie) { return $movie->rank <= 3; }) as $movie)
                    @include('movies.partials.card', ['movie' => $movie, 'isJapan' => false])
                @endforeach
            </div>

            <!-- Rank 4+ -->
            <div class="ranking-list">
                @foreach($globalMovies->filter(function($movie) { return $movie->rank > 3; }) as $movie)
                    @include('movies.partials.list-item', ['movie' => $movie, 'isJapan' => false])
                @endforeach
            </div>
        @endif
        <div class="text-center mt-4 mb-5">
            <small style="color: var(--text-secondary);">最終更新: {{ $globalLastUpdated }}</small>
        </div>
        <div class="d-flex justify-content-center mt-4">
            {{ $globalMovies->appends(['tab' => 'global', 'genre' => $selectedGenre])->links('vendor.pagination.simple-cinematic') }}
        </div>
    </div>

    <!-- Japan Ranking Container -->
    <div id="japan-container" style="display: none;">
        @if($japanMovies->isEmpty())
            <p class="text-center text-white">データがありません。</p>
        @else
            <!-- Top 3 -->
            <div class="top-rankings">
                @foreach($japanMovies->filter(function($movie) { return $movie->rank <= 3; }) as $movie)
                    @include('movies.partials.card', ['movie' => $movie, 'isJapan' => true])
                @endforeach
            </div>

            <!-- Rank 4+ -->
            <div class="ranking-list">
                @foreach($japanMovies->filter(function($movie) { return $movie->rank > 3; }) as $movie)
                    @include('movies.partials.list-item', ['movie' => $movie, 'isJapan' => true])
                @endforeach
            </div>
        @endif

        <div class="text-center mt-4 mb-5">
            <small style="color: var(--text-secondary);">最終更新: {{ $japanLastUpdated }}</small>
        </div>
        <div class="d-flex justify-content-center mt-4">
            {{ $japanMovies->appends(['tab' => 'japan', 'genre' => $selectedGenre])->links('vendor.pagination.simple-cinematic') }}
        </div>
    </div>
</div>

@include('movies.partials.modal')
@include('movies.partials.filter-modal')

@endsection

@section('scripts')
<script>
    // Tab Switching Logic (Keep here for page-specific UI state)
    function switchTab(tab) {
        document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById('btn-' + tab).classList.add('active');
        document.getElementById('global-container').style.display = tab === 'global' ? 'block' : 'none';
        document.getElementById('japan-container').style.display = tab === 'japan' ? 'block' : 'none';
        document.getElementById('page-title').textContent = tab === 'global' ? '世界興行収入ランキング' : '日本興行収入ランキング';
        document.getElementById('tabInput').value = tab;
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    }

    window.TMDB_API_KEY = '26e7b323fa7ad8630e7c661e3c1def29';

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'global';
        switchTab(tab);
        // リスト項目の背景画像を処理（サーバーサイドで設定されている場合）
        if (typeof processListBackgrounds === 'function') {
            processListBackgrounds();
        }
        // Top3カードのポスター画像を取得
        if (typeof fetchImages === 'function') {
            fetchImages();
        }
    });
</script>
@vite(['resources/js/movie-modal.js', 'resources/js/ai-analysis.js', 'resources/js/filter-modal.js'])
@endsection