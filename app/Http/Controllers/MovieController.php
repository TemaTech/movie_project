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
        
        // HTTPクライアントのデフォルト設定
        Http::macro('tmdb', function () {
            return Http::timeout(30)
                ->retry(3, 1000)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]);
        });
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
        // 日本映画のID（jp_で始まる）の場合
        if (str_starts_with($id, 'jp_')) {
            $movie = JapaneseMovie::where('movie_id', $id)->first();
            
            if (!$movie) {
                abort(404);
            }

            // ビューで使いやすいようにデータを整形
            $movieData = $movie->toArray();
            
            // ジャンルの変換（JSON文字列または配列を配列に統一）
            if (is_string($movieData['genres'])) {
                $movieData['genres'] = json_decode($movieData['genres'], true) ?? [];
            }
            
            // ジャンル名の変換マップ
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
            
            if (!empty($movieData['genres'])) {
                $movieData['genres'] = array_map(function($genre) use ($genreMap) {
                    return $genreMap[$genre] ?? $genre;
                }, $movieData['genres']);
            }

            // 予算のフォーマット（数値の場合のみ）
            if (isset($movieData['budget']) && is_numeric($movieData['budget'])) {
                $movieData['budget_billion'] = number_format($movieData['budget'] / 100000000, 1);
            }

            // TMDB互換のキーを追加
            $movieData['id'] = $movie->movie_id;
            $movieData['original_title'] = $movie->title; // 日本映画は原題＝タイトルとする
            
            return view('movies.show', ['movie' => $movieData]);
        }

        // 数値でないIDは404（jp_以外）
        if (!ctype_digit((string)$id)) {
            abort(404);
        }

        try {
            $response = Http::get("{$this->baseUrl}/movie/{$id}", [
                'api_key' => $this->apiKey,
                'language' => 'ja-JP',
                'append_to_response' => 'credits,videos'
            ]);

            if ($response->successful() && $response->json()) {
                $movie = $response->json();
                return view('movies.show', compact('movie'));
            }

            abort(404);

        } catch (\Exception $e) {
            abort(404);
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
            $response = Http::tmdb()->get('https://api.themoviedb.org/3/discover/movie', [
                'api_key' => $api_key,
                'sort_by' => 'revenue.desc',
                'language' => 'ja'
            ]);

            if (!$response->successful()) {
                Log::error('TMDB API エラー: ' . $response->status() . ' - ' . $response->body());
                throw new \Exception('映画データの取得に失敗しました。');
            }

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

            return redirect()->route('movies.index')->with('success', '映画データを更新しました');

        } catch (\Exception $e) {
            Log::error('エラー発生: ' . $e->getMessage());
            return redirect()->route('movies.index')->with('error', 'エラーが発生しました');
        }
    }

    public function index()
    {
        try {
            // クエリ付きのホームアクセスは正規URLへ恒久的リダイレクト (ジャンル絞り込みのために無効化)
            // if (request()->routeIs('movies.index') && count(request()->query()) > 0) {
            //     return redirect()->to(rtrim(config('app.url'), '/') . '/', 301);
            // }
            // データベース接続情報のデバッグ
            \Log::debug('Database Connection Settings:', [
                'connection' => config('database.default'),
                'host' => config('database.connections.mysql.host'),
                'port' => config('database.connections.mysql.port'),
                'database' => config('database.connections.mysql.database'),
                'username' => config('database.connections.mysql.username')
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

            // タイトル変換マップを定義
            $titleMap = [
                '哪吒之魔童闹海' => 'ナタ 魔童の大暴れ'
            ];

            // 世界の興行収入データ
            $globalMovies = GlobalMovie::query()
                ->when($selectedGenre, function($query) use ($selectedGenre, $genreMap) {
                    $searchGenre = array_flip($genreMap)[$selectedGenre] ?? $selectedGenre;
                    return $query->whereRaw("JSON_CONTAINS(genres, ?)", [json_encode([$searchGenre])]);
                })
                ->orderBy('box_office', 'desc')
                ->paginate(100, ['*'], 'global_page');

            // rankを設定し、その他の変換も行う
            $rank = ($globalMovies->currentPage() - 1) * $globalMovies->perPage() + 1;
            $globalMovies->through(function ($movie) use ($genreMap, &$rank, $titleMap) {
                $movie->rank = $rank++;
                // タイトルの変換を追加
                $dbTitle = $movie->title;
                $movie->title = $titleMap[$dbTitle] ?? $dbTitle;
                // original_titleがデータベースにない、またはタイトルと同じ場合のみ、
                // 必要に応じて代入するようにする（既に正しい値がある場合は保持）
                if (empty($movie->original_title)) {
                    $movie->original_title = $dbTitle;
                }
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
                    return $query->whereRaw("JSON_CONTAINS(genres, ?)", [json_encode([$searchGenre])]);
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
                $query->whereRaw("JSON_CONTAINS(genres, ?)", [json_encode([$searchGenre])]);
            }

            // 興行収入でソート
            $query->orderBy('box_office', 'desc');

            $total = $query->count();
            $movies = $query->paginate($perPage);

            // データの変換処理
            $transformedData = collect($movies->items())->map(function ($movie, $index) use ($titleMap, $tab, $page, $perPage, $genreMap) {
                // タイトルの変換
                $dbTitle = $movie->title;
                $movie->title = $titleMap[$dbTitle] ?? $dbTitle;
                if (empty($movie->original_title)) {
                    $movie->original_title = $dbTitle;
                }

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

                // 元のランクを保持
                $movie->original_rank = $movie->rank;
                // フィルタリング後のランクを設定
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
