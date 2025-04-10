<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Movie Ranking - 世界と日本の映画興行収入ランキング。正確な興行収入データと詳細なジャンル分析を提供。">
    <meta name="keywords" content="映画,興行収入,ランキング,ボックスオフィス,日本映画,世界の映画,Movie Ranking">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}" />
    <title>Movie Ranking | 世界と日本の映画興行収入ランキング</title>
    
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
            <a class="navbar-brand" href="{{ url('/') }}">🎬 Movie Ranking</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ url('/') }}">ホーム</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- メインコンテンツ -->
    <div class="main-content">
        <div class="container">
            @yield('content')
        </div>
    </div>

    <!-- フッター -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h5>Movie Ranking</h5>
                    <p>世界と日本の映画興行収入を比較できる、信頼性の高いランキングサイトです。</p>
                </div>
                <div class="col-md-4">
                    <h5>リンク</h5>
                    <ul class="list-unstyled">
                        <li><a href="{{ url('/') }}" class="text-decoration-none">ホーム</a></li>
                    </ul>
                </div>
            </div>
            <hr>
            <div class="text-center">
                <p>&copy; {{ date('Y') }} Movie Ranking. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    @yield('scripts')
</body>
</html>
