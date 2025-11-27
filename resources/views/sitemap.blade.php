<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ rtrim(config('app.url'), '/') }}/</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    @foreach($movies as $movie)
        @php
            $isTmdbId = isset($movie->movie_id) && is_string($movie->movie_id) && str_starts_with($movie->movie_id, 'global_');
            $isJapanId = isset($movie->movie_id) && is_string($movie->movie_id) && str_starts_with($movie->movie_id, 'jp_');
        @endphp
        @if($isTmdbId)
        <url>
            <loc>{{ rtrim(config('app.url'), '/') }}/movies/{{ str_replace('global_', '', $movie->movie_id) }}</loc>
            @if(!empty($movie->last_updated))
            <lastmod>{{ \Carbon\Carbon::parse($movie->last_updated)->toAtomString() }}</lastmod>
            @elseif(!empty($movie->updated_at))
            <lastmod>{{ \Carbon\Carbon::parse($movie->updated_at)->toAtomString() }}</lastmod>
            @endif
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
        @elseif($isJapanId)
        <url>
            <loc>{{ rtrim(config('app.url'), '/') }}/movies/{{ $movie->movie_id }}</loc>
            @if(!empty($movie->last_updated))
            <lastmod>{{ \Carbon\Carbon::parse($movie->last_updated)->toAtomString() }}</lastmod>
            @elseif(!empty($movie->updated_at))
            <lastmod>{{ \Carbon\Carbon::parse($movie->updated_at)->toAtomString() }}</lastmod>
            @endif
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
        @endif
    @endforeach
</urlset>