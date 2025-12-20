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
    
    <!-- Custom CSS -->
    <!-- Custom CSS -->
    <style>
        /* --- CSS Variables & Reset --- */
        :root {
            --bg-main: #0a0a0a; /* 真っ黒に近いダークグレー */
            --bg-card: #161616; /* カード背景 */
            --text-primary: #ffffff;
            --text-secondary: #a0a0a0;
            --accent-gold: #FFD700; /* 1位や強調用 */
            --accent-blue: #3b82f6; /* SFなどのジャンル用 */
            --accent-red: #e50914; /* アクション用 */
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --font-num: 'Oswald', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: var(--font-body);
            line-height: 1.5;
            padding-bottom: 100px;
        }

        a { text-decoration: none; color: inherit; }

        /* --- Header Area --- */
        header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(10, 10, 10, 0.8);
            backdrop-filter: blur(12px); /* すりガラス効果 */
            border-bottom: 1px solid var(--glass-border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; /* モバイルでの折り返しを許可 */
            gap: 10px; /* 要素間の隙間 */
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            background: linear-gradient(to right, #fff, #aaa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Toggle Switch Design */
        .toggle-container {
            background: #222;
            border-radius: 30px;
            padding: 4px;
            display: flex;
            position: relative;
        }
        .toggle-btn {
            padding: 8px 24px;
            border-radius: 24px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            color: var(--text-secondary);
            text-decoration: none !important;
        }
        .toggle-btn:hover {
            color: #fff;
        }
        .toggle-btn.active {
            background: #444; /* 実際はJSでハイライト制御 */
            color: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.5);
        }

        /* --- Main Container --- */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .page-title {
            text-align: center;
            margin: 60px 0 40px;
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        /* --- Top 3 Podium (Hero Section) --- */
        .top-rankings {
            display: flex;
            justify-content: center;
            align-items: flex-end; /* 下揃えにして表彰台感を出す */
            gap: 20px;
            margin-bottom: 60px;
            flex-wrap: wrap;
        }

        .top-card {
            background: var(--bg-card);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            width: 300px;
            position: relative;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .top-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            border-color: rgba(255,255,255,0.3);
        }

        .top-card.rank-1 { order: 2; width: 340px; padding: 30px; border: 1px solid rgba(255, 215, 0, 0.3); background: linear-gradient(180deg, #1a1a1a 0%, #0f0f0f 100%); }
        .top-card.rank-2 { order: 1; }
        .top-card.rank-3 { order: 3; }

        .rank-badge {
            font-family: var(--font-num);
            font-size: 4rem;
            line-height: 1;
            font-weight: 700;
            margin-bottom: 10px;
            display: block;
        }
        .rank-1 .rank-badge { color: var(--accent-gold); text-shadow: 0 0 20px rgba(255, 215, 0, 0.4); }
        .rank-2 .rank-badge { color: #C0C0C0; }
        .rank-3 .rank-badge { color: #CD7F32; }

        .poster-placeholder {
            width: 100%;
            aspect-ratio: 2/3;
            background-color: #333;
            border-radius: 8px;
            margin-bottom: 15px;
            background-size: cover;
            background-position: center;
            transition: opacity 0.3s;
        }

        /* --- List View (Rank 4+) --- */
        .ranking-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .list-item {
            display: grid;
            /* Grid Layout: Rank | Image | Info | Revenue */
            grid-template-columns: 60px 70px 1fr 220px; 
            align-items: center;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            padding: 15px 20px;
            border-radius: 12px;
            transition: 0.2s;
            position: relative;
            overflow: hidden;
        }

        .list-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: scale(1.01);
        }

        /* Rank Number */
        .list-rank {
            font-family: var(--font-num);
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-align: center;
            z-index: 2;
        }

        /* Poster Thumb */
        .list-poster {
            width: 50px;
            height: 75px;
            border-radius: 6px;
            background-color: #333;
            background-size: cover;
            background-position: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.5);
            z-index: 2;
        }

        /* Info Area */
        .list-info {
            padding-left: 20px;
            z-index: 2;
        }
        .movie-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: block;
            color: var(--text-primary);
        }
        .tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tag {
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--text-secondary);
        }

        /* Revenue Area */
        .list-revenue {
            text-align: right;
            position: relative;
            z-index: 2;
        }
        .revenue-main {
            display: block;
            font-family: var(--font-num);
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .revenue-sub {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* Bar Graph Visualization */
        .revenue-bar-bg {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.08) 100%);
            z-index: 0;
            border-right: 2px solid rgba(255,255,255,0.1);
        }

        /* --- Responsive --- */
        /* --- Responsive --- */
        @media (max-width: 768px) {
            header {
                padding: 0.8rem 1rem;
                justify-content: center; /* 中央揃え */
            }
            .logo {
                width: 100%;
                text-align: center;
                margin-bottom: 10px;
            }
            .toggle-container {
                order: 2;
                margin-bottom: 10px;
            }
            .genre-filter-wrapper {
                order: 3;
                width: 100%;
                display: flex;
                justify-content: center;
                margin-left: 0 !important;
            }
            
            .top-rankings { 
                flex-direction: row; 
                flex-wrap: wrap; 
                justify-content: center; 
                align-items: stretch;
                gap: 10px;
                margin-bottom: 30px;
            }
            .top-card { 
                width: calc(50% - 5px); 
                max-width: none; 
                padding: 10px;
            } 
            .top-card.rank-1 { 
                order: 1; 
                width: 100%; 
                max-width: 400px; 
                padding: 15px;
                display: grid;
                grid-template-columns: 100px 1fr;
                grid-template-rows: auto auto auto; /* Title, Revenue, Budget */
                align-content: center;
                text-align: left;
                gap: 5px 15px; /* Row gap 5px, Col gap 15px */
            } 
            /* Rank 1 Layout Adjustments for Mobile */
            .top-card.rank-1 .poster-placeholder {
                grid-row: 1 / span 3;
                grid-column: 1;
                margin-bottom: 0;
                height: 150px;
                width: 100px;
            }
            .top-card.rank-1 .movie-title { 
                grid-row: 1; 
                grid-column: 2; 
                font-size: 1.1rem; 
                align-self: end;
                margin-bottom: 5px;
                /* 折り返し許可 */
                white-space: normal;
                display: -webkit-box;
                -webkit-line-clamp: 3;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .top-card.rank-1 .revenue-main { 
                grid-row: 2; 
                grid-column: 2; 
                font-size: 1.3rem; 
                line-height: 1.2;
            }
            .top-card.rank-1 .revenue-sub {
                grid-row: 3;
                grid-column: 2;
                align-self: start;
            }
            .top-card.rank-1 .rank-badge {
                position: absolute;
                top: -10px;
                left: -10px;
                font-size: 3rem;
                z-index: 10;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.8);
            }

            /* Rank 2 & 3 Adjustments */
            .top-card.rank-2 { order: 2; }
            .top-card.rank-3 { order: 3; }
            .top-card .rank-badge { font-size: 2rem; margin-bottom: 5px; }
            .top-card .movie-title { 
                font-size: 0.85rem; 
                line-height: 1.3;
                height: 2.6em; /* 2行分確保 */
                margin-bottom: 5px;
                /* 折り返し許可 */
                white-space: normal;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .top-card .revenue-main { font-size: 1rem; }
            .top-card .revenue-sub { font-size: 0.7rem; }
            
            .list-item {
                grid-template-columns: 40px 50px 1fr;
                gap: 10px;
                padding: 12px 15px; /* 少しパディングを詰める */
            }
            .list-info {
                padding-left: 10px; /* 余白を調整 */
                display: flex;
                align-items: center; /* タイトルを中央に */
            }
            .list-item .movie-title {
                margin-bottom: 0;
                font-size: 1rem;
            }
            .list-revenue {
                grid-column: 3; /* タイトルの右側に配置 */
                grid-row: 1;
                text-align: right;
                padding-left: 0;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: 2px;
            }
            .list-revenue .revenue-main {
                font-size: 1.1rem;
            }
            .list-revenue .revenue-sub {
                font-size: 0.65rem;
                margin-top: 0;
            }
        }

        /* --- Genre Filter Customization --- */
        .genre-filter-container {
            margin-bottom: 2rem;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .genre-select {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--glass-border);
            padding: 8px 16px;
            border-radius: 8px;
            outline: none;
        }

        .btn-filter {
            background: var(--accent-blue);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-filter:hover {
            opacity: 0.9;
        }
    </style>
    
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
</body>
</html>
