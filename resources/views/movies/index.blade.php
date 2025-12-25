@extends('layouts.cinematic')

@section('title', '歴代映画興行収入ランキング | 世界・日本のヒット作を徹底分析 - MUBIRAN')

@section('head')
    <link rel="stylesheet" href="{{ asset('css/movie-modal.css') }}?v={{ time() }}">
    <link rel="stylesheet" href="{{ asset('css/ai-analysis.css') }}?v={{ time() }}">
@endsection

@section('content')

<header>
    <a href="/" class="logo">MUBIRAN</a>
    
    <!-- Toggle Switch -->
    <div class="toggle-container">
        <a href="#" class="toggle-btn active" id="btn-global" onclick="switchTab('global'); return false;">世界興行収入</a>
        <a href="#" class="toggle-btn" id="btn-japan" onclick="switchTab('japan'); return false;">日本興行収入</a>
    </div>

    <!-- Genre Filter (Integrated into Header for style) -->
    <div class="genre-filter-wrapper" style="margin-left: 20px;">
        <form action="" method="GET" id="genreForm" style="display: flex; gap: 10px;">
            <input type="hidden" name="tab" id="tabInput" value="{{ request('tab', 'global') }}">
            <select name="genre" class="genre-select" onchange="document.getElementById('genreForm').submit()">
                <option value="">すべてのジャンル</option>
                @foreach($availableGenres as $genre)
                    <option value="{{ $genre }}" {{ $selectedGenre == $genre ? 'selected' : '' }}>
                        {{ $genre }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>
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
        if (typeof fetchImages === 'function') {
            fetchImages();
        }
    });
</script>
<script src="{{ asset('js/movie-modal.js') }}"></script>
<script src="{{ asset('js/ai-analysis.js') }}"></script>
@endsection