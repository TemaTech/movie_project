---
description: フロントエンド（CSS/JS）を編集した後のビルドと反映方法
---

# フロントエンドビルド手順

CSS/JavaScriptファイルを編集した後、以下の手順でビルドを反映させてください。

## 推奨方法：make コマンド

// turbo
```bash
make build
```

このコマンドは以下を自動で実行します：
1. Node.js Dockerコンテナでnpm run build
2. docker-compose down && up（コンテナ再作成）

## 手動でビルドする場合

### 1. Viteビルドを実行

```bash
docker run --rm -v "$(pwd)":/app -w /app node:20 sh -c "npm ci && npm run build"
```

### 2. コンテナを再作成

**重要**: `restart`ではなく`down && up`が必要です。

```bash
docker-compose down
docker-compose up -d
```

## トラブルシューティング

### CSSの変更がブラウザに反映されない

1. ブラウザキャッシュをクリア（Cmd+Shift+R / Ctrl+Shift+R）
2. それでも反映されない場合は`make build`を実行
3. 読み込まれているCSSファイル名を確認:
   - DevTools > Network > CSS で`cinematic-*.css`のハッシュ値を確認
   - 古いハッシュの場合はコンテナ再作成が必要

### なぜ`docker-compose restart`では不十分？

開発環境ではボリュームマウントを使用していますが、コンテナ内のファイルキャッシュが残る場合があります。
`docker-compose down && up`で完全に再作成することで解決します。
