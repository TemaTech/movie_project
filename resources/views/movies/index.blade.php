@extends('layouts.cinematic')

@section('title', 'MUBIRAN | Cinematic Data-Scapes')

@section('content')

<header>
    <div class="logo">MUBIRAN</div>
    
    <!-- Toggle Switch -->
    <div class="toggle-container">
        <a href="#" class="toggle-btn active" id="btn-global" onclick="switchTab('global'); return false;">世界興行収入</a>
        <a href="#" class="toggle-btn" id="btn-japan" onclick="switchTab('japan'); return false;">日本興行収入</a>
    </div>

    <!-- Genre Filter (Integrated into Header for style) -->
    <div class="genre-filter-wrapper" style="margin-left: 20px;">
        <form action="{{ url()->current() }}" method="GET" id="genreForm" style="display: flex; gap: 10px;">
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
    <h1 class="page-title" id="page-title">Global Box Office Ranking</h1>

    <!-- Global Ranking Container -->
    <div id="global-container" style="display: block;">
        @if($globalMovies->isEmpty())
            <p class="text-center text-white">データがありません。</p>
        @else
            <!-- Top 3 -->
            <div class="top-rankings">
                @foreach($globalMovies->filter(function($movie) { return $movie->rank <= 3; }) as $movie)
                    <div class="top-card rank-{{ $movie->rank }}" onclick="window.location.href='{{ rtrim(config('app.url'), '/') }}/movies/{{ str_replace('global_', '', $movie->movie_id) }}'">
                        <div class="rank-badge">{{ $movie->rank }}</div>
                        <div class="poster-placeholder tmdb-poster" data-title="{{ $movie->title }}" data-type="movie"></div>
                        <div class="movie-title" style="font-size: 1.3rem;">{{ $movie->title }}</div>
                        <div class="revenue-main" style="color: var(--accent-gold);">
                            {{ number_format($movie->box_office / 100000000, 2) }}億ドル
                        </div>
                        <div class="revenue-sub">{{ $movie->box_office_billion }}億円</div>
                    </div>
                @endforeach
            </div>

            <!-- Rank 4+ -->
            <div class="ranking-list">
                @foreach($globalMovies->filter(function($movie) { return $movie->rank > 3; }) as $movie)
                    @php
                        // Calculate bar width relative to the top movie (approx 3 billion)
                        $revenueVal = $movie->box_office / 100000000; // in billion
                        $maxRevenue = 3.0;
                        $barWidth = min(($revenueVal / $maxRevenue) * 100, 100);
                    @endphp
                    <div class="list-item" onclick="window.location.href='{{ rtrim(config('app.url'), '/') }}/movies/{{ str_replace('global_', '', $movie->movie_id) }}'" style="cursor: pointer;">
                        <div class="revenue-bar-bg" style="width: {{ $barWidth }}%;"></div>
                        <div class="list-rank">{{ str_pad($movie->rank, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="list-poster tmdb-poster" data-title="{{ $movie->title }}" data-type="movie"></div>
                        <div class="list-info">
                            <span class="movie-title">{{ $movie->title }}</span>
                            <div class="tags">
                                @if($movie->genres && is_array($movie->genres))
                                    @foreach($movie->genres as $genre)
                                        <span class="tag">{{ $genre }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        <div class="list-revenue">
                            <span class="revenue-main">{{ number_format($movie->box_office / 100000000, 2) }}億ドル</span>
                            <span class="revenue-sub">{{ $movie->box_office_billion }}億円</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="text-center mt-4 mb-5">
            <small style="color: var(--text-secondary);">最終更新: {{ $globalLastUpdated }}</small>
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
                    <div class="top-card rank-{{ $movie->rank }}">
                        <div class="rank-badge">{{ $movie->rank }}</div>
                        <div class="poster-placeholder tmdb-poster" data-title="{{ $movie->title }}" data-type="movie"></div>
                        <div class="movie-title" style="font-size: 1.3rem;">{{ $movie->title }}</div>
                        <div class="revenue-main" style="color: var(--accent-gold);">
                            {{ $movie->box_office_billion }}億円
                        </div>
                        <div class="revenue-sub">
                            {{ $movie->budget_billion === '0.0' || !$movie->budget_billion ? '-' : '制作費: ' . $movie->budget_billion . '億円' }}
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Rank 4+ -->
            <div class="ranking-list">
                @foreach($japanMovies->filter(function($movie) { return $movie->rank > 3; }) as $movie)
                    @php
                        // Calculate bar width relative to approx 150 billion yen (top movies) or just relative to max
                        // Japan top is around 400 billion? No, 40 billion.
                        // Let's say max is 200 (20 billion) for visualization scaling
                        $revenueVal = (float)str_replace(',', '', $movie->box_office_billion);
                        $maxRevenue = 150.0; // 150億円
                        $barWidth = min(($revenueVal / $maxRevenue) * 100, 100);
                    @endphp
                    <div class="list-item">
                        <div class="revenue-bar-bg" style="width: {{ $barWidth }}%;"></div>
                        <div class="list-rank">{{ str_pad($movie->rank, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="list-poster tmdb-poster" data-title="{{ $movie->title }}" data-type="movie"></div>
                        <div class="list-info">
                            <span class="movie-title">{{ $movie->title }}</span>
                            <div class="tags">
                                @if($movie->genres && is_array($movie->genres))
                                    @foreach($movie->genres as $genre)
                                        <span class="tag">{{ $genre }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        <div class="list-revenue">
                            <span class="revenue-main">{{ $movie->box_office_billion }}億円</span>
                            <span class="revenue-sub">
                                {{ $movie->budget_billion === '0.0' || !$movie->budget_billion ? '-' : '制作費: ' . $movie->budget_billion . '億円' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<script>
    // TMDB API Key (Exposed for demo purposes as requested)
    const TMDB_API_KEY = '26e7b323fa7ad8630e7c661e3c1def29';
    const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
    const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w200';
    const IMAGE_BASE_URL_LARGE = 'https://image.tmdb.org/t/p/w500';

    // Tab Switching Logic
    function switchTab(tab) {
        // Update Buttons
        document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById('btn-' + tab).classList.add('active');

        // Update Containers
        document.getElementById('global-container').style.display = tab === 'global' ? 'block' : 'none';
        document.getElementById('japan-container').style.display = tab === 'japan' ? 'block' : 'none';

        // Update Title
        document.getElementById('page-title').textContent = tab === 'global' ? 'Global Box Office Ranking' : 'Japan Box Office Ranking';

        // Update Form Input
        document.getElementById('tabInput').value = tab;

        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    }

    // Initialize Tab based on URL or default
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'global';
        switchTab(tab);

        // Fetch Images
        fetchImages();
    });

    // TMDB Image Fetching
    async function fetchImages() {
        const posters = document.querySelectorAll('.tmdb-poster');
        
        for (const poster of posters) {
            const title = poster.dataset.title;
            if (!title) continue;

            // Check Cache
            const cachedImage = localStorage.getItem('tmdb_poster_' + title);
            if (cachedImage) {
                poster.style.backgroundImage = `url('${cachedImage}')`;
                continue;
            }

            try {
                // Search Movie
                const searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${TMDB_API_KEY}&query=${encodeURIComponent(title)}&language=ja-JP`;
                const response = await fetch(searchUrl);
                const data = await response.json();

                if (data.results && data.results.length > 0) {
                    const posterPath = data.results[0].poster_path;
                    if (posterPath) {
                        const imageUrl = poster.classList.contains('poster-placeholder') ? 
                            IMAGE_BASE_URL_LARGE + posterPath : 
                            IMAGE_BASE_URL + posterPath;
                        
                        poster.style.backgroundImage = `url('${imageUrl}')`;
                        
                        // Cache it
                        localStorage.setItem('tmdb_poster_' + title, imageUrl);
                    }
                }
            } catch (error) {
                console.error('Error fetching image for:', title, error);
            }
            
            // Rate limiting (simple delay)
            await new Promise(r => setTimeout(r, 100));
        }
    }
</script>
@endsection