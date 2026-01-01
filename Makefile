# Movie Project Makefile
# フロントエンド開発とデプロイのためのコマンド集

.PHONY: build dev restart deploy clean help

# ヘルプ（デフォルト）
help:
	@echo "使用可能なコマンド:"
	@echo "  make build    - フロントエンドをビルドしてコンテナを再起動"
	@echo "  make dev      - 開発サーバーを起動"
	@echo "  make restart  - コンテナを完全に再起動（キャッシュ問題解決用）"
	@echo "  make deploy   - 本番環境にデプロイ"
	@echo "  make clean    - Dockerボリュームをクリーンアップ"

# フロントエンドのビルド（開発環境）
# CSS/JSを編集した後に実行してください
build:
	@echo "🔨 フロントエンドをビルド中..."
	docker run --rm -v "$$(pwd)":/app -w /app node:20 npm run build
	@echo "🔄 コンテナを再起動中..."
	docker-compose down
	docker-compose up -d
	@echo "✅ ビルド完了！ブラウザをリロードしてください。"

# 開発サーバーの起動
dev:
	docker-compose up -d
	@echo "✅ 開発サーバーが起動しました: http://localhost:8000"

# コンテナの完全再起動（CSS反映問題の解決）
restart:
	@echo "🔄 コンテナを完全に再起動中..."
	docker-compose down
	docker-compose up -d
	@echo "✅ 再起動完了！"

# 本番デプロイ
deploy:
	@echo "🚀 本番環境にデプロイ中..."
	./deploy.sh

# Dockerボリュームのクリーンアップ
clean:
	@echo "🧹 Dockerボリュームをクリーンアップ中..."
	docker-compose down -v
	@echo "✅ クリーンアップ完了！"
