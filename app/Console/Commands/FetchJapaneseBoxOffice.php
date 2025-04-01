<?php

namespace App\Console\Commands;

use App\Models\JapaneseMovie;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            
            $response = Http::get('https://ja.wikipedia.org/w/api.php', [
                'action' => 'parse',
                'page' => '日本歴代興行成績上位の映画一覧',
                'prop' => 'wikitext',
                'format' => 'json',
                'redirects' => 'true'
            ]);

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

                    // バルク処理による高速化
                    DB::transaction(function () use ($moviesData) {
                        // 既存のデータを一旦全て削除
                        JapaneseMovie::truncate();
                        
                        // チャンクに分割してバルクインサート
                        foreach (array_chunk($moviesData, 50) as $chunk) {
                            JapaneseMovie::insert($chunk);
                        }
                        
                        $this->info(sprintf('データベースに%d件の映画データを保存しました', count($moviesData)));
                    });
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

            // 総合ランキングのテーブルを抽出
            if (preg_match('/==\s*総合ランキング.*?{\\| class="wikitable.*?\|\}/s', $pageContent, $match)) {
                $tableContent = $match[0];
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
                        if (preg_match('/\|\s*(\d{4})/', $line, $yearMatch)) {
                            $releaseYear = $yearMatch[1] . '-01-01';
                        }
                    }
                    
                    // 100位までに制限
                    if ($currentRank > 100) {
                        break;
                    }
                    
                    // 順位に対応する興行収入を取得
                    $boxOffice = $rankBoxOfficeMap[$currentRank] ?? 0;
                    
                    // 映画データの追加
                    if ($currentRank !== null && !empty($title) && $boxOffice > 0) {
                        // TMDBから追加情報を取得
                        $tmdbDetails = $this->fetchTMDBMovieDetails($title);
                        
                        $movieData = [
                            'movie_id' => $this->createMovieId($currentRank, $title, $distributor, $releaseYear),
                            'title' => $title,
                            'box_office' => $boxOffice,
                            'rank' => $currentRank,
                            'production_country' => $productionCountry,
                            'distributor' => $distributor,
                            'release_date' => $releaseYear,
                            'last_updated' => now(),
                            'genres' => '[]',  // デフォルト値として空の配列のJSON文字列を設定
                            'budget' => 0
                        ];

                        // TMDBからの追加情報があれば更新
                        if ($tmdbDetails) {
                            $movieData['genres'] = json_encode($tmdbDetails['genres']);  // 配列をJSON文字列に変換
                            $movieData['budget'] = $tmdbDetails['budget'];
                            
                            $this->info(sprintf(
                                'TMDB情報取得成功: タイトル=%s, ジャンル=%s, 制作費=%.1f億円',
                                $title,
                                implode(', ', $tmdbDetails['genres']),
                                $tmdbDetails['budget'] / 100000000
                            ));
                        }

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
                    // 興行収入を検出 - 通常の形式とrowspan付きの両方に対応（スペースを許容）
                    if (preg_match('/^\|\s*(?:rowspan="\d+"\s*\|\s*)?(\d+(?:\.\d+)?)$/', $line, $matches) && 
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

    // 新しいヘルパーメソッドを追加
    private function extractSimpleText($text)
    {
        // [[記事名|表示名]] 形式のリンクを処理
        if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/', $text, $matches)) {
            return trim($matches[1]);
        }
        
        // リンクでない通常のテキスト
        return trim(preg_replace('/\[\[|\]\]/', '', $text));
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

    private function extractTitle($text)
    {
        // [[記事名|表示名]] 形式のリンクを処理
        if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/', $text, $matches)) {
            return trim($matches[1]);
        }
        
        // リンクでない通常のテキスト
        return trim(preg_replace('/\[\[|\]\]/', '', $text));
    }

    private function fetchTMDBMovieDetails($title)
    {
        $apiKey = config('services.tmdb.api_key');
        
        try {
            // 映画を検索（日本語で検索）
            $searchResponse = Http::get('https://api.themoviedb.org/3/search/movie', [
                'api_key' => $apiKey,
                'query' => $title,
                'language' => 'ja-JP',
                'region' => 'JP'  // 日本の結果を優先
            ]);

            if ($searchResponse->successful() && !empty($searchResponse->json()['results'])) {
                // タイトルの完全一致か類似度の高いものを探す
                $results = $searchResponse->json()['results'];
                $bestMatch = null;
                $highestSimilarity = 0;

                foreach ($results as $result) {
                    $similarity = similar_text($title, $result['title'], $percent);
                    if ($percent > $highestSimilarity) {
                        $highestSimilarity = $percent;
                        $bestMatch = $result;
                    }
                }

                // 類似度が70%以上の場合のみ採用
                if ($highestSimilarity >= 70) {
                    $detailsResponse = Http::get("https://api.themoviedb.org/3/movie/{$bestMatch['id']}", [
                        'api_key' => $apiKey,
                        'language' => 'ja-JP'
                    ]);

                    if ($detailsResponse->successful()) {
                        $details = $detailsResponse->json();
                        
                        return [
                            'genres' => collect($details['genres'])->pluck('name')->toArray(),
                            'budget' => $details['budget'],
                            'release_date' => $details['release_date']
                        ];
                    }
                } else {
                    $this->warn("タイトルの一致度が低いためスキップ: {$title} (類似度: {$highestSimilarity}%)");
                }
            }
        } catch (\Exception $e) {
            $this->error("TMDB API エラー - {$title}: " . $e->getMessage());
        }

        return null;
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
            'genres' => '[]',  // デフォルト値
            'budget' => 0,     // デフォルト値
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
}
