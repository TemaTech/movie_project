@extends('layouts.app')

@section('content')
<style>
    .gradient-bg {
        background: linear-gradient(135deg, #f5f7fa 0%, #e4e8eb 100%);
        min-height: 100vh;
    }
    .custom-card {
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.98);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }
    .custom-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
    }
    .nav-tabs .nav-link {
        border: none;
        color: #555;
        border-radius: 12px;
        margin: 0 5px;
        padding: 12px 24px;
        transition: all 0.3s ease;
        background: rgba(255, 255, 255, 0.9);
    }
    .nav-tabs .nav-link.active {
        background: #2c3e50;
        color: white;
        border: none;
    }
    .table thead th {
        background: #2c3e50;
        color: white;
        border: none;
        font-weight: 500;
        padding: 15px;
        white-space: nowrap;
    }
    .badge {
        color: #2c3e50;
        font-weight: 500;
        padding: 4px 8px;
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.1);
        transition: all 0.2s ease;
        text-decoration: none;
        cursor: pointer;
        display: inline-block;
        margin: 1px;
        white-space: nowrap;
        font-size: 0.75rem;
    }
    .badge:hover {
        transform: scale(1.05);
        filter: brightness(0.95);
    }
    .table td {
        padding: 15px;
        color: #495057;
        border-bottom: 1px solid #f1f3f5;
        white-space: nowrap;
    }
    .table td:nth-child(2),
    .table td:nth-child(3) {
        white-space: normal;
        min-width: 180px;
        max-width: 350px;
    }
    .table td:nth-child(2) {
        min-width: 250px;
        max-width: 500px;
    }
    .table th:nth-child(4),
    .table th:nth-child(5) {
        min-width: 150px;
    }
    .table th:nth-child(6) {
        min-width: 100px;
    }
    h1 {
        color: #2c3e50;
        font-weight: 600;
    }
    .custom-card .pagination {
        margin: 2rem 0 0;
        gap: 0.5rem;
    }
    
    .custom-card .page-item {
        margin: 0 2px;
    }
    
    .custom-card .page-link {
        color: #2c3e50;
        border: 1px solid #e9ecef;
        padding: 0.6rem 1rem;
        border-radius: 8px;
        background: #fff;
        transition: all 0.2s ease;
        font-size: 0.9rem;
        min-width: 40px;
        text-align: center;
        line-height: 1.2;
    }
    
    /* 矢印アイコンのスタイルを変更 */
    .custom-card .page-item:first-child .page-link,
    .custom-card .page-item:last-child .page-link {
        font-size: 0.8rem;
        padding: 0.6rem 0.8rem;
    }
    
    /* 矢印アイコンを変更 */
    .custom-card .page-item:first-child .page-link::before {
        content: "←";
        font-family: system-ui;
    }
    
    .custom-card .page-item:last-child .page-link::before {
        content: "→";
        font-family: system-ui;
    }
    
    /* デフォルトの矢印を非表示に */
    .custom-card .page-item:first-child .page-link svg,
    .custom-card .page-item:last-child .page-link svg {
        display: none;
    }
    
    .custom-card .page-link:hover {
        background: #f8f9fa;
        color: #2c3e50;
        border-color: #dee2e6;
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    
    .custom-card .page-item.active .page-link {
        background-color: #2c3e50;
        border-color: #2c3e50;
        color: #fff;
        font-weight: 500;
    }
    
    .custom-card .page-item.disabled .page-link {
        background-color: #f8f9fa;
        border-color: #e9ecef;
        color: #6c757d;
        pointer-events: none;
    }

    .custom-card .pagination-info {
        text-align: center;
        color: #6c757d;
        font-size: 0.9rem;
        margin-top: 1rem;
    }

    /* ページネーションの基本設定 */
    .custom-card nav[role="navigation"] {
        width: 100%;
        margin: 2rem 0;
    }

    /* モバイル用ページネーションを非表示 */
    .custom-card .flex.justify-between.sm\:hidden {
        display: none;
    }

    /* デスクトップ用ページネーションコンテナ */
    .custom-card .hidden.sm\:flex-1.sm\:flex.sm\:items-center.sm\:justify-between {
        display: flex !important;
        flex-direction: column;
        align-items: center;
    }

    /* 英語の表示を非表示にする */
    .custom-card .text-sm.text-gray-700.leading-5 {
        display: none;
    }

    /* ページネーションリスト */
    .custom-card .relative.z-0.inline-flex.rtl\:flex-row-reverse {
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }

    /* ページネーションアイテム */
    .custom-card .relative.inline-flex.items-center,
    .custom-card span[aria-current="page"] {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        padding: 0 8px;
        margin: 0;
        font-size: 14px;
        font-weight: 500;
        color: #4a5568;
        background: #ffffff;
        border: none;
        border-right: 1px solid #e2e8f0;
        transition: all 0.2s ease;
        text-decoration: none !important;
    }

    /* リンクの下線を削除 */
    .custom-card .relative.inline-flex.items-center a {
        text-decoration: none !important;
    }

    /* 最後のアイテムのボーダーを削除 */
    .custom-card .relative.inline-flex.items-center:last-child {
        border-right: none;
    }

    /* ホバー効果 */
    .custom-card .relative.inline-flex.items-center:hover:not([aria-current="page"]) {
        background: #f7fafc;
        color: #2d3748;
    }

    /* アクティブページ */
    .custom-card span[aria-current="page"] {
        background: #3182ce;
        color: white;
        font-weight: 600;
    }

    /* 矢印アイコン */
    .custom-card svg.w-5.h-5 {
        width: 16px;
        height: 16px;
        stroke-width: 3;
    }

    /* ページ情報テキスト */
    .custom-card .pagination-info {
        margin-top: 1rem;
        font-size: 14px;
        color: #718096;
    }

    /* 無効なページネーションアイテム */
    .custom-card .relative.inline-flex.items-center[aria-disabled="true"] {
        color: #a0aec0;
        background: #f7fafc;
        cursor: not-allowed;
    }

    /* すべてのリンクの下線を削除 */
    .custom-card a {
        text-decoration: none !important;
    }

    /* テーブルのレスポンシブ対応を改善 */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
        /* スクロールバーのスタイリング */
        scrollbar-width: thin;
        scrollbar-color: rgba(0, 0, 0, 0.2) transparent;
    }

    /* Webkit（Chrome, Safari等）用のスクロールバースタイル */
    .table-responsive::-webkit-scrollbar {
        height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: transparent;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background-color: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
    }

    /* テーブルのスクロール対応改善 */
    .table-responsive {
        margin: 0 -15px;
        padding: 0 15px;
        width: calc(100% + 30px);
    }

    @media (max-width: 768px) {
        .table-responsive {
            margin: 0 -10px;
            padding: 0 10px;
            width: calc(100% + 20px);
        }

        /* スクロールバーのスタイリング（モバイル用） */
        .table-responsive::-webkit-scrollbar {
            height: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 2px;
        }
    }

    /* スマートフォン対応のスタイル */
    @media (max-width: 768px) {
        .container {
            padding: 0 10px;
        }

        h1 {
            font-size: 1.8rem !important;
            margin-bottom: 1rem !important;
        }

        .nav-tabs .nav-link {
            padding: 8px 12px;
            font-size: 0.9rem;
        }

        .custom-card {
            padding: 10px !important;
        }

        .table thead th {
            padding: 10px 5px;
            font-size: 0.8rem;
        }

        .table td {
            padding: 10px 5px;
            font-size: 0.8rem;
        }

        .badge {
            font-size: 0.7rem;
            padding: 2px 6px;
        }

        /* テーブルのレスポンシブ調整 */
        .table td:nth-child(2),
        .table td:nth-child(3) {
            min-width: 120px;
            max-width: 200px;
        }

        .table th:nth-child(4),
        .table th:nth-child(5),
        .table th:nth-child(6) {
            min-width: auto;
        }

        /* フィルターフォームの調整 */
        #filterForm {
            flex-direction: column;
            gap: 10px;
        }

        #filterForm select {
            max-width: 100% !important;
            width: 100%;
        }

        #filterForm button {
            width: 100%;
        }

        /* ページネーションの調整 */
        .pagination {
            flex-wrap: wrap;
            justify-content: center;
            gap: 5px;
        }

        .page-link {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            min-width: 35px;
        }
    }
</style>

<div class="gradient-bg py-5">
    <div class="container">
        <h1 class="mb-5 text-center fw-bold display-4 animate__animated animate__fadeIn">
            🎬 映画興行収入ランキング
        </h1>

        <!-- タブナビゲーションの直前に移動 -->
        <div class="mb-4">
            <form action="{{ url('/movies') }}" method="get" class="d-flex justify-content-center align-items-center gap-2" id="filterForm">
                <input type="hidden" name="tab" value="{{ request()->get('tab', 'global') }}" id="currentTab">
                <select name="genre" class="form-select" style="max-width: 200px;">
                    <option value="">すべてのジャンル</option>
                    @foreach($availableGenres as $genre)
                        <option value="{{ $genre }}" {{ $selectedGenre == $genre ? 'selected' : '' }}>
                            {{ $genre }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">絞り込み</button>
                @if($selectedGenre)
                    <a href="{{ url('/movies') }}?tab={{ request()->get('tab', 'global') }}" class="btn btn-outline-secondary">
                        クリア
                    </a>
                @endif
            </form>
        </div>

        <!-- タブナビゲーション -->
        <ul class="nav nav-tabs nav-fill mb-4 border-0" id="movieTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="global-tab" data-bs-toggle="tab" data-bs-target="#global" type="button" role="tab" aria-controls="global" aria-selected="true">
                    🌏 世界興行収入
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="japan-tab" data-bs-toggle="tab" data-bs-target="#japan" type="button" role="tab" aria-controls="japan" aria-selected="false">
                    🗾 日本興行収入
                </button>
            </li>
        </ul>

        <!-- タブコンテンツ -->
        <div class="tab-content" id="movieTabsContent">
            <!-- 世界興行収入タブ -->
            <div class="tab-pane fade show active" id="global" role="tabpanel">
                <div class="custom-card shadow-lg">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center">#</th>
                                        <th>タイトル</th>
                                        <th>ジャンル</th>
                                        <th class="text-end">興行収入</th>
                                        <th class="text-end">制作費</th>
                                        <th class="text-center">公開年</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($globalMovies as $movie)
                                    <tr>
                                        <td class="text-center fw-bold">{{ $movie->rank }}</td>
                                        <td>{{ $movie->title }}</td>
                                        <td>
                                            @if($movie->genres && is_array($movie->genres) && count($movie->genres) > 0)
                                                @foreach($movie->genres as $genre)
                                                    <a href="{{ route('movies.list', ['tab' => request()->get('tab', 'global'), 'genre' => $genre]) }}" 
                                                       class="badge text-decoration-none me-1" 
                                                       style="cursor: pointer; background-color: {{ $genreColors[$genre] ?? '#f8f9fa' }}; color: {{ in_array($genre, ['スリラー', 'ホラー']) ? '#ffffff' : '#2c3e50' }};">
                                                        {{ $genre }}
                                                    </a>
                                                @endforeach
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $movie->box_office_billion }}億円</td>
                                        <td class="text-end">{{ $movie->budget_billion }}億円</td>
                                        <td class="text-center">{{ \Carbon\Carbon::parse($movie->release_date)->format('Y/m/d') }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <!-- グローバル映画のページネーション -->
                            <div class="d-flex flex-column align-items-center mt-4">
                                @if($globalMovies->total() > 0)
                                    {{ $globalMovies->appends(['japan_page' => request()->japan_page])->links() }}
                                    <div class="pagination-info">
                                        全{{ $globalMovies->total() }}件中 {{ $globalMovies->firstItem() }}～{{ $globalMovies->lastItem() }}件を表示
                                    </div>
                                @else
                                    <div class="pagination-info">
                                        検索結果がありません
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 日本興行収入タブ -->
            <div class="tab-pane fade" id="japan" role="tabpanel">
                <div class="custom-card shadow-lg">
                    <div class="card-body p-4">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle"></i>
                            日本国内の興行収入データは<a href="https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧" target="_blank" rel="noopener noreferrer">Wikipedia</a>から取得しています（CC BY-SA 3.0）
                            <br>
                            最終更新: {{ $lastUpdated }}
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center">#</th>
                                        <th>タイトル</th>
                                        <th>ジャンル</th>
                                        <th class="text-end">興行収入</th>
                                        <th class="text-end">制作費</th>
                                        <th class="text-center">公開年</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($japanMovies as $movie)
                                    <tr>
                                        <td class="text-center fw-bold">{{ $movie->rank }}</td>
                                        <td>{{ $movie->title }}</td>
                                        <td>
                                            @if($movie->genres && is_array($movie->genres) && count($movie->genres) > 0)
                                                @foreach($movie->genres as $genre)
                                                    <a href="{{ route('movies.list', ['tab' => request()->get('tab', 'global'), 'genre' => $genre]) }}" 
                                                       class="badge text-decoration-none me-1" 
                                                       style="cursor: pointer; background-color: {{ $genreColors[$genre] ?? '#f8f9fa' }}; color: {{ in_array($genre, ['スリラー', 'ホラー']) ? '#ffffff' : '#2c3e50' }};">
                                                        {{ $genre }}
                                                    </a>
                                                @endforeach
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            {{ $movie->box_office_billion }}億円
                                        </td>
                                        <td class="text-end">{{ $movie->budget_billion }}億円</td>
                                        <td class="text-center">{{ \Carbon\Carbon::parse($movie->release_date)->format('Y/m/d') }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <!-- 日本映画のページネーション -->
                            <div class="d-flex flex-column align-items-center mt-4">
                                {{ $japanMovies->appends(['global_page' => request()->global_page])->links() }}
                                <div class="pagination-info">
                                    全{{ $japanMovies->total() }}件中 {{ $japanMovies->firstItem() }}～{{ $japanMovies->lastItem() }}件を表示
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Animate.css の追加 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

<!-- タブの状態を維持するためのスクリプトを追加 -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ローカルストレージからタブの状態を取得、なければURLから取得、それもなければデフォルトはglobal
        const savedTab = localStorage.getItem('activeMovieTab');
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || savedTab || 'global';

        // タブの状態を設定
        const tab = document.querySelector(`#${activeTab}-tab`);
        if (tab) {
            const tabInstance = new bootstrap.Tab(tab);
            tabInstance.show();
        }

        // フォームの hidden input を更新
        document.getElementById('currentTab').value = activeTab;

        // タブ切り替え時の処理
        const tabs = document.querySelectorAll('[data-bs-toggle="tab"]');
        tabs.forEach(tab => {
            tab.addEventListener('shown.bs.tab', function(event) {
                const id = event.target.id.replace('-tab', '');
                
                // ローカルストレージにタブの状態を保存
                localStorage.setItem('activeMovieTab', id);

                // フォームの hidden input を更新
                document.getElementById('currentTab').value = id;

                // ジャンルフィルターが設定されている場合はフォームを送信
                const genreSelect = document.querySelector('select[name="genre"]');
                if (genreSelect.value) {
                    document.getElementById('filterForm').submit();
                } else {
                    // URLにタブパラメータを追加
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('tab', id);
                    window.history.replaceState({}, '', currentUrl);
                }
            });
        });

        // ページネーションリンクにタブとジャンルパラメータを追加
        document.querySelectorAll('.pagination a').forEach(link => {
            link.addEventListener('click', function(e) {
                const currentTab = document.getElementById('currentTab').value;
                const currentGenre = document.querySelector('select[name="genre"]').value;
                const url = new URL(this.href);
                url.searchParams.set('tab', currentTab);
                if (currentGenre) {
                    url.searchParams.set('genre', currentGenre);
                }
                this.href = url.toString();
            });
        });
    });
</script>

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "映画興行収入ランキング",
    "description": "世界と日本の映画興行収入ランキングデータベース",
    "url": "{{ url('/') }}"
}
</script>

<div class="attribution d-flex align-items-center justify-content-end gap-2 mt-3 mb-4">
    <small class="text-muted">映画データ提供:</small>
    <a href="https://www.themoviedb.org/" target="_blank" rel="noopener" class="text-decoration-none">
        <img src="https://www.themoviedb.org/assets/2/v4/logos/v2/blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg" 
             alt="TMDb" 
             style="height: 1.2rem; width: auto;">
    </a>
</div>
@endsection