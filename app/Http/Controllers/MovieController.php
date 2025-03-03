<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Movie;

class MovieController extends Controller
{
    public function fetchMovies()
    {
        try {
            $api_key = config('services.tmdb.api_key'); // APIキーはconfig/services.phpに設定
    
            // 世界の映画を取得（5ページ分 = 100件）
            for ($page = 1; $page <= 5; $page++) {
                $globalResponse = Http::get("https://api.themoviedb.org/3/discover/movie", [
                    'api_key' => $api_key,
                    'sort_by' => 'revenue.desc',
                    'language' => 'ja',
                    'include_adult' => false,
                    'include_video' => false,
                    'page' => $page
                ]);
    
                if ($globalResponse->successful()) {
                    $movies = $globalResponse->json()['results'];
                    
                    // APIレスポンスの全体をデバッグ出力
                    // dd([
                    //     'APIレスポンス全体' => $globalResponse->json(),
                    //     '最初の映画の詳細' => Http::get("https://api.themoviedb.org/3/movie/{$movies[0]['id']}", [
                    //         'api_key' => $api_key,
                    //         'language' => 'ja'
                    //     ])->json(),
                    //     'ジャンルデータの例' => collect($movies[0]['genre_ids'])->toArray()
                    // ]);
    
                    Log::info('世界の映画データを取得: ' . count($movies) . '件');
    
                    foreach ($movies as $movie) {
                        $movieDetails = Http::get("https://api.themoviedb.org/3/movie/{$movie['id']}", [
                            'api_key' => $api_key,
                            'language' => 'ja'
                        ])->json();
    
                        // デバッグ用のログを追加
                        Log::info('映画詳細データ: ', [
                            'title' => $movie['title'],
                            'budget' => $movieDetails['budget'] ?? 0,
                            'revenue' => $movieDetails['revenue'] ?? 0
                        ]);
    
                        $genres = [];
                        if (isset($movieDetails['genres']) && is_array($movieDetails['genres'])) {
                            $genres = collect($movieDetails['genres'])->pluck('name')->toArray();
                        }
    
                        Movie::updateOrCreate(
                            ['movie_id' => $movie['id']],
                            [
                                'title' => $movie['title'],
                                'box_office' => $movieDetails['revenue'] ?? 0,
                                'budget' => $movieDetails['budget'] ?? 0,
                                'release_date' => $movie['release_date'] ?? null,
                                'region' => 'global',
                                'genres' => $genres
                            ]
                        );
    
                        Log::info('映画のジャンルデータ: ', [
                            'title' => $movie['title'],
                            'genres' => $genres
                        ]);
                    }
                }
            }
    
            // 日本の映画を取得（5ページ分 = 100件）
            for ($page = 1; $page <= 5; $page++) {
                $japanResponse = Http::get("https://api.themoviedb.org/3/discover/movie", [
                    'api_key' => $api_key,
                    'sort_by' => 'revenue.desc',
                    'language' => 'ja',
                    'with_original_language' => 'ja',
                    'region' => 'japan',
                    'include_adult' => false,
                    'include_video' => false,
                    'page' => $page
                ]);
    
                if ($japanResponse->successful()) {
                    $japanMovies = $japanResponse->json()['results'];
                    Log::info('取得した日本映画数: ' . count($japanMovies));
    
                    foreach ($japanMovies as $movie) {
                        $movieDetails = Http::get("https://api.themoviedb.org/3/movie/{$movie['id']}", [
                            'api_key' => $api_key,
                            'language' => 'ja'
                        ])->json();
    
                        // デバッグ用のログを追加
                        Log::info('映画詳細データ: ', [
                            'title' => $movie['title'],
                            'budget' => $movieDetails['budget'] ?? 0,
                            'revenue' => $movieDetails['revenue'] ?? 0
                        ]);
    
                        $genres = [];
                        if (isset($movieDetails['genres']) && is_array($movieDetails['genres'])) {
                            $genres = collect($movieDetails['genres'])->pluck('name')->toArray();
                        }
    
                        if (isset($movieDetails['revenue']) && $movieDetails['revenue'] > 0) {
                            // ここでデータを保存する際のregionの値を確認
                            Log::info('保存前の映画データ: ', [
                                'title' => $movie['title'],
                                'region' => 'japan'  // この値が正しく設定されているか
                            ]);
                            
                            Movie::updateOrCreate(
                                ['movie_id' => $movie['id']],
                                [
                                    'title' => $movie['title'],
                                    'box_office' => $movieDetails['revenue'],
                                    'budget' => $movieDetails['budget'] ?? 0,
                                    'release_date' => $movie['release_date'] ?? null,
                                    'region' => 'japan',
                                    'genres' => $genres
                                ]
                            );
                            Log::info('保存した日本映画: ' . $movie['title'] . ' (region: japan)');
                        }
                    }
                }
            }
    
            return redirect('/movies')->with('success', '映画データを更新しました');
    
        } catch (\Exception $e) {
            Log::error('エラー発生: ' . $e->getMessage());
            return redirect('/movies')->with('error', 'データの取得に失敗しました: ' . $e->getMessage());
        }
    }

    public function index()
    {
        $selectedGenre = request()->get('genre');

        // ジャンル名の変換マップを定義
        $genreMap = [
            'アニメーション' => 'アニメ',
            'サイエンスフィクション' => 'SF'
        ];

        // 世界の興行収入データ
        $globalMovies = Movie::where('region', 'global');
        if ($selectedGenre) {
            $searchGenre = array_flip($genreMap)[$selectedGenre] ?? $selectedGenre;
            $escapedGenre = json_encode($searchGenre);
            $globalMovies = $globalMovies->whereRaw('JSON_CONTAINS(genres, ?)', [$escapedGenre]);
        }
        $globalMovies = $globalMovies->orderBy('box_office', 'desc')
                                    ->paginate(20, ['*'], 'global_page');

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
        $japanMovies = Movie::where('region', 'japan');
        if ($selectedGenre) {
            $searchGenre = array_flip($genreMap)[$selectedGenre] ?? $selectedGenre;
            $escapedGenre = json_encode($searchGenre);
            $japanMovies = $japanMovies->whereRaw('JSON_CONTAINS(genres, ?)', [$escapedGenre]);
        }
        $japanMovies = $japanMovies->orderBy('box_office', 'desc')
                                   ->paginate(20, ['*'], 'japan_page');

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

        return view('movies.index', compact('globalMovies', 'japanMovies', 'availableGenres', 'selectedGenre', 'genreColors'));
    }
}
