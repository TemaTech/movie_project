<div class="top-card rank-{{ $movie->rank }}" onclick="openModal('{{ addslashes($movie->title) }}', '{{ $movie->movie_id }}')">
    <div class="rank-badge">{{ $movie->rank }}</div>
    <div class="poster-placeholder tmdb-poster" data-title="{{ $movie->title }}" data-movie-id="{{ $movie->movie_id }}" data-type="movie"></div>
    <div class="movie-title" style="font-size: 1.3rem;">{{ $movie->title }}</div>
    <div class="revenue-main" style="color: var(--accent-gold);">
        @if(isset($isJapan) && $isJapan)
            {{ $movie->box_office_billion }}億円
        @else
            {{ number_format($movie->box_office / 100000000, 2) }}億ドル
        @endif
    </div>
    <div class="revenue-sub">
        @if(isset($isJapan) && $isJapan)
            {{ $movie->budget_billion === '0.0' || !$movie->budget_billion ? '-' : '制作費: ' . $movie->budget_billion . '億円' }}
        @else
            {{ $movie->box_office_billion }}億円
        @endif
    </div>
</div>
