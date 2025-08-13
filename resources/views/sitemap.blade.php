<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ rtrim(config('app.url'), '/') }}/</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    @foreach($movies as $movie)
    <url>
        <loc>{{ rtrim(config('app.url'), '/') }}/movies/{{ $movie->movie_id }}</loc>
        @if($movie->release_date)
        <lastmod>{{ $movie->release_date->toAtomString() }}</lastmod>
        @endif
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    @endforeach
</urlset>