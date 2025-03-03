<!DOCTYPE html>
<html>
<head>
    <!-- 既存のhead要素 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <!-- 既存のコンテンツ -->
    @yield('content')

    <!-- 以下のスクリプトを</body>タグの直前に追加 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
