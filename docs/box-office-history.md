# 興行収入履歴（box office history）

このプロジェクトの興行収入履歴は、コスト優先のため **Git 上のファイル** で永続化しています。
本番は Cloudflare Pages の静的サイトのため、ランタイム DB は使わず、GitHub Actions が取得・蓄積・公開します。

## 構成

| パス | 内容 |
| --- | --- |
| `data/history/registry.json` | 映画の安定キー・別名・リダイレクト用マスタ |
| `data/history/observations/{region}/{YYYY-MM}.ndjson` | 興行収入の時系列（1行1観測） |

`region` は `global` または `japan` です。

観測レコードのスキーマ:

```json
{
  "key": "tmdb-19995",
  "observedAt": "2026-08-15T09:15:03+09:00",
  "boxOffice": 1234567890,
  "isActive": true,
  "correction": false
}
```

`correction` は前回より興行収入が減った場合に付与されます（任意フィールド）。

## 本番（CI）の運用

GitHub Actions（`Publish static movie ranking`）が次の流れで更新します。

1. 一時 MySQL へランキング取得
2. `data/history/` に履歴を追記
3. `data/history` をコミット・push
4. 静的サイトを生成して Cloudflare Pages へデプロイ

CI では `BOX_OFFICE_HISTORY_PATH` を **設定しません**（デフォルトの `data/history` を使用）。
`.env.example` をコピーするだけではこの値が入ってしまうため、有効な代入はコメントアウトし、ワークフロー側でも削除します。

## ローカル開発

ローカルで `movies:fetch-*` を実行すると履歴ファイルが更新されます。
Git の差分ノイズを避けるため、`.env` で別ディレクトリを使います。

```env
BOX_OFFICE_HISTORY_PATH=storage/box-office-history
```

初回はリポジトリ内の `data/history/` を自動コピーしてブートストラップします。
以降のローカル fetch は `storage/box-office-history/` のみ更新され、**Git には出ません**。

### ローカルでの注意

- `data/history/` の変更は **コミットしない**
- 最新の本番履歴が必要なら `main` を pull してから、ローカル履歴ディレクトリを削除して再ブートストラップ
- 誤って `data/history/` を更新した場合: `git restore data/history/`

静的サイトの出力確認:

```bash
npm run build
php artisan site:export-static
```

`site:export-static` も `BOX_OFFICE_HISTORY_PATH` を参照します。

## 将来の DB 移行

コードはストレージをインターフェース経由で扱うようになっています。

- `RegistryRepository` … 映画マスタ
- `ObservationRepository` … 時系列観測
- `config/box_office.php` の `driver` … 現在は `file` のみ

DB 移行時は `database` ドライバと実装クラスを追加し、fetch / export コマンドはそのまま使えます。

### エクスポート（移行用）

移行前に、ファイル形式の履歴をバンドルとして書き出せます。

```bash
php artisan box-office:export-history
```

デフォルト出力先: `storage/app/box-office-history-export/{timestamp}/`

含まれるもの:

- `manifest.json` … フォーマット定義・スキーマ
- `registry.json`
- `observations/{region}/*.ndjson`

任意のパスへ出力する場合:

```bash
php artisan box-office:export-history --output=/tmp/box-office-export
```

本番履歴（`data/history`）を直接エクスポートする場合（CI 相当）:

```bash
php artisan box-office:export-history --path=data/history --output=/tmp/box-office-export
```

DB 側では、おおよそ次のテーブルにマッピングできます。

- `box_office_movies` ← `registry.json` の `movies`
- `box_office_aliases` ← `registry.json` の `aliases`
- `box_office_observations` ← ndjson の各行

インポートコマンド（`box-office:import-history`）は DB 導入時に追加する想定です。
