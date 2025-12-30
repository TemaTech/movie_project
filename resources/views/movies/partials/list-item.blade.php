@php
    if (isset($isJapan) && $isJapan) {
        $revenueVal = (float)str_replace(',', '', $movie->box_office_billion);
        $maxRevenue = 150.0;
    } else {
        $revenueVal = $movie->box_office / 100000000;
        $maxRevenue = 3.0;
    }
    $barWidth = min(($revenueVal / $maxRevenue) * 100, 100);
    
    // Determine if the movie is currently active
    // For Japan: Use DB flag is_active (green background in wiki)
    // For Global: Use 6-month release window
    $releaseDate = $movie->release_date ? \Carbon\Carbon::parse($movie->release_date) : null;
    $isActive = false;
    
    if (isset($isJapan) && $isJapan) {
        $isActive = (bool)($movie->is_active ?? false);
    } else {
        $isActive = $releaseDate && $releaseDate->diffInMonths(now()) <= 6;
    }

    $posterUrl = '';
    if ($movie->poster_path) {
        if (str_starts_with($movie->poster_path, 'posters/')) {
             $posterUrl = asset('storage/' . $movie->poster_path);
        } else {
             $posterUrl = 'https://image.tmdb.org/t/p/w200' . $movie->poster_path;
        }
    }
    $titleLen = mb_strlen($movie->title);
    $titleClass = 'title-short';
    if ($titleLen > 35) {
        $titleClass = 'title-long';
    } elseif ($titleLen > 20) {
        $titleClass = 'title-medium';
    }
@endphp
<div class="list-item {{ $isActive ? 'active-movie' : '' }} {{ isset($isJapan) && $isJapan ? 'is-japan' : '' }}" onclick="openModal('{{ addslashes($movie->title) }}', '{{ $movie->movie_id }}', {{ $releaseDate ? $releaseDate->year : 'null' }}, {{ $movie->tmdb_id ?? 'null' }}, '{{ $movie->box_office_billion ?? null }}')">
    @if($isActive)
        <div class="active-badge-card">公開中</div>
    @endif
    <div class="revenue-bar-bg" style="width: {{ $barWidth }}%;"></div>
    <div class="list-rank">{{ $movie->rank }}</div>
    <div class="list-poster tmdb-poster {{ $posterUrl ? '' : 'poster-placeholder' }}" 
         style="{{ $posterUrl ? 'background-image: url(' . $posterUrl . ')' : '' }}"
         data-title="{{ $movie->title }}" 
         data-movie-id="{{ $movie->movie_id }}" 
         data-tmdb-id="{{ $movie->tmdb_id ?? '' }}"
         data-release-year="{{ $releaseDate ? $releaseDate->year : '' }}" 
         data-type="movie"></div>
    <div class="list-info">
        <span class="movie-title {{ $titleClass }}">
            {{ $movie->title }}
        </span>
        @if(!empty($movie->original_title) && $movie->original_title !== $movie->title && ($movie->production_country ?? '') !== 'JP')
            <span class="movie-title-en">{{ $movie->original_title }}</span>
        @endif
    </div>
    <div class="list-revenue">
        @if(isset($isJapan) && $isJapan)
            <span class="revenue-main">{{ $movie->box_office_billion }}億円</span>
        @else
            <span class="revenue-main">{{ number_format($movie->box_office / 100000000, 2) }}億ドル</span>
            <span class="revenue-sub">{{ $movie->box_office_billion }}億円</span>
        @endif
    </div>
    
    @if(!empty($movie->ai_analysis))<button class="ai-trigger-btn" onclick="toggleAi(event, this)" aria-label="AI分析を表示"><svg class="ai-sparkle-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 0L14.59 9.41L24 12L14.59 14.59L12 24L9.41 14.59L0 12L9.41 9.41L12 0Z"/></svg></button><div class="ai-overlay"><span class="ai-overlay-label">AI Analysis</span><div class="ai-overlay-content">{{ $movie->ai_analysis }}</div></div>@endif
</div>
