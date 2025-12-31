<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '歴代映画興行収入ランキング | 世界・日本のヒット作を徹底分析 - MUBIRAN')</title>
    <meta name="description" content="@yield('description', '「アバター」「鬼滅の刃」など、世界と日本の歴代ヒット映画の興行収入ランキングを完全網羅。興収だけでなく制作費や利益率まで可視化。あなたの好きな映画は今何位？最新データをリアルタイムで更新中。')">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Oswald:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Vite で管理されるCSS -->
    @vite(['resources/css/cinematic.css'])
    
    @yield('head')
</head>
<body>
    @yield('content')

    <footer style="text-align: center; padding: 40px 20px; color: var(--text-secondary); border-top: 1px solid var(--glass-border); margin-top: 60px;">
        <div class="container">
            <p style="margin-bottom: 10px;">&copy; {{ date('Y') }} MUBIRAN. All rights reserved.</p>
            <p style="font-size: 0.8rem;">
                Data provided by <a href="https://www.themoviedb.org/" target="_blank" style="color: var(--accent-blue);">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" style="color: var(--accent-blue);">Wikipedia</a>.
            </p>
        </div>
    </footer>
    
    @yield('scripts')
    
    <!-- Service Worker 登録 -->
    <script>
        // Service Worker 登録
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then((registration) => {
                        console.log('SW registered:', registration.scope);
                        
                        // 新しいSWが利用可能になったらログ出力
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'activated') {
                                    console.log('New Service Worker activated, cache updated');
                                }
                            });
                        });
                    })
                    .catch((error) => {
                        console.log('SW registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>
