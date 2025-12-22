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
@endphp
<div class="list-item {{ $isActive ? 'active-movie' : '' }} {{ isset($isJapan) && $isJapan ? 'is-japan' : '' }}" onclick="openModal('{{ addslashes($movie->title) }}', '{{ $movie->movie_id }}', {{ $releaseDate ? $releaseDate->year : 'null' }}, {{ $movie->tmdb_id ?? 'null' }}, '{{ $movie->box_office_billion ?? null }}')">
    <div class="revenue-bar-bg" style="width: {{ $barWidth }}%;"></div>
    <div class="list-rank">{{ str_pad($movie->rank, 2, '0', STR_PAD_LEFT) }}</div>
    <div class="list-poster tmdb-poster {{ $movie->poster_path ? '' : 'poster-placeholder' }}" 
         style="{{ $movie->poster_path ? 'background-image: url(https://image.tmdb.org/t/p/w200' . $movie->poster_path . ')' : '' }}"
         data-title="{{ $movie->title }}" 
         data-movie-id="{{ $movie->movie_id }}" 
         data-tmdb-id="{{ $movie->tmdb_id ?? '' }}"
         data-release-year="{{ $releaseDate ? $releaseDate->year : '' }}" 
         data-type="movie"></div>
    <div class="list-info">
        <span class="movie-title">
            {{ $movie->title }}
            @if($isActive)
                <span class="active-badge">公開中</span>
            @endif
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
</div>
