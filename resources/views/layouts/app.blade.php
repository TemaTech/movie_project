<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="映画の興行収入ランキングデータベース。世界と日本の映画興行収入、制作費、公開日などの情報を提供。">
    <meta name="keywords" content="映画,興行収入,ランキング,ボックスオフィス,日本映画,世界の映画">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}" />
    <title>映画興行収入ランキング | グローバル・日本の映画データベース</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div id="app">
        @yield('content')
    </div>
</body>
</html>
