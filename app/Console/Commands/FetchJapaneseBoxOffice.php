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
            $currentBoxOffice = null;

            // 総合ランキングのテーブルを抽出
            if (preg_match('/==\s*総合ランキング.*?{\\| class="wikitable.*?\|\}/s', $pageContent, $match)) {
                $tableContent = $match[0];
                $this->info('総合ランキングのテーブルを検出しました');
                
                // 行に分割
                $rows = explode("\n|-", $tableContent);
                
                foreach ($rows as $row) {
                    // テーブルの開始・終了記号をスキップ
                    if (strpos($row, '{|') !== false || strpos($row, '|}') !== false) {
                        continue;
                    }

                    // 各行のデータを抽出
                    $lines = explode("\n", $row);
                    $data = [];
                    $hasRank = false;
                    
                    foreach ($lines as $line) {
                        $line = trim($line);
                        // 順位の行
                        if (preg_match('/^!(\d+)$/', $line, $matches)) {
                            $hasRank = true;
                            $currentRank = (int)$matches[1];
                        }
                        // タイトルまたはその他のデータ行
                        elseif (preg_match('/^\|(.+)$/', $line, $matches)) {
                            $content = trim($matches[1]);
                            $content = str_replace(['<br/>', '<br />'], ' ', $content);
                            $data[] = $content;
                        }
                    }

                    // データのバリデーション
                    if (count($data) > 0) {
                        $title = $this->extractTitle($data[0] ?? '');
                        
                        // タイトルが空、または不正な文字のみの場合はスキップ
                        if (empty($title) || $title === '{' || $title === '}' || strlen($title) < 2) {
                            continue;
                        }

                        // 公開年の抽出（6列目）
                        $releaseYear = null;
                        if (isset($data[5]) && preg_match('/(\d{4})/', $data[5], $yearMatch)) {
                            $releaseYear = $yearMatch[1] . '-01-01';
                        }

                        // 新しい順位の行の処理
                        if ($hasRank && isset($data[1])) {
                            // 興行収入の抽出と保存
                            if (preg_match('/(\d+(?:\.\d+)?)/', $data[1], $boxOfficeMatch)) {
                                $currentBoxOffice = (float)$boxOfficeMatch[1] * 100000000;
                            }
                        }

                        // 100位までに制限
                        if ($currentRank > 100) {
                            break;
                        }

                        // 映画データの追加（新しい順位または同率順位）
                        if ($currentRank !== null && $currentBoxOffice !== null) {
                            $movies[] = [
                                'movie_id' => $this->createMovieId(count($movies) + 1, $title),
                                'title' => $title,
                                'box_office' => $currentBoxOffice,
                                'rank' => $currentRank,
                                'release_date' => $releaseYear,
                                'last_updated' => now()
                            ];

                            $this->info(sprintf(
                                'データ抽出: 順位=%d, タイトル=%s, 興行収入=%.1f億円, 公開年=%s',
                                $currentRank,
                                $title,
                                $currentBoxOffice / 100000000,
                                $releaseYear ?? '-'
                            ));
                        }
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

    private function createMovieId($rank, $title)
    {
        // 数字を3桁のゼロ埋めに
        $rankPart = sprintf('%03d', $rank);
        
        // タイトルから安全なハッシュを生成
        $titleHash = substr(md5($title), 0, 8);
        
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
            // 映画を検索
            $searchResponse = Http::get('https://api.themoviedb.org/3/search/movie', [
                'api_key' => $apiKey,
                'query' => $title,
                'language' => 'ja-JP'
            ]);

            if ($searchResponse->successful() && !empty($searchResponse->json()['results'])) {
                // 検索結果のログ
                $this->info("\nTMDB検索結果 - {$title}:");
                $this->info("検索結果件数: " . count($searchResponse->json()['results']));
                $this->info("最初の結果のタイトル: " . $searchResponse->json()['results'][0]['title']);
                
                $result = $searchResponse->json()['results'][0];
                
                // 詳細情報を取得
                $detailsResponse = Http::get("https://api.themoviedb.org/3/movie/{$result['id']}", [
                    'api_key' => $apiKey,
                    'language' => 'ja-JP'
                ]);

                if ($detailsResponse->successful()) {
                    $details = $detailsResponse->json();
                    
                    // 生のジャンルデータを確認
                    $this->info("\nTMDB詳細情報（生データ）:");
                    $this->info("生のジャンルデータ: " . json_encode($details['genres'], JSON_UNESCAPED_UNICODE));
                    
                    // ジャンル名の配列を作成
                    $genres = collect($details['genres'])->pluck('name')->toArray();
                    
                    // 処理後のジャンルデータを確認
                    $this->info("処理後のジャンル: " . json_encode($genres, JSON_UNESCAPED_UNICODE));
                    
                    return [
                        'genres' => $genres,
                        'budget' => $details['budget'],
                        'release_date' => $details['release_date']
                    ];
                }
            } else {
                $this->warn("\n⚠️ TMDBで映画が見つかりませんでした: {$title}");
            }
        } catch (\Exception $e) {
            $this->error("\nTMDB APIエラー - {$title}: " . $e->getMessage());
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
