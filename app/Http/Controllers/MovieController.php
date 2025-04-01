<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use Illuminate\Support\Facades\DB;
use App\Models\Movie;
use Illuminate\Pagination\LengthAwarePaginator;

class MovieController extends Controller
{
    private $apiKey;
    private $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('TMDB_API_KEY');
        $this->baseUrl = 'https://api.themoviedb.org/3';
    }

    public function getPopularMovies()
    {
        try {
            $response = Http::get("{$this->baseUrl}/movie/popular", [
                'api_key' => $this->apiKey,
                'language' => 'ja-JP'
            ]);

            if ($response->successful()) {
                $movies = $response->json()['results'];
                return view('movies.index', compact('movies'));
            }

            return view('movies.index', ['movies' => [], 'error' => '映画データの取得に失敗しました']);

        } catch (\Exception $e) {
            return view('movies.index', ['movies' => [], 'error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $response = Http::get("{$this->baseUrl}/movie/{$id}", [
                'api_key' => $this->apiKey,
                'language' => 'ja-JP',
                'append_to_response' => 'credits,videos'
            ]);

            if ($response->successful()) {
                $movie = $response->json();
                return view('movies.show', compact('movie'));
            }

            return redirect()->route('movies.index')->with('error', '映画の詳細情報の取得に失敗しました');

        } catch (\Exception $e) {
            return redirect()->route('movies.index')->with('error', $e->getMessage());
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->input('query');
            
            if (empty($query)) {
                return redirect()->route('movies.index');
            }

            $response = Http::get("{$this->baseUrl}/search/movie", [
                'api_key' => $this->apiKey,
                'language' => 'ja-JP',
                'query' => $query
            ]);

            if ($response->successful()) {
                $searchResults = $response->json()['results'];
                return view('movies.search', [
                    'movies' => $searchResults,
                    'query' => $query
                ]);
            }

            return view('movies.search', [
                'movies' => [],
                'query' => $query,
                'error' => '検索結果の取得に失敗しました'
            ]);

        } catch (\Exception $e) {
            return view('movies.search', [
                'movies' => [],
                'query' => $query ?? '',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function fetchMovies()
    {
        try {
            $api_key = config('services.tmdb.api_key');
            Log::info('APIキーを使用: ' . $api_key);

            // グローバルの映画データのみを取得
            $response = Http::get('https://api.themoviedb.org/3/discover/movie', [
                'api_key' => $api_key,
                'sort_by' => 'revenue.desc',
                'language' => 'ja'
            ]);

            if ($response->successful()) {
                $movies = $response->json()['results'];
                foreach ($movies as $movie) {
                    try {
                        GlobalMovie::updateOrCreate(
                            ['movie_id' => 'global_' . $movie['id']],
                            [
                                'title' => $movie['title'],
                                'box_office' => $movie['revenue'] ?? 0,
                                'budget' => $movie['budget'] ?? 0,
                                'release_date' => $movie['release_date'] ?? null,
                                'region' => 'global',
                                'genres' => isset($movie['genres']) ? collect($movie['genres'])->pluck('name')->toArray() : []
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::error('個別の映画保存でエラー: ' . $e->getMessage());
                        continue;
                    }
                }
            }

            return redirect()->route('movies.index')->with('success', '映画データを更新しました');

        } catch (\Exception $e) {
            Log::error('エラー発生: ' . $e->getMessage());
            return redirect()->route('movies.index')->with('error', 'エラーが発生しました');
        }
    }

    public function index()
    {
        try {
            // データベース接続情報のデバッグ
            \Log::debug('Database Connection Settings:', [
                'connection' => config('database.default'),
                'host' => config('database.connections.pgsql.host'),
                'port' => config('database.connections.pgsql.port'),
                'database' => config('database.connections.pgsql.database'),
                'username' => config('database.connections.pgsql.username')
            ]);

            // 接続テスト
            try {
                DB::connection()->getPdo();
                \Log::debug('Database connection successful');
            } catch (\Exception $e) {
                \Log::error('Database connection failed: ' . $e->getMessage());
                throw $e;
            }

            // データベースの映画数を確認
            $movieCount = GlobalMovie::count();
            \Log::debug("Total global movies in database: {$movieCount}");

            // もしデータベースが空なら、APIからデータを取得
            if ($movieCount === 0) {
                \Log::debug('Global movies database is empty, fetching movies from API');
                $this->fetchMovies();
            }

            $selectedGenre = request()->get('genre');

            // ジャンル名の変換マップを定義
            $genreMap = [
                'アニメーション' => 'アニメ',
                'サイエンスフィクション' => 'SF',
                'アニメ' => 'アニメーション',
                'SF' => 'サイエンスフィクション',
                '謎' => 'ミステリー',
                'ミステリー' => '謎',
                '犯罪' => 'サスペンス',
                'サスペンス' => '犯罪',
                '履歴' => '歴史',
                '歴史' => '履歴'
            ];

            // 世界の興行収入データ
            $globalMovies = GlobalMovie::query()
                ->when($selectedGenre, function($query) use ($selectedGenre, $genreMap) {
                    $searchGenre = array_flip($genreMap)[$selectedGenre] ?? $selectedGenre;
                    return $query->whereRaw("genres::jsonb @> ?::jsonb", [json_encode([$searchGenre])]);
                })
                ->orderBy('box_office', 'desc')
                ->paginate(100, ['*'], 'global_page');

            // rankを設定し、その他の変換も行う
            $rank = ($globalMovies->currentPage() - 1) * $globalMovies->perPage() + 1;
            $globalMovies->through(function ($movie) use ($genreMap, &$rank) {
                $movie->rank = $rank++;
                // ドルから円への換算（1ドル = 約150円で計算）
                $movie->box_office_billion = number_format($movie->box_office * 150 / 100000000, 1);
                $movie->budget_billion = number_format($movie->budget * 150 / 100000000, 1);
                if ($movie->genres) {
                    $movie->genres = array_map(function($genre) use ($genreMap) {
                        return $genreMap[$genre] ?? $genre;
                    }, $movie->genres);
                }
                return $movie;
            });
            
            // 日本の興行収入データ
            $japanMovies = JapaneseMovie::query()  // Movie から JapaneseMovie に変更
                ->when($selectedGenre, function($query) use ($selectedGenre, $genreMap) {
                    $searchGenre = array_flip($genreMap)[$selectedGenre] ?? $selectedGenre;
                    return $query->whereRaw("genres::jsonb @> ?::jsonb", [json_encode([$searchGenre])]);
                })
                ->orderBy('box_office', 'desc')
                ->paginate(100, ['*'], 'japan_page');

            // rankを設定し、その他の変換も行う
            $rank = ($japanMovies->currentPage() - 1) * $japanMovies->perPage() + 1;
            $japanMovies->through(function ($movie) use ($genreMap, &$rank) {
                $movie->rank = $rank++;
                $movie->box_office_billion = number_format($movie->box_office / 100000000, 1);
                $movie->budget_billion = number_format($movie->budget / 100000000, 1);
                
                // genres のデバッグ出力
                \Log::debug("Movie {$movie->title} genres:", [
                    'raw' => $movie->genres,
                    'type' => gettype($movie->genres)
                ]);
                
                // genres が配列でない場合の処理
                if (!is_array($movie->genres)) {
                    if (is_string($movie->genres)) {
                        $movie->genres = json_decode($movie->genres, true) ?? [];
                    } else {
                        $movie->genres = [];
                    }
                }
                
                // ジャンルの変換
                $movie->genres = array_map(function($genre) use ($genreMap) {
                    return $genreMap[$genre] ?? $genre;
                }, $movie->genres);
                
                return $movie;
            });

            // 利用可能なジャンルの取得を両方のテーブルから行うように修正
            $availableGenres = collect()
                ->concat(GlobalMovie::select('genres')->get()->pluck('genres'))
                ->concat(JapaneseMovie::select('genres')->get()->pluck('genres'))
                ->flatten()
                ->unique()
                ->map(function($genre) use ($genreMap) {
                    return $genreMap[$genre] ?? $genre;
                })
                ->values()
                ->sort()
                ->all();

            // ページネーションのURLにジャンルパラメータを追加
            $globalMovies->appends([
                'japan_page' => request()->japan_page,
                'tab' => request()->tab,
                'genre' => $selectedGenre
            ]);

            $japanMovies->appends([
                'global_page' => request()->global_page,
                'tab' => request()->tab,
                'genre' => $selectedGenre
            ]);

            // ジャンルごとの色を薄いトーンに変更
            $genreColors = [
                'アクション' => '#ffcccc',      // 薄い赤
                'アドベンチャー' => '#ffe4b5',  // 薄いサーモン
                'アニメ' => '#e0ffe0',          // 薄いグリーン
                'コメディ' => '#f0e68c',        // 薄いプラム
                'ドラマ' => '#add8e6',          // 薄いスカイブルー
                'ファンタジー' => '#f5deb3',    // 薄いバーリーウッド
                'ホラー' => '#d3d3d3',          // 薄いグレー
                'ミステリー' => '#d8bfd8',      // 薄いミディアムパープル
                'ロマンス' => '#ffb3d1',        // 薄いライトピンク
                'SF' => '#b2e0e0',              // 薄いライトシーグリーン
                'スリラー' => '#f08080',        // 薄いダークレッド
                'ファミリー' => '#fffacd',      // 薄いゴールド
            ];

            // 取得したデータをログ出力
            \Log::debug('Global movies count: ' . $globalMovies->count());
            \Log::debug('Japan movies count: ' . $japanMovies->count());

            // 最終更新日時の取得を修正
            $japanLastUpdated = JapaneseMovie::whereNotNull('last_updated')
                                          ->max('last_updated');
            $globalLastUpdated = GlobalMovie::whereNotNull('last_updated')
                                         ->max('last_updated');

            return view('movies.index', compact('globalMovies', 'japanMovies', 'availableGenres', 'selectedGenre', 'genreColors', 'japanLastUpdated', 'globalLastUpdated'));
        } catch (\Exception $e) {
            \Log::error('Error in index method: ' . $e->getMessage());
            throw $e;
        }
    }

    public function filterByGenre(Request $request)
    {
        try {
            $genre = $request->query('genre');
            $tab = $request->query('tab', 'global');
            $perPage = 100;
            $page = $request->query('page', 1);

            // ジャンル名の変換マップを定義
            $genreMap = [
                'アニメーション' => 'アニメ',
                'サイエンスフィクション' => 'SF',
                'アニメ' => 'アニメーション',
                'SF' => 'サイエンスフィクション',
                '謎' => 'ミステリー',
                'ミステリー' => '謎',
                '犯罪' => 'サスペンス',
                'サスペンス' => '犯罪',
                '履歴' => '歴史',
                '歴史' => '履歴'
            ];

            // タイトル変換マップを定義
            $titleMap = [
                '哪吒之魔童闹海' => 'ナタ 魔童の大暴れ'
            ];

            // タブに応じてモデルを選択
            $query = $tab === 'global' ? GlobalMovie::query() : JapaneseMovie::query();

            // ジャンルでフィルタリング（ジャンルが指定されている場合のみ）
            if ($genre && $genre !== 'すべてのジャンル') {
                $searchGenre = array_flip($genreMap)[$genre] ?? $genre;
                $query->whereJsonContains('genres', $searchGenre);
            }

            // 興行収入でソート
            $query->orderBy('box_office', 'desc');

            $total = $query->count();
            $movies = $query->paginate($perPage);

            // データの変換処理
            $transformedData = collect($movies->items())->map(function ($movie, $index) use ($titleMap, $tab, $page, $perPage, $genreMap) {
                // タイトルの変換
                $movie->title = $titleMap[$movie->title] ?? $movie->title;

                // ジャンルの変換
                if (!is_array($movie->genres)) {
                    if (is_string($movie->genres)) {
                        $movie->genres = json_decode($movie->genres, true) ?? [];
                    } else {
                        $movie->genres = [];
                    }
                }
                $movie->genres = array_map(function($genre) use ($genreMap) {
                    return $genreMap[$genre] ?? $genre;
                }, $movie->genres);

                // 既存の変換処理
                if ($tab === 'global') {
                    if ($movie->box_office) {
                        $movie->box_office_billion = number_format($movie->box_office * 150 / 100000000, 1);
                    }
                    if ($movie->budget) {
                        $movie->budget_billion = number_format($movie->budget * 150 / 100000000, 1);
                    }
                } else {
                    if ($movie->box_office) {
                        $movie->box_office_billion = number_format($movie->box_office / 100000000, 1);
                    }
                    if ($movie->budget) {
                        $movie->budget_billion = number_format($movie->budget / 100000000, 1);
                    }
                }

                // ランク付け（インデックスを使用）
                $movie->rank = ($page - 1) * $perPage + $index + 1;

                return $movie;
            });

            // 新しいページネーターインスタンスを作成
            $paginator = new LengthAwarePaginator(
                $transformedData,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'success' => true,
                'movies' => $paginator,
            ]);

        } catch (\Exception $e) {
            \Log::error('Movie filtering error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'データの取得中にエラーが発生しました。'
            ], 500);
        }
    }
}
