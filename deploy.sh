#!/bin/bash

# エラー発生時に停止
set -e

echo "🚀 Starting Deployment Process..."

# .envファイルの読み込み
if [ -f .env ]; then
    echo "📄 Loading environment variables from .env..."
    export $(grep -v '^#' .env | xargs)
fi

# 1. データベースのバックアップ (既存のMySQLが動いている場合)
if systemctl is-active --quiet mysql; then
    echo "📦 Backing up existing database..."
    mkdir -p backups
    mysqldump -u ${DB_USERNAME:-movie_user} -p${DB_PASSWORD:-movie_password} ${DB_DATABASE:-movie_db} > backups/backup_$(date +%Y%m%d_%H%M%S).sql
    echo "✅ Backup created."
fi

# 2. 既存サービスの停止 (MySQLのみ停止、Nginxはホスト側で使用するため停止しない)
echo "🛑 Stopping existing services..."
sudo systemctl stop mysql || true
sudo systemctl disable mysql || true

# 3. 最新コードの取得
echo "⬇️ Pulling latest code..."
# ローカルの変更を破棄して最新の状態にする
git checkout public/sw.js 2>/dev/null || true
rm -f .env.cron 2>/dev/null || true
git pull origin features/movie_master

# 3.5. Service Workerキャッシュバージョンの更新（キャッシュバスティング）
echo "🔄 Updating Service Worker cache version..."
NEW_VERSION=$(date +%Y%m%d%H%M%S)
if [ -f public/sw.js ]; then
    sed -i "s/const CACHE_VERSION = '[^']*'/const CACHE_VERSION = 'v${NEW_VERSION}'/" public/sw.js
    echo "✅ SW cache version updated to: v${NEW_VERSION}"
else
    echo "⚠️ public/sw.js not found, skipping SW version update"
fi

# 4. Dockerコンテナのビルドと起動 (キャッシュバスティングのため、appコンテナと匿名ボリュームを再作成)
echo "🐳 Building and starting Docker containers..."
docker compose -f docker-compose.prod.yml stop app
docker compose -f docker-compose.prod.yml rm -f -v app
docker compose -f docker-compose.prod.yml up -d --build

# 5. アプリケーションのセットアップ
echo "⏳ Waiting for database connection..."
until docker compose -f docker-compose.prod.yml exec app php artisan db:monitor > /dev/null 2>&1; do
    echo "Waiting for database..."
    sleep 3
done

echo "⚙️ Running application setup..."
docker compose -f docker-compose.prod.yml exec app php artisan storage:link --force
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

echo "✅ Deployment completed successfully!"
echo "🌐 Application should be running on port 80"
