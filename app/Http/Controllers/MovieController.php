<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Movie;
use Illuminate\Support\Facades\DB;

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

            // 日本の映画データを取得
            $response = Http::get('https://api.themoviedb.org/3/discover/movie', [
                'api_key' => $api_key,
                'with_original_language' => 'ja',
                'sort_by' => 'revenue.desc',
                'language' => 'ja',
                'region' => 'JP'
            ]);

            if ($response->successful()) {
                $movies = $response->json()['results'];
                foreach ($movies as $movie) {
                    try {
                        $movieDetails = Http::get("https://api.themoviedb.org/3/movie/{$movie['id']}", [
                            'api_key' => $api_key,
                            'language' => 'ja'
                        ])->json();

                        Movie::updateOrCreate(
                            ['movie_id' => (string)$movie['id']],
                            [
                                'title' => $movie['title'],
                                'box_office' => $movieDetails['revenue'] ?? 0,
                                'budget' => $movieDetails['budget'] ?? 0,
                                'release_date' => $movie['release_date'] ?? null,
                                'region' => 'japan',
                                'genres' => isset($movieDetails['genres']) ? collect($movieDetails['genres'])->pluck('name')->toArray() : []
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::error('個別の映画保存でエラー: ' . $e->getMessage());
                        continue;
                    }
                }
            }

            // グローバルの映画データも取得（既存のコード）
            // ...

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
            $movieCount = Movie::count();
            \Log::debug("Total movies in database: {$movieCount}");

            // もしデータベースが空なら、APIからデータを取得
            if ($movieCount === 0) {
                \Log::debug('Database is empty, fetching movies from API');
                $this->fetchMovies();
            }

            $selectedGenre = request()->get('genre');

            // ジャンル名の変換マップを定義
            $genreMap = [
                'アニメーション' => 'アニメ',
                'サイエンスフィクション' => 'SF',
                'アニメ' => 'アニメーション',
                'SF' => 'サイエンスフィクション'
            ];

            // 世界の興行収入データ
            $globalMovies = Movie::query()  // query()を明示的に呼び出す
                ->where('region', 'global')  // クォートを取り除く
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
                $movie->box_office_billion = number_format(($movie->box_office * 150) / 10000000000, 3);
                $movie->budget_billion = number_format(($movie->budget * 150) / 10000000000, 3);
                if ($movie->genres) {
                    $movie->genres = array_map(function($genre) use ($genreMap) {
                        return $genreMap[$genre] ?? $genre;
                    }, $movie->genres);
                }
                return $movie;
            });
            
            // 日本の興行収入データ
            $japanMovies = Movie::query()  // query()を明示的に呼び出す
                ->where('region', 'japan')  // クォートを取り除く
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
                $movie->box_office_billion = number_format($movie->box_office / 100000000, 3);
                $movie->budget_billion = number_format($movie->budget / 100000000, 3);
                if ($movie->genres) {
                    $movie->genres = array_map(function($genre) use ($genreMap) {
                        return $genreMap[$genre] ?? $genre;
                    }, $movie->genres);
                }
                return $movie;
            });

            // 利用可能なジャンルの一覧を取得し、変換
            $availableGenres = Movie::select('genres')
                ->get()
                ->pluck('genres')
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

            return view('movies.index', compact('globalMovies', 'japanMovies', 'availableGenres', 'selectedGenre', 'genreColors'));
        } catch (\Exception $e) {
            \Log::error('Error in index method: ' . $e->getMessage());
            throw $e;
        }
    }
}
