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

# 2. 既存サービスの停止
echo "🛑 Stopping existing services..."
sudo systemctl stop nginx || true
sudo systemctl stop mysql || true
sudo systemctl disable nginx || true
sudo systemctl disable mysql || true

# 3. 最新コードの取得
echo "⬇️ Pulling latest code..."
git pull origin features/movie_master

# 4. Dockerコンテナのビルドと起動
echo "🐳 Building and starting Docker containers..."
docker compose -f docker-compose.prod.yml up -d --build

# 5. アプリケーションのセットアップ
echo "⚙️ Running application setup..."
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

echo "✅ Deployment completed successfully!"
echo "🌐 Application should be running on port 80"
