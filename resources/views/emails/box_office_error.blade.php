<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>エラー通知</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        .header {
            background-color: #d9534f;
            color: white;
            padding: 10px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            padding: 20px;
            background-color: white;
            border: 1px solid #eee;
        }
        .error-message {
            background-color: #ffeeee;
            border-left: 5px solid #d9534f;
            padding: 10px;
            margin-bottom: 20px;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .stack-trace {
            font-size: 0.8em;
            color: #666;
            background-color: #f5f5f5;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
            white-space: pre;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 0.8em;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>エラー発生通知: {{ $commandName }}</h2>
        </div>
        <div class="content">
            <p>{{ $commandName }}の取得処理実行中にエラーが発生しました。</p>
            
            <h3>エラー内容</h3>
            <div class="error-message">
                {{ $error->getMessage() }}
            </div>

            <h3>発生日時</h3>
            <p>{{ now()->format('Y-m-d H:i:s') }}</p>

            <h3>スタックトレース（抜粋）</h3>
            <div class="stack-trace">
{{ substr($error->getTraceAsString(), 0, 2000) }}...
            </div>
        </div>
        <div class="footer">
            <p>このメールはシステムから自動送信されています。</p>
        </div>
    </div>
</body>
</html>
