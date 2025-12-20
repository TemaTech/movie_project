<div class="top-card rank-{{ $movie->rank }}" onclick="openModal('{{ addslashes($movie->title) }}', '{{ $movie->movie_id }}', {{ $movie->release_date ? \Carbon\Carbon::parse($movie->release_date)->year : 'null' }})">
    <div class="rank-badge">{{ $movie->rank }}</div>
    <div class="poster-placeholder tmdb-poster" data-title="{{ $movie->title }}" data-movie-id="{{ $movie->movie_id }}" data-release-year="{{ $movie->release_date ? \Carbon\Carbon::parse($movie->release_date)->year : '' }}" data-type="movie"></div>
    <div class="movie-title" style="font-size: 1.3rem; margin-bottom: 2px;">{{ $movie->title }}</div>
    @if(!empty($movie->original_title) && $movie->original_title !== $movie->title && ($movie->production_country ?? '') !== 'JP')
        <div class="movie-title-en">{{ $movie->original_title }}</div>
    @endif
    <div class="revenue-main" style="color: var(--accent-gold);">
        @if(isset($isJapan) && $isJapan)
            {{ $movie->box_office_billion }}億円
        @else
            {{ number_format($movie->box_office / 100000000, 2) }}億ドル
        @endif
    </div>
    @if(!(isset($isJapan) && $isJapan))
        <div class="revenue-sub">
            {{ $movie->box_office_billion }}億円
        </div>
    @endif
</div>
