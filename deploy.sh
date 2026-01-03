#!/bin/bash

# エラー発生時に停止
set -e

echo ""
echo "🚀 ========== DEPLOYMENT START =========="
echo ""

# .envファイルの読み込み
if [ -f .env ]; then
    export $(grep -v '^#' .env | xargs) 2>/dev/null
fi

# 1. データベースのバックアップ (既存のMySQLが動いている場合)
if systemctl is-active --quiet mysql 2>/dev/null; then
    echo "📦 Backing up database..."
    mkdir -p backups
    mysqldump -u ${DB_USERNAME:-movie_user} -p${DB_PASSWORD:-movie_password} ${DB_DATABASE:-movie_db} > backups/backup_$(date +%Y%m%d_%H%M%S).sql 2>/dev/null
    echo "   ✅ Backup created"
fi

# 2. 既存サービスの停止 (MySQLのみ停止)
echo "🛑 Stopping MySQL service..."
sudo systemctl stop mysql 2>/dev/null || true
sudo systemctl disable mysql 2>/dev/null || true

# 3. 最新コードの取得
echo "⬇️  Pulling latest code..."
git checkout public/sw.js 2>/dev/null || true
rm -f .env.cron 2>/dev/null || true
git pull origin features/movie_master

# 3.5. Service Workerキャッシュバージョンの更新（キャッシュバスティング）
NEW_VERSION=$(date +%Y%m%d%H%M%S)
if [ -f public/sw.js ]; then
    sed -i "s/const CACHE_VERSION = '[^']*'/const CACHE_VERSION = 'v${NEW_VERSION}'/" public/sw.js
    echo "🔄 SW cache version: v${NEW_VERSION}"
else
    echo "⚠️ public/sw.js not found, skipping SW version update"
fi

# 4. Dockerコンテナのビルドと起動 (キャッシュバスティングのため、appコンテナと匿名ボリュームを再作成)
echo "🐳 Building Docker containers... (this may take 30-60 seconds)"
docker compose -f docker-compose.prod.yml stop app
docker compose -f docker-compose.prod.yml rm -f -v app
docker compose -f docker-compose.prod.yml up -d --build
echo "   ✅ Containers built and started"

# 5. アプリケーションのセットアップ
echo "⏳ Waiting for database..."
until docker compose -f docker-compose.prod.yml exec app php artisan db:monitor > /dev/null 2>&1; do
    sleep 3
done
echo "   ✅ Database connected"

echo "⚙️  Setting up application..."

# キャッシュをクリア（旧キャッシュによる不整合を防止）
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker compose -f docker-compose.prod.yml exec app php artisan config:clear
docker compose -f docker-compose.prod.yml exec app php artisan route:clear
docker compose -f docker-compose.prod.yml exec app php artisan view:clear

# Composerオートロードを再生成
# Gitのsafe.directory警告を消す
docker compose -f docker-compose.prod.yml exec app git config --global --add safe.directory /var/www/html
docker compose -f docker-compose.prod.yml exec app composer dump-autoload -o

# ストレージリンクとマイグレーション
docker compose -f docker-compose.prod.yml exec app php artisan storage:link --force
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# キャッシュを再構築
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

echo "   ✅ Application setup complete"

echo ""
echo "✅ ========== DEPLOYMENT COMPLETE =========="
echo "🌐 Application running on port 80"
echo ""
