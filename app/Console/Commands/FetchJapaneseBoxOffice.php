<?php

namespace App\Console\Commands;

use App\Models\Movie;
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
            DB::beginTransaction();
            
            $this->info('Wikipediaからデータ取得を開始...');
            
            $response = Http::get('https://ja.wikipedia.org/w/api.php', [
                'action' => 'parse',
                'page' => '日本歴代興行成績上位の映画一覧',
                'format' => 'json',
                'prop' => 'wikitext'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $pageContent = $data['parse']['wikitext']['*'] ?? '';
                
                if (preg_match('/==\s*総合ランキング\s*==(.+?)(?===|$)/s', $pageContent, $sectionMatch)) {
                    $rankingContent = $sectionMatch[1];
                    
                    $rows = preg_split('/\n\|-\n/', $rankingContent);
                    $movies = [];
                    $processedTitles = [];
                    $currentRank = null;
                    $currentBoxOffice = null;
                    $rowspanCount = 0;

                    foreach ($rows as $index => $row) {
                        $this->info("\n=== 行 {$index} の解析開始 ===");
                        $this->info($row);

                        // rowspanを含む行の処理（順位と興行収入が同時に記載）
                        if (preg_match('/! rowspan="(\d+)" \|(\d+).*?\| rowspan="\d+" \|(\d+(?:\.\d+)?)/s', $row, $matches)) {
                            $rowspanCount = (int)$matches[1];
                            $currentRank = $matches[2];
                            $currentBoxOffice = $matches[3];
                            $this->info("rowspan検出 - 順位: {$currentRank}, 興行収入: {$currentBoxOffice}億円, rowspan: {$rowspanCount}");
                        }
                        // 通常の行の処理（rowspanなし）
                        elseif (preg_match('/^!(\d+).*?\|(\d+(?:\.\d+)?)/s', $row, $matches)) {
                            $rowspanCount = 1;
                            $currentRank = $matches[1];
                            $currentBoxOffice = $matches[2];
                            $this->info("通常行検出 - 順位: {$currentRank}, 興行収入: {$currentBoxOffice}億円");
                        }
                        // rowspanCountが0より大きい場合は、前の値を継続使用
                        elseif ($rowspanCount > 0) {
                            $this->info("rowspan継続 - 順位: {$currentRank}, 興行収入: {$currentBoxOffice}億円, 残り: {$rowspanCount}");
                        }

                        // タイトルの処理
                        if (preg_match('/\|\[\[([^|\]]+)(?:\|[^\]]+)?\]\]/', $row, $titleMatch)) {
                            $title = trim($titleMatch[1]);
                            $this->info("タイトル検出: {$title}");

                            // 公開年の処理
                            $releaseYear = null;
                            if (preg_match('/\|(\d{4})(?:\||$)/', $row, $yearMatch)) {
                                if ($yearMatch[1] >= 1900 && $yearMatch[1] <= date('Y')) {
                                    $releaseYear = $yearMatch[1];
                                    $this->info("公開年検出: {$releaseYear}年");
                                }
                            }

                            if (!in_array($title, $processedTitles) && $currentRank && $currentBoxOffice) {
                                $processedTitles[] = $title;
                                $movies[] = [
                                    'rank' => $currentRank,
                                    'title' => $title,
                                    'box_office' => (float)$currentBoxOffice,
                                    'release_date' => $releaseYear
                                ];
                                
                                $this->info("データ追加: #{$currentRank} {$title} - {$currentBoxOffice}億円" . 
                                    ($releaseYear ? " ({$releaseYear}年)" : ""));
                            }
                        }

                        // rowspanCountを減少
                        if ($rowspanCount > 0) {
                            $rowspanCount--;
                        }
                    }

                    // 興行収入で降順ソート
                    usort($movies, function($a, $b) {
                        return $b['box_office'] <=> $a['box_office'];
                    });

                    // 既存データの削除
                    Movie::where('region', 'japan')
                        ->where('data_source', 'Wikipedia')
                        ->delete();

                    // 一括挿入用のデータを準備
                    $now = now();
                    $moviesData = [];
                    
                    foreach ($movies as $movie) {
                        $rank = $movie['rank']; // 元の順位を使用
                        $boxOffice = (int)($movie['box_office'] * 100000000);
                        
                        $movieId = 'jp_' . sprintf('%03d', $rank) . '_' . substr(md5($movie['title']), 0, 8);

                        $moviesData[] = [
                            'movie_id' => $movieId,
                            'title' => $movie['title'],
                            'box_office' => $boxOffice,
                            'region' => 'japan',
                            'data_source' => 'Wikipedia',
                            'data_source_url' => 'https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧',
                            'last_updated' => $now,
                            'budget' => 0,
                            'release_date' => $movie['release_date'] 
                                ? Carbon::createFromFormat('Y', $movie['release_date'])->startOfYear()
                                : $now,
                            'genres' => '[]',
                            'created_at' => $now,
                            'updated_at' => $now
                        ];
                    }

                    if (!empty($moviesData)) {
                        Movie::insert($moviesData);
                        DB::commit();

                        $this->info('データ更新完了');
                        $this->info("保存された映画の総数: " . count($moviesData));
                    }
                } else {
                    $this->error('総合ランキングセクションが見つかりませんでした');
                }
            } else {
                $this->error('Wikipediaからのデータ取得に失敗しました');
                $this->error('Response: ' . $response->body());
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('エラー発生: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
    }
}
