<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateAiAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-ai-analysis {--type=all : The type of ranking to generate (global/japan/all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate AI trends analysis for top ranking movies using Gemini.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $this->info('Starting AI Analysis generation (Paid Tier Mode)...');
        $this->info('DB Connection: ' . \DB::connection()->getDatabaseName());

        if ($type === 'global' || $type === 'all') {
            $this->processTable('global_movies', 'Global Ranking');
        }
        
        if ($type === 'japan' || $type === 'all') {
            $this->processTable('japanese_movies', 'Japanese Ranking');
        }

        $this->info('AI Analysis generation completed successfully.');
    }

    private function processTable($tableName, $label)
    {
        $this->info("Processing {$label}...");

        // Process top 20 movies
        $movies = \DB::table($tableName)->orderBy('rank')->limit(20)->get();

        foreach ($movies as $movie) {
            $analysis = $this->generateAnalysis($movie, $tableName);
            
            if ($analysis) {
                \DB::table($tableName)
                    ->where('id', $movie->id)
                    ->update(['ai_analysis' => $analysis]);
                
                $this->line("Updated analysis for: {$movie->title}");
            } else {
                $this->error("Failed to generate analysis for: {$movie->title}");
            }
        }
    }

    /**
     * Generate analysis text using Gemini API.
     */
    private function generateAnalysis($movie, $table)
    {
        $apiKey = config('services.gemini.api_key');
        
        if (!$apiKey) {
            $this->error("GEMINI_API_KEY is not set in .env");
            return null;
        }

        $context = $table === 'japanese_movies' ? "日本国内" : "世界";
        $prompt = "
            映画『{$movie->title}』は現在、{$context}の興行収入ランキングで第{$movie->rank}位です。
            この映画が今ヒットしている理由、または注目されている背景を、以下のルールで分析し、一言で解説してください。

            ルール：
            1. 50文字以内の日本語で簡潔にまとめること。
            2. 「〜だからヒット」「〜で話題」のような、理由がわかる文脈を含めること。
            3. 評論家のような少し知的でかっこいい口調（〜とのこと、〜だそうだ、等の伝聞は禁止。「〜である」「〜が熱い」等で言い切る）。
            4. ネタバレは禁止。
            5. 具体的な興行収入の数字は含めないでよい（コンテキストとして既にユーザーに見えているため）。
        ";

        // Gemini 2.0 Flash was shut down on 2026-06-01.
        // The model can be overridden with GEMINI_MODEL without changing code.
        $model = config('services.gemini.model', 'gemini-3.5-flash');

        try {
            // 両方の環境で動作するように、クラスの存在を確認して適切なものを使用
            // ローカル: Gemini\Gemini（名前空間あり）, 本番: Gemini（グローバル）
            // 第2引数falseでオートロードを無効化し、二重読み込みを防止
            if (class_exists('Gemini\\Gemini', false)) {
                $client = \Gemini\Gemini::client($apiKey);
            } elseif (class_exists('Gemini', false)) {
                $client = \Gemini::client($apiKey);
            } else {
                // どちらも存在しない場合、まずGemini\Geminiを試す（オートロード有効）
                $client = class_exists('Gemini\\Gemini') 
                    ? \Gemini\Gemini::client($apiKey) 
                    : \Gemini::client($apiKey);
            }
            $result = $client->generativeModel(model: $model)->generateContent($prompt);
            return $result->text();
        } catch (\Exception $e) {
            $this->error("API Error for {$movie->title}: " . $e->getMessage());
            return null;
        }
    }
}
