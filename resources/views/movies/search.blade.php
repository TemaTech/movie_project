@extends('layouts.app')

@section('title', '映画検索結果 - ムビラン')
@section('meta_description', '映画タイトルで検索できます。興行収入・公開年などの基本情報も確認。')
@section('canonical')
<link rel="canonical" href="{{ url()->current() }}" />
@endsection

@section('content')
<div class="container py-5">
    <h1 class="mb-4">検索結果</h1>
    @if(!empty($query))
        <p class="mb-3">キーワード: <strong>{{ $query }}</strong></p>
    @endif

    @if(!empty($error))
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if(empty($movies) || count($movies) === 0)
        <p>該当する映画は見つかりませんでした。</p>
    @else
        <div class="list-group">
            @foreach($movies as $item)
                <a class="list-group-item list-group-item-action" href="{{ rtrim(config('app.url'), '/') }}/movies/{{ $item['id'] }}">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">{{ $item['title'] ?? 'タイトル不明' }}</h5>
                        @if(!empty($item['release_date']))
                            <small>{{ \Illuminate\Support\Str::of($item['release_date'])->limit(10, '') }}</small>
                        @endif
                    </div>
                    @if(!empty($item['overview']))
                        <p class="mb-1 text-muted">{{ \Illuminate\Support\Str::limit($item['overview'], 120) }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection


