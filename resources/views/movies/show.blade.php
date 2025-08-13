@extends('layouts.app')

@section('title', ($movie['title'] ?? '映画詳細') . ' | 興行収入・公開日・概要')
@section('meta_description', ($movie['overview'] ?? '映画の詳細情報を掲載。') . '｜原題: ' . ($movie['original_title'] ?? '-') . '｜公開日: ' . ($movie['release_date'] ?? '-'))
@section('meta_keywords', ($movie['title'] ?? '') . ',映画,興行収入,公開日,概要')
@section('canonical')
<link rel="canonical" href="{{ url()->current() }}" />
@endsection
@section('breadcrumbs')
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "item": {"@id": "{{ rtrim(config('app.url'), '/') }}/", "name": "ホーム"}
    },
    {
      "@type": "ListItem",
      "position": 2,
      "item": {"@id": "{{ rtrim(config('app.url'), '/') }}/movies/{{ $movie['id'] ?? '' }}", "name": "{{ $movie['title'] ?? '映画詳細' }}"}
    }
  ]
}
</script>
@endsection

@section('content')
<div class="container py-5">
    <h1 class="mb-4">{{ $movie['title'] ?? '映画詳細' }}</h1>
    <div class="card">
        <div class="card-body">
            <p class="mb-1"><strong>原題:</strong> {{ $movie['original_title'] ?? '-' }}</p>
            <p class="mb-1"><strong>公開日:</strong> {{ $movie['release_date'] ?? '-' }}</p>
            <p class="mb-1"><strong>概要:</strong></p>
            <p>{{ $movie['overview'] ?? '情報がありません。' }}</p>
        </div>
    </div>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Movie",
      "name": {{ json_encode($movie['title'] ?? '映画') }},
      "alternateName": {{ json_encode($movie['original_title'] ?? null) }},
      "datePublished": {{ json_encode($movie['release_date'] ?? null) }},
      "description": {{ json_encode($movie['overview'] ?? null) }},
      "url": {{ json_encode(url()->current()) }},
      "inLanguage": "ja"
    }
    </script>
</div>
@endsection


