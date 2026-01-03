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
git pull origin features/movie_master 2>&1 | grep -E "(Already up to date|Updating|Fast-forward|files changed)" || true

# 3.5. Service Workerキャッシュバージョンの更新
NEW_VERSION=$(date +%Y%m%d%H%M%S)
if [ -f public/sw.js ]; then
    sed -i "s/const CACHE_VERSION = '[^']*'/const CACHE_VERSION = 'v${NEW_VERSION}'/" public/sw.js
    echo "🔄 SW cache version: v${NEW_VERSION}"
fi

# 4. Dockerコンテナのビルドと起動
echo "🐳 Building Docker containers..."
docker compose -f docker-compose.prod.yml stop app > /dev/null 2>&1
docker compose -f docker-compose.prod.yml rm -f -v app > /dev/null 2>&1
docker compose -f docker-compose.prod.yml up -d --build --quiet-pull --progress=quiet > /dev/null 2>&1
echo "   ✅ Containers built and started"

# 5. アプリケーションのセットアップ
echo "⏳ Waiting for database..."
until docker compose -f docker-compose.prod.yml exec -T app php artisan db:monitor > /dev/null 2>&1; do
    sleep 2
done
echo "   ✅ Database connected"

echo "⚙️  Setting up application..."

# キャッシュをクリア
docker compose -f docker-compose.prod.yml exec -T app php artisan cache:clear --quiet
docker compose -f docker-compose.prod.yml exec -T app php artisan config:clear --quiet
docker compose -f docker-compose.prod.yml exec -T app php artisan route:clear --quiet
docker compose -f docker-compose.prod.yml exec -T app php artisan view:clear --quiet

# Composerオートロードを再生成
docker compose -f docker-compose.prod.yml exec -T app git config --global --add safe.directory /var/www/html 2>/dev/null || true
docker compose -f docker-compose.prod.yml exec -T app composer dump-autoload -o --quiet 2>/dev/null

# ストレージリンクとマイグレーション
docker compose -f docker-compose.prod.yml exec -T app php artisan storage:link --force --quiet 2>/dev/null || true
MIGRATION_OUTPUT=$(docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force 2>&1)
if echo "$MIGRATION_OUTPUT" | grep -q "Migrating"; then
    echo "   📊 Migrations applied"
fi

# キャッシュを再構築
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache --quiet
docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache --quiet
docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache --quiet
echo "   ✅ Application setup complete"

echo ""
echo "✅ ========== DEPLOYMENT COMPLETE =========="
echo "🌐 Application running on port 80"
echo ""
