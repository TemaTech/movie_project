@extends('layouts.app')

@section('canonical')
<link rel="canonical" href="{{ url()->current() }}" />
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
</div>
@endsection


