<?php

namespace App\Console\Commands;

use App\Models\JapaneseMovie;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\BoxOfficeFetchError;

class FetchJapaneseBoxOffice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'movies:fetch-japanese-boxoffice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Japanese box office data from Wikipedia';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // 非推奨警告を抑制
            error_reporting(E_ALL & ~E_DEPRECATED);
            
            // ログ出力を最小限に
            DB::connection()->disableQueryLog();
            Log::withoutContext();
            
            $this->info('Wikipediaからデータ取得を開始...');
            
            $response = Http::wikimedia()->get('https://ja.wikipedia.org/w/api.php', [
                'action' => 'parse',
                'page' => '日本歴代興行成績上位の映画一覧',
                'prop' => 'wikitext',
                'format' => 'json',
                'redirects' => 'true'
            ]);
            // Wikimediaのレートリミット配慮
            usleep(500000); // 0.5秒待機

            if ($response->successful()) {
                $this->info('Wikipediaからのレスポンス取得成功');
                
                // デバッグ: Wikiテキストの内容を確認
                $wikitext = $response->json()['parse']['wikitext']['*'] ?? '';
                $this->info('取得したWikiテキストの一部:');
                $this->info(substr($wikitext, 0, 500) . '...');
                
                // テーブル抽出のデバッグ
                if (preg_match('/==\s*総合ランキング\s*==.*?{\|.*?wikitable.*?\|\}/s', $wikitext, $match)) {
                    $this->info('テーブル抽出結果:');
                    $this->info(substr($match[0], 0, 500) . '...');
                } else {
                    $this->error('テーブルが見つかりませんでした');
                }
                
                $moviesData = $this->parseWikipediaResponse($response->json());
                
                if (!empty($moviesData)) {
                    $this->info(sprintf('解析された映画データ: %d件', count($moviesData)));
                    
                    // データのサンプルを表示
                    $this->info('最初の3件のデータ:');
                    foreach (array_slice($moviesData, 0, 3) as $movie) {
                        $this->info(sprintf(
                            '順位: %d, タイトル: %s, 興行収入: %.1f億円',
                            $movie['rank'],
                            $movie['title'],
                            $movie['box_office'] / 100000000
                        ));
                    }

                    // 既存のトランザクションがあれば終了
                    try {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                            $this->info('既存のトランザクションをロールバックしました');
                        }
                    } catch (\Exception $e) {
                        // 既存のトランザクションがない場合は無視
                    }
                    
                    try {
                        // 既存のデータを一旦全て削除（truncateは暗黙的にコミットするため、トランザクション外で実行）
                        JapaneseMovie::query()->delete();
                        $this->info('既存のデータを削除しました');
                        
                        DB::beginTransaction();
                        $this->info('トランザクションを開始しました');
                        
                        // チャンクに分割してバルクインサート
                        foreach (array_chunk($moviesData, 50) as $chunkIndex => $chunk) {
                            JapaneseMovie::insert($chunk);
                            $this->info(sprintf('チャンク %d/%d を挿入しました', $chunkIndex + 1, count(array_chunk($moviesData, 50))));
                        }
                        
                        DB::commit();
                        $this->info('トランザクションをコミットしました');
                        $this->info(sprintf('データベースに%d件の映画データを保存しました', count($moviesData)));
                    } catch (\Exception $e) {
                        $this->error('データベース処理中にエラーが発生しました: ' . $e->getMessage());
                        Log::error('Japanese movie database operation error:', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // トランザクションがアクティブかチェックしてからロールバック
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                            $this->info('トランザクションをロールバックしました');
                        }
                        throw $e;
                    }
                } else {
                    $this->error('映画データの解析に失敗しました');
                }
            } else {
                $this->error('Wikipediaからのデータ取得に失敗しました');
                $this->info('レスポンス: ' . $response->body());
            }
        } catch (\Exception $e) {
            $this->error('エラー発生: ' . $e->getMessage());
            $this->error('スタックトレース: ' . $e->getTraceAsString());

            // エラーメール送信
            try {
                Mail::to('horiuchi.cadd9@gmail.com')->send(new BoxOfficeFetchError($e, '日本歴代興行成績'));
                $this->info('エラー通知メールを送信しました');
            } catch (\Exception $mailException) {
                $this->error('エラー通知メールの送信に失敗しました: ' . $mailException->getMessage());
            }
            
            // 最終的なトランザクションのクリーンアップ
            try {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                    $this->info('最終的なトランザクションをロールバックしました');
                }
            } catch (\Exception $cleanupError) {
                $this->error('トランザクションクリーンアップ中にエラー: ' . $cleanupError->getMessage());
            }
        }
    }

    private function parseWikipediaResponse($response)
    {
        try {
            $pageContent = $response['parse']['wikitext']['*'] ?? '';
            if (empty($pageContent)) {
                $this->error('ページコンテンツが空です');
                return [];
            }
    
            $this->info('Wikiテキストの解析を開始...');
            
            $movies = [];
            $currentRank = null;
            $rankBoxOfficeMap = []; // 順位ごとの興行収入を保存

            // 総合ランキングのテーブルを抽出（1-100位と101-200位の両方を取得）
            $tableContent = $this->extractRankingTables($pageContent);
            if ($tableContent !== null) {
                $this->info('総合ランキングのテーブルを検出しました');
                
                // 最初にテーブル全体から順位と興行収入のマッピングを作成
                $this->createRankBoxOfficeMapping($tableContent, $rankBoxOfficeMap);
                $this->info('順位と興行収入のマッピングを作成しました');
                
                // 行に分割
                $rows = explode("\n|-", $tableContent);
                $rowspanRank = 0;
                
                foreach ($rows as $rowIndex => $row) {
                    // テーブルの開始・終了記号をスキップ
                    if (strpos($row, '{|') !== false || strpos($row, '|}') !== false) {
                        continue;
                    }
    
                    // 各行のデータを抽出
                    $lines = explode("\n", $row);
                    
                    // 映画データの初期化
                    $title = '';
                    $productionCountry = '日本';  // デフォルト値
                    $distributor = '';
                    $releaseYear = null;
                    
                    // rowspanの処理
                    if ($rowspanRank > 0) {
                        // 前の行の順位を継続使用
                        $rowspanRank--;
                    } else {
                        // 順位を抽出
                        foreach ($lines as $line) {
                            $line = trim($line);
                            // rowspan付きの順位を検出
                            if (preg_match('/^! rowspan="(\d+)" \|(\d+)$/', $line, $matches)) {
                                $rowspanRank = (int)$matches[1] - 1; // 現在の行を含むため-1
                                $currentRank = (int)$matches[2];
                                break;
                            } 
                            // 通常の順位を検出
                            elseif (preg_match('/^!(\d+)$/', $line, $matches)) {
                                $currentRank = (int)$matches[1];
                                break;
                            }
                        }
                    }
                    
                    // タイトルと他の情報を抽出
                    $titleFound = false;
                    $distributorFound = false;
                    
                    foreach ($lines as $lineIndex => $line) {
                        $line = trim($line);
                        
                        // タイトルを抽出（最初のカラム）
                        if (!$titleFound && $lineIndex > 0 && preg_match('/^\|(.+)$/', $line, $matches)) {
                            $titleText = $matches[1];
                            // HTMLタグを除去
                            $titleText = preg_replace('/<br\s*\/?>/i', ' ', $titleText);
                            
                            // Wikiリンク形式のタイトルを抽出
                            if (preg_match('/\[\[([^|\]]+)(?:\|([^\]]+))?\]\]/', $titleText, $titleMatches)) {
                                $title = isset($titleMatches[2]) ? $titleMatches[2] : $titleMatches[1];
                            } else {
                                $title = trim($titleText);
                            }
                            $titleFound = true;
                            continue;
                        }
                        
                        // 製作国を抽出（3列目）
                        if (preg_match('/\|(日本|アメリカ|イギリス|フランス|韓国|中国|香港)/', $line, $countryMatch)) {
                            $productionCountry = $countryMatch[1];
                            continue;
                        }
                        
                        // 配給会社を抽出（5列目）
                        if (!$distributorFound && preg_match('/\|(.+)$/', $line, $matches) && 
                            (strpos($line, '東宝') !== false || 
                             strpos($line, 'ディズニー') !== false || 
                             strpos($line, 'ワーナー') !== false ||
                             strpos($line, 'FOX') !== false ||
                             strpos($line, 'UIP') !== false ||
                             strpos($line, 'CIC') !== false ||
                             strpos($line, '東映') !== false ||
                             strpos($line, '松竹') !== false ||
                             strpos($line, 'ヘラルド') !== false)) {
                            
                            $distText = $matches[1];
                            // Wikiリンク形式の配給会社を抽出
                            if (preg_match('/\[\[([^|\]]+)(?:\|([^\]]+))?\]\]/', $distText, $distMatches)) {
                                $distributor = isset($distMatches[2]) ? $distMatches[2] : $distMatches[1];
                            } else {
                                $distributor = trim($distText);
                            }
                            $distributorFound = true;
                            continue;
                        }
                        
                        // 公開年を抽出（6列目）
                        if (preg_match('/\|\s*(?:style="text-align:center"\s*\|\s*)?(\d{4})(?:\s*年)?(?:\s*\||$)/', $line, $yearMatch)) {
                            $releaseYear = $yearMatch[1] . '-01-01';
                            continue;
                        }
                    }
                    
                    // 200位までに制限（安全弁）
                    if ($currentRank > 200) break;
                    
                    // 順位に対応する興行収入を取得
                    $boxOffice = $rankBoxOfficeMap[$currentRank] ?? 0;
                    
                    // 映画データの追加
                    if ($currentRank !== null && !empty($title) && $boxOffice > 0) {
                        // Wikidataから追加情報を取得（QID解決→詳細取得）
                        $wikidataGenres = [];
                        // 予算はTMDBのみ使用
                        $wikidataBudgetYen = 0;
                        $wikidataReleaseDate = $releaseYear;
                        $tmdbGenres = [];

                        // パフォーマンス最適化のため、Wikidata呼び出しはスキップ
                        // 公開年はWikipediaテーブルから抽出した値を正規化して使用
                        $wikidataGenres = [];
                        $wikidataReleaseDate = $this->sanitizeReleaseDate($releaseYear, $releaseYear);

                        // TMDBからジャンルと制作費をまとめて取得（APIリクエスト削減）
                        $tmdbDetails = $this->fetchTMDBDetails($title, $wikidataReleaseDate);
                        
                        $tmdbGenres = $tmdbDetails['genres'];
                        $tmdbBudgetYen = $tmdbDetails['budget'];

                        if (!empty($tmdbGenres)) {
                            $this->info(sprintf('TMDBジャンル使用: %s => [%s]', $title, implode(', ', $tmdbGenres)));
                        } elseif (!empty($wikidataGenres)) {
                            $this->info('TMDBジャンル未取得のためWikidataジャンルを使用');
                        }

                        $budgetToSave = $tmdbBudgetYen > 0 ? $tmdbBudgetYen : 0; // TMDBのみ、なければ0
                        if ($tmdbBudgetYen > 0) {
                            $this->info(sprintf('TMDB制作費使用: %s => %.1f億円', $title, $tmdbBudgetYen / 100000000));
                        } else {
                            $this->info('TMDB制作費未取得のため、制作費は未設定（0）');
                        }

                        $genresToSave = !empty($tmdbGenres) ? $tmdbGenres : $wikidataGenres;
                        $movieData = [
                            'movie_id' => $this->createMovieId($currentRank, $title, $distributor, $wikidataReleaseDate),
                            'title' => $title,
                            'box_office' => $boxOffice,
                            'rank' => $currentRank,
                            'production_country' => $productionCountry,
                            'distributor' => $distributor,
                            'release_date' => $wikidataReleaseDate,
                            'last_updated' => now(),
                            'genres' => json_encode($genresToSave),
                            'budget' => $budgetToSave
                        ];

                        $movies[] = $movieData;
                        
                        $this->info(sprintf(
                            'データ抽出: 順位=%d, タイトル=%s, 興行収入=%.1f億円, 製作国=%s, 配給=%s, 公開年=%s',
                            $currentRank,
                            $title,
                            $boxOffice / 100000000,
                            $productionCountry,
                            $distributor,
                            $releaseYear ?? '-'
                        ));

                        // 200件に達したら打ち切り（別表の混入防止）
                        if (count($movies) >= 200) break;
                    }
                }
            } else {
                $this->error('総合ランキングのテーブルが見つかりませんでした');
            }
    
            $this->info(sprintf('合計 %d 件の映画データを解析しました', count($movies)));
            return $movies;
    
        } catch (\Exception $e) {
            $this->error('データ解析中にエラーが発生しました: ' . $e->getMessage());
            return [];
        }
    }
    
    // 順位と興行収入のマッピングを作成するヘルパーメソッド
    private function createRankBoxOfficeMapping($tableContent, &$rankBoxOfficeMap)
    {
        // 行に分割
        $rows = explode("\n|-", $tableContent);
        $currentRank = null;
        $rowspanRank = 0;
        
        foreach ($rows as $row) {
            // テーブルの開始・終了記号をスキップ
            if (strpos($row, '{|') !== false || strpos($row, '|}') !== false) {
                continue;
            }
            
            // ダブルパイプ || を改行+パイプ \n| に置換して、1行1セルの形式に正規化
            $row = str_replace('||', "\n|", $row);
            $lines = explode("\n", $row);
            
            // rowspanの処理
            if ($rowspanRank > 0) {
                $rowspanRank--;
            } else {
                // 順位を抽出
                foreach ($lines as $line) {
                    $line = trim($line);
                    // rowspan付きの順位を検出
                    if (preg_match('/^! rowspan="(\d+)" \|(\d+)$/', $line, $matches)) {
                        $rowspanRank = (int)$matches[1] - 1; // 現在の行を含むため-1
                        $currentRank = (int)$matches[2];
                        break;
                    } 
                    // 通常の順位を検出
                    elseif (preg_match('/^!(\d+)$/', $line, $matches)) {
                        $currentRank = (int)$matches[1];
                        break;
                    }
                }
            }
            
            // 興行収入を抽出（既に順位が設定されている場合のみ処理）
            if ($currentRank !== null && !isset($rankBoxOfficeMap[$currentRank])) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($currentRank == 108) {
                        $this->info("Rank 108 Line: " . $line);
                    }
                    // 興行収入を検出 - 通常の形式とrowspan付きの両方に対応（スペースや参照[1]などを許容）
                    // 行末の $ を削除し、数字の後の任意の文字列を許容
                    if (preg_match('/^\|\s*(?:rowspan="\d+"\s*\|\s*)?(\d+(?:\.\d+)?)/', $line, $matches) && 
                        count($matches) > 1 && is_numeric($matches[1])) {
                        $boxOffice = (float)$matches[1] * 100000000; // 億円を円に変換
                        $rankBoxOfficeMap[$currentRank] = $boxOffice;
                        $this->info(sprintf('順位 %d の興行収入を設定: %.1f億円', $currentRank, $boxOffice / 100000000));
                        break;
                    }
                }
            }
        }
        
        // デバッグ: 全ての順位と興行収入のマッピングを表示
        $this->info('順位と興行収入のマッピング:');
        foreach ($rankBoxOfficeMap as $rank => $boxOffice) {
            $this->info(sprintf('順位 %d: %.1f億円', $rank, $boxOffice / 100000000));
        }
    }

    /**
     * 総合ランキングのテーブル（1-100位、101-200位）を抽出して連結する
     */
    private function extractRankingTables(string $pageContent): ?string
    {
        $tables = [];
        
        // 取得したい見出しのパターン（順不同でも処理できるよう配慮、ただし基本は上から順）
        // Wikipediaの記述揺れに対応
        $targetPatterns = [
            // 1-100位: "総合ランキング" または "総合ランキング（第1位 - 第100位）"
            '/={2,3}\s*総合ランキング(?:（第1位 - 第100位）)?\s*={2,3}/u',
            // 101-200位
            '/={2,3}\s*総合ランキング（第101位 - 第200位）\s*={2,3}/u',
        ];

        foreach ($targetPatterns as $pattern) {
            if (preg_match($pattern, $pageContent, $matches, PREG_OFFSET_CAPTURE)) {
                $headingPos = $matches[0][1];
                
                // 見出し以降で最初のwikitable開始を探す
                $tableStart = strpos($pageContent, '{|', $headingPos);
                
                // 見出しから離れすぎている場合は誤検知の可能性があるのでスキップ（例: 2000文字以内）
                if ($tableStart === false || ($tableStart - $headingPos) > 5000) {
                    continue;
                }

                // 対応する終了マーカー "|}" を見つける
                // ネストされたテーブルがある場合は考慮が必要だが、ランキング表は通常フラット
                $tableEnd = strpos($pageContent, '|}', $tableStart);
                if ($tableEnd === false) {
                    continue;
                }

                // wikitableクラスを持つか確認
                $tableStr = substr($pageContent, $tableStart, $tableEnd - $tableStart + 2);
                if (stripos($tableStr, 'wikitable') !== false) {
                    $tables[] = $tableStr;
                    $this->info("テーブルを抽出しました: " . trim($matches[0][0]));
                }
            }
        }

        if (empty($tables)) {
            return null;
        }

        // 複数のテーブルを改行で連結して返す
        return implode("\n\n", $tables);
    }

    private function createMovieId($rank, $title, $distributor = '', $releaseYear = '')
    {
        // 数字を3桁のゼロ埋めに
        $rankPart = sprintf('%03d', $rank);
        
        // タイトル、配給会社、公開年を組み合わせて一意のハッシュを生成
        $uniqueString = $title . '|' . $distributor . '|' . $releaseYear;
        $titleHash = substr(md5($uniqueString), 0, 8);
        
        return "jp_{$rankPart}_{$titleHash}";
    }

    // MediaWiki: Wikipediaのページタイトルから Wikidata QID を取得
    private function getWikidataIdFromWikipediaTitle(string $title): ?string
    {
        try {
            $res = Http::wikimedia()->get('https://ja.wikipedia.org/w/api.php', [
                'action' => 'query',
                'prop' => 'pageprops',
                'ppprop' => 'wikibase_item',
                'titles' => $title,
                'format' => 'json'
            ]);
            usleep(300000);
            if (!$res->successful()) return null;
            $pages = data_get($res->json(), 'query.pages', []);
            foreach ($pages as $page) {
                $qid = data_get($page, 'pageprops.wikibase_item');
                if (!empty($qid)) return $qid;
            }
        } catch (\Exception $e) {
            $this->warn("Wikidata QID取得に失敗: {$title} - " . $e->getMessage());
        }
        return null;
    }

    // Wikidata: QIDから作品詳細（ジャンル日本語・予算USD・公開日）を取得
    private function fetchWikidataMovieDetailsByQid(string $qid): ?array
    {
        try {
            $res = Http::wikimedia()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetentities',
                'ids' => $qid,
                'props' => 'claims|labels',
                'languages' => 'ja',
                'format' => 'json'
            ]);
            usleep(300000);
            if (!$res->successful()) return null;

            $entity = data_get($res->json(), "entities.$qid");
            if (!$entity) return null;

            $claims = data_get($entity, 'claims', []);
            $budgetUsd = null;
            $releaseDate = null;
            $genreQids = [];

            // 予算 P2130（通貨はUSD想定。他通貨は未変換）
            foreach ((array) data_get($claims, 'P2130', []) as $claim) {
                $amount = data_get($claim, 'mainsnak.datavalue.value.amount');
                if ($amount !== null) {
                    $budgetUsd = (float) $amount;
                    break;
                }
            }

            // 公開日 P577
            foreach ((array) data_get($claims, 'P577', []) as $claim) {
                $time = data_get($claim, 'mainsnak.datavalue.value.time');
                if ($time) {
                    $releaseDate = substr($time, 1, 10); // +YYYY-MM-DD → YYYY-MM-DD（0埋めは後で正規化）
                    break;
                }
            }

            // ジャンル P136
            foreach ((array) data_get($claims, 'P136', []) as $claim) {
                $gid = data_get($claim, 'mainsnak.datavalue.value.id');
                if ($gid) $genreQids[] = $gid;
            }

            $genresJa = $this->resolveWikidataLabels($genreQids, 'ja');

            return [
                'genres' => $genresJa,
                'budget' => $budgetUsd,
                'release_date' => $releaseDate,
            ];
        } catch (\Exception $e) {
            $this->warn("Wikidata詳細取得に失敗: {$qid} - " . $e->getMessage());
            return null;
        }
    }

    // Wikidata: QID配列を日本語ラベルに解決
    private function resolveWikidataLabels(array $qids, string $lang = 'ja'): array
    {
        $qids = array_values(array_unique(array_filter($qids)));
        if (empty($qids)) return [];

        try {
            $res = Http::wikimedia()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetentities',
                'ids' => implode('|', $qids),
                'props' => 'labels',
                'languages' => $lang,
                'format' => 'json'
            ]);
            usleep(300000);
            if (!$res->successful()) return [];

            $entities = data_get($res->json(), 'entities', []);
            $labels = [];
            foreach ($qids as $qid) {
                $label = data_get($entities, "$qid.labels.$lang.value");
                if ($label) $labels[] = $label;
            }
            return $labels;
        } catch (\Exception $e) {
            $this->warn('Wikidataラベル解決に失敗: ' . $e->getMessage());
            return [];
        }
    }

    // 不正な日付（YYYY-00-00 / YYYY-MM-00 など）をMySQLで扱える形に正規化
    private function sanitizeReleaseDate(?string $date, ?string $fallbackYearDate = null): ?string
    {
        if (empty($date)) {
            return $fallbackYearDate;
        }

        // 期待形式: YYYY-MM-DD
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            // 不明形式はフォールバック
            return $fallbackYearDate;
        }

        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];

        if ($mo <= 0 || $mo > 12) {
            $mo = 1;
        }
        if ($d <= 0 || $d > 31) {
            $d = 1;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    // TMDBから作品詳細（ジャンル、制作費）を一括取得
    private function fetchTMDBDetails(string $title, ?string $releaseDate = null): array
    {
        $defaultResult = ['genres' => [], 'budget' => 0];
        $apiKey = config('services.tmdb.api_key');
        if (empty($apiKey)) {
            return $defaultResult;
        }

        try {
            $wikiYear = $releaseDate ? substr($releaseDate, 0, 4) : null;
            if (!$wikiYear) {
                $this->warn('TMDB詳細取得スキップ: 公開年不明のため年一致を満たせません');
                return $defaultResult;
            }

            // 1. 検索
            $searchResponse = Http::get('https://api.themoviedb.org/3/search/movie', [
                'api_key' => $apiKey,
                'query' => $title,
                'language' => 'ja-JP',
                'region' => 'JP',
                'year' => $wikiYear,
            ]);
            // レートリミット考慮 (以前より緩和しても良いが安全策)
            usleep(100000);

            if (!$searchResponse->successful() || empty($searchResponse->json()['results'])) {
                return $defaultResult;
            }

            $results = $searchResponse->json()['results'];
            $bestMatch = null;
            $highestSimilarity = -1.0;
            $normalizedQuery = $this->normalizeTitle($title);

            foreach ($results as $result) {
                $candidateTitle = $result['title'] ?? '';
                if ($candidateTitle === '') continue;
                $candidateYear = isset($result['release_date']) && $result['release_date']
                    ? substr($result['release_date'], 0, 4)
                    : null;

                // 年一致必須
                if ($candidateYear !== $wikiYear) {
                    continue;
                }

                $similarity = $this->calculateSimilarity($normalizedQuery, $this->normalizeTitle($candidateTitle));
                if ($similarity < 0.6) {
                    continue;
                }

                if ($similarity > $highestSimilarity) {
                    $highestSimilarity = $similarity;
                    $bestMatch = $result;
                }
            }

            if (!$bestMatch) {
                return $defaultResult;
            }

            // 2. 詳細取得
            $detailsResponse = Http::get("https://api.themoviedb.org/3/movie/{$bestMatch['id']}", [
                'api_key' => $apiKey,
                'language' => 'ja-JP'
            ]);
            usleep(100000);

            if (!$detailsResponse->successful()) return $defaultResult;
            $details = $detailsResponse->json();
            
            // ジャンル抽出
            $genres = [];
            if (!empty($details['genres'])) {
                $genres = collect($details['genres'])->pluck('name')->filter()->values()->all();
            }

            // 予算抽出と円換算
            $budgetYen = 0;
            $budgetUsd = (int) ($details['budget'] ?? 0);
            if ($budgetUsd > 0) {
                $budgetYen = (int) round($budgetUsd * 150);
            }

            return [
                'genres' => $genres,
                'budget' => $budgetYen
            ];

        } catch (\Exception $e) {
            $this->warn('TMDB詳細取得に失敗: ' . $e->getMessage());
            return $defaultResult;
        }
    }

    private function createMovieData($movie, $tmdbDetails, $now)
    {
        $rank = $movie['rank'];
        $boxOffice = (int)($movie['box_office'] * 100000000);
        $movieId = 'jp_' . sprintf('%03d', $rank) . '_' . substr(md5($movie['title']), 0, 8);

        $data = [
            'movie_id' => $movieId,
            'title' => $movie['title'],
            'box_office' => $boxOffice,
            'region' => 'japan',
            'data_source' => 'Wikipedia',
            'data_source_url' => 'https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧',
            'last_updated' => $now,
            'genres' => json_encode($tmdbDetails['genres']),
            'budget' => $tmdbDetails['budget'],
            'release_date' => $movie['release_date'] 
                ? Carbon::createFromFormat('Y', $movie['release_date'])->startOfYear()
                : $now
        ];

        if ($tmdbDetails) {
            // TMDBからの詳細情報がある場合は上書き
            // genres は配列のまま保存（json_encodeは不要）
            $data['genres'] = $tmdbDetails['genres'];  // 変更点：json_encodeを削除
            $data['budget'] = $tmdbDetails['budget'];
            $data['release_date'] = $tmdbDetails['release_date'];
            
            // デバッグ情報
            $this->info("\nTMDBデータの統合結果:");
            $this->info("タイトル: " . $data['title']);
            $this->info("ジャンル（生データ）: " . var_export($data['genres'], true));
        }

        return $data;
    }

    private function normalizeTitle($title)
    {
        // タイトルの正規化処理
        $title = mb_strtolower($title); // 小文字化
        $title = $this->convertFullWidthToHalfWidth($title); // 全角→半角変換
        
        // 記号の統一や削除
        $title = str_replace(['：', '：', '：'], ':', $title); // 各種コロンを半角に統一
        $title = str_replace(['＆', '＆'], '&', $title); // アンパサンドを半角に統一
        
        // 空白文字の正規化
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);
        
        return $title;
    }

    private function convertFullWidthToHalfWidth($str) 
    {
        return mb_convert_kana($str, 'as'); // 全角英数字→半角英数字
    }

    private function calculateSimilarity($str1, $str2) 
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        if ($len1 === 0) return $len2 === 0 ? 1.0 : 0.0;
        if ($len2 === 0) return 0.0;

        $distance = levenshtein($str1, $str2);
        $maxLength = max($len1, $len2);
        
        return 1 - ($distance / $maxLength);
    }
}
