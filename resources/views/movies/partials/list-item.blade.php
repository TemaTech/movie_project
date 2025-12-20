@php
    if (isset($isJapan) && $isJapan) {
        $revenueVal = (float)str_replace(',', '', $movie->box_office_billion);
        $maxRevenue = 150.0;
    } else {
        $revenueVal = $movie->box_office / 100000000;
        $maxRevenue = 3.0;
    }
    $barWidth = min(($revenueVal / $maxRevenue) * 100, 100);
@endphp
<div class="list-item" onclick="openModal('{{ addslashes($movie->title) }}', '{{ $movie->movie_id }}')">
    <div class="revenue-bar-bg" style="width: {{ $barWidth }}%;"></div>
    <div class="list-rank">{{ str_pad($movie->rank, 2, '0', STR_PAD_LEFT) }}</div>
    <div class="list-poster tmdb-poster" data-title="{{ $movie->title }}" data-movie-id="{{ $movie->movie_id }}" data-type="movie"></div>
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
        @if(isset($isJapan) && $isJapan)
            <span class="revenue-main">{{ $movie->box_office_billion }}億円</span>
            <span class="revenue-sub">
                {{ $movie->budget_billion === '0.0' || !$movie->budget_billion ? '-' : '制作費: ' . $movie->budget_billion . '億円' }}
            </span>
        @else
            <span class="revenue-main">{{ number_format($movie->box_office / 100000000, 2) }}億ドル</span>
            <span class="revenue-sub">{{ $movie->box_office_billion }}億円</span>
        @endif
    </div>
</div>
