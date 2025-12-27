# Production Deployment Guide

このガイドでは、既存のサーバー環境からDocker環境へ移行し、デプロイする手順を説明します。

## 前提条件

- サーバーに`docker`および`docker-compose-plugin` (または `docker-compose`) がインストールされていること。
- `git` がインストールされていること。

## 移行手順

1. **サーバーへのSSH接続**
   ```bash
   ssh user@your-server-ip
   ```

2. **プロジェクトディレクトリへ移動**
   ```bash
   cd /path/to/movie_project
   ```

3. **デプロイスクリプトの実行**
   `deploy.sh` スクリプトを使用することで、既存のNginx/MySQLを停止し、Dockerコンテナを立ち上げることができます。
   **注意:** このスクリプトは既存のデータベースバックアップを試みますが、念のため手動でもバックアップを取ることを推奨します。

   ```bash
   ./deploy.sh
   ```

   もし権限エラーが出る場合は `sudo ./deploy.sh` を実行してください。

## DBデータの移行 (必要な場合)

Docker環境への切替時、既存のMySQLデータは自動的には引き継がれません（新しいボリュームが作成されます）。
バックアップデータをインポートする場合は以下の手順を実行してください。

1. **バックアップファイルの確認**
   `backups/` ディレクトリに `.sql` ファイルが生成されているはずです。

2. **コンテナへのインポート**
   ```bash
   # 例: backups/backup_20251227.sql をインポートする場合
   cat backups/backup_20251227.sql | sudo docker compose -f docker-compose.prod.yml exec -T db mysql -u movie_user -pmovie_password movie_db
   ```

## 運用コマンド

- **ログの確認**
  ```bash
  sudo docker compose -f docker-compose.prod.yml logs -f app
  ```

- **コンテナの再起動**
  ```bash
  sudo docker compose -f docker-compose.prod.yml restart
  ```

- **手動でのマイグレーション**
  ```bash
  sudo docker compose -f docker-compose.prod.yml exec app php artisan migrate
  ```
