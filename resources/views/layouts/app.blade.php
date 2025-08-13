<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ムビラン - 最新映画興行収入ランキング。世界と日本の映画売上データをリアルタイムで提供。正確な興行収入データと詳細なジャンル分析を提供。">
    <meta name="keywords" content="映画,興行収入,ランキング,売上,日本映画,世界の映画,最新,ムビラン,ボックスオフィス,映画統計,映画データ,映画売上,興行成績,映画ランキング,最新映画">
    @if(count(request()->query()) > 0)
    <meta name="robots" content="noindex, follow">
    @else
    <meta name="robots" content="index, follow">
    @endif
    <meta name="author" content="ムビラン">
    <meta name="language" content="ja">
    @hasSection('canonical')
        @yield('canonical')
    @else
        <link rel="canonical" href="{{ url()->current() }}" />
    @endif
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#2c3e50">
    
    <title>最新映画興行収入ランキング - 世界と日本の映画売上データ</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        .navbar-brand {
            font-weight: bold;
        }
        .navbar {
            background-color: white !important;
            border-bottom: 1px solid #eee;
        }
        .footer {
            background-color: white;
            padding: 2rem 0;
            margin-top: 3rem;
            border-top: 1px solid #eee;
        }
        .main-content {
            min-height: calc(100vh - 300px);
            padding: 2rem 0;
        }
        .data-source {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .data-source a {
            color: #666;
            text-decoration: none;
        }
        .data-source a:hover {
            text-decoration: underline;
        }
    </style>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <!-- ヘッダー -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="{{ rtrim(config('app.url'), '/') }}/">🎬 ムビラン | 最新映画興行収入ランキング</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ rtrim(config('app.url'), '/') }}/">ホーム</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- メインコンテンツ -->
    <div class="main-content">
        <div class="container">
            <!-- パンくずリスト（構造化データ） -->
            <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "BreadcrumbList",
                "itemListElement": [
                    {
                        "@type": "ListItem",
                        "position": 1,
                        "item": {
                             "@id": "{{ rtrim(config('app.url'), '/') }}/",
                            "name": "ホーム"
                        }
                    }
                ]
            }
            </script>
            
            <!-- サイト情報（構造化データ） -->
            <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "WebSite",
                "name": "ムビラン - 最新映画興行収入ランキング",
                "alternateName": "Movie Ranking",
                "description": "世界と日本の映画興行収入・統計データ",
                 "url": "{{ rtrim(config('app.url'), '/') }}/",
                "logo": "{{ asset('images/android-chrome-512x512.png') }}",
                "sameAs": [],
                "potentialAction": {
                    "@type": "SearchAction",
                     "target": "{{ rtrim(config('app.url'), '/') }}/?search={search_term_string}",
                    "query-input": "required name=search_term_string"
                }
            }
            </script>
            
            <!-- 組織情報（構造化データ） -->
            <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "Organization",
                "name": "ムビラン",
                "alternateName": "Movie Ranking",
                "description": "映画興行収入ランキングサイト",
                 "url": "{{ rtrim(config('app.url'), '/') }}/",
                "logo": "{{ asset('images/android-chrome-512x512.png') }}",
                "sameAs": []
            }
            </script>
            
            @yield('content')
        </div>
    </div>

    <!-- フッター -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h5>ムビラン - 最新映画興行収入ランキング</h5>
                    <p>世界と日本の映画興行収入を比較できる、信頼性の高いランキングサイトです。</p>
                </div>
                <div class="col-md-4">
                    <h5>リンク</h5>
                    <ul class="list-unstyled">
                        <li><a href="{{ rtrim(config('app.url'), '/') }}/" class="text-decoration-none">ホーム</a></li>
                        <li><a href="{{ rtrim(config('app.url'), '/') }}/?tab=global" class="text-decoration-none">世界興行収入ランキング</a></li>
                        <li><a href="{{ rtrim(config('app.url'), '/') }}/?tab=japan" class="text-decoration-none">日本興行収入ランキング</a></li>
                        <li><a href="{{ rtrim(config('app.url'), '/') }}/sitemap.xml" class="text-decoration-none">サイトマップ</a></li>
                    </ul>
                </div>
            </div>
            <hr>
            <div class="text-center">
                <p>&copy; {{ date('Y') }} ムビラン - 最新映画興行収入ランキング. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    @yield('scripts')
</body>
</html>
