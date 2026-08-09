# Cloudflare Pages への公開手順

このプロジェクトは、常時稼働するサーバーなしで映画ランキングを公開できます。
GitHub Actionsが一時的なMySQLへランキングを取得し、公開用の静的ファイルを生成してCloudflare Pagesへ配置します。公開サイトやブラウザにはTMDb APIキーを渡しません。

## 事前準備

1. TMDbで新しいAPIキーを発行し、古いキーは無効化します。
2. CloudflareでPagesプロジェクトを作成します。
3. GitHubに3つのSecretsを登録します。
4. GitHub Actionsを手動実行して、初回公開を行います。

## 1. TMDb APIキーを再発行する

TMDbの開発者用設定画面で、新しいAPIキーを発行します。古いキーは過去にリポジトリへ含まれていたため、再利用せず無効化してください。

新しいキーは次の手順でGitHubへ登録します。キーをソースコード、`.env.example`、チャット、Cloudflareの公開設定へ貼り付けないでください。

1. GitHubで `TemaTech/movie_project` を開きます。
2. **Settings** → **Secrets and variables** → **Actions** を開きます。
3. **New repository secret** を押します。
4. Nameに `TMDB_API_KEY`、Secretに新しいTMDb APIキーを入力します。
5. **Add secret** を押します。

登録済みのSecretの値は後から表示できません。名前が `TMDB_API_KEY` と表示されれば完了です。GitHub ActionsのSecretは暗号化して保管され、ワークフロー実行時だけ利用されます。

## 2. Cloudflare Pagesプロジェクトを作る

1. Cloudflareへログインし、**Workers & Pages** を開きます。
2. **Create application** → **Pages** → **Upload assets** を選びます。
3. Project nameに `movie-ranking` と入力して作成します。

ここでは仮のファイルをアップロードしても、まだ公開しなくても構いません。GitHub Actionsが最初の公開ファイルをアップロードします。

公開URLは `https://movie-ranking.pages.dev` です。

## 3. Cloudflareの認証情報をGitHubへ登録する

CloudflareでAPI Tokenを作成します。

1. 右上のプロフィール → **My Profile** → **API Tokens** を開きます。
2. **Create Token** → **Create Custom Token** を選びます。
3. Token nameは `github-pages-deploy` とします。
4. Permissionsに **Account / Cloudflare Pages / Edit** を追加します。
5. Account Resourcesは、このCloudflareアカウントを対象にします。
6. 作成後に表示されるトークンをコピーします。この画面を閉じると再表示できません。

GitHubの **Settings** → **Secrets and variables** → **Actions** で、以下の2つを追加します。

| Secret名 | 入力する値 |
| --- | --- |
| `CLOUDFLARE_API_TOKEN` | 先ほど作成したCloudflare API Token |
| `CLOUDFLARE_ACCOUNT_ID` | Cloudflareダッシュボードに表示されるAccount ID |

## 4. 初回公開

変更をGitHubへpushした後、GitHubリポジトリの **Actions** を開きます。

1. 左の **Publish static movie ranking** を選びます。
2. **Run workflow** → **Run workflow** を押します。
3. 完了するまで待ちます。初回はデータ取得と画像保存があるため、数分かかります。

緑のチェックが表示されたら、`https://movie-ranking.pages.dev` を開きます。世界・日本のランキング、タイトル検索、ジャンル／アニメ・実写の絞り込みが使えれば成功です。

以後は毎日12:17頃（日本時間）に自動更新されます。更新に失敗しても、前回成功した公開版はそのまま表示されます。

## ローカルでの静的ファイル出力

データベースへランキングを取得済みで、フロントエンドをビルド済みの場合は、次のコマンドで公開ファイルを作れます。

```bash
npm run build
php artisan site:export-static
```

出力先は `dist/` です。ここには生成済みのデータや画像を含むため、Gitへは追加しません。
