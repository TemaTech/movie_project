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
    protected $description = 'Generate AI trends analysis for top ranking movies.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $this->info('Starting AI Analysis generation...');
        $this->info('DB Connection: ' . \DB::connection()->getDatabaseName());
        $this->info('DB Host: ' . config('database.connections.mysql.host'));

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

        // Only process top 20 for AI analysis to save resources/tokens
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

            // Rate Limiting: Sleep to avoid hitting Gemini Free Tier limits (approx 15 RPM)
            $this->info("Sleeping for 4 seconds to respect API rate limits...");
            sleep(4);
        }
    }

    /**
     * Generate analysis text using Gemini API.
     */
    private function generateAnalysis($movie, $table)
    {
        $apiKey = env('GEMINI_API_KEY');
        
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

        $models = [
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-flash-latest', 
            'gemini-pro-latest'
        ];

        try {
            $client = \Gemini::client($apiKey);

            foreach ($models as $model) {
                $attempts = 0;
                $maxAttempts = 2; // Try once, if limited wait long and retry once

                while ($attempts < $maxAttempts) {
                    try {
                        $result = $client->generativeModel(model: $model)->generateContent($prompt);
                        return $result->text();
                    } catch (\Exception $e) {
                        $msg = strtolower($e->getMessage());
                        
                        // Check for rate limit errors
                        if (str_contains($msg, 'quota') || str_contains($msg, '429') || str_contains($msg, 'rate limit')) {
                            $attempts++;
                            if ($attempts < $maxAttempts) {
                                // Free Tier is 15 RPM (approx). If we hit limit, penalty can be ~60s.
                                // Wait 70s to be absolutely sure the window resets.
                                $waitTime = 70; 
                                $this->warn("Rate limit hit for {$model}. Cooldown: Waiting {$waitTime}s to reset API quota window...");
                                sleep($waitTime);
                                continue;
                            }
                        }
                        
                        $this->warn("Model {$model} failed: " . $e->getMessage());
                        break; 
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error("API Error context for {$movie->title}: " . $e->getMessage());
            return null;
        }

        return null;
    }
}
