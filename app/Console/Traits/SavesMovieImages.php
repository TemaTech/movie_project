<?php

namespace App\Console\Traits;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SavesMovieImages
{
    /**
     * Download and save movie poster image.
     *
     * @param string|null $tmdbPosterPath The poster path from TMDB (e.g. "/abc.jpg")
     * @param string $filenameBase Base filename without extension
     * @return string|null The relative path to the saved image (e.g. "posters/filename.jpg") or null
     */
    protected function downloadAndSaveImage(?string $tmdbPosterPath, string $filenameBase): ?string
    {
        if (empty($tmdbPosterPath)) {
            return null;
        }

        try {
            // Using w342 size for a good balance of quality and size
            $imageUrl = 'https://image.tmdb.org/t/p/w342' . $tmdbPosterPath;
            
            $response = Http::get($imageUrl);

            if ($response->successful()) {
                $imageContent = $response->body();
                $extension = 'jpg'; // TMDB uses JPG usually
                if (str_ends_with($tmdbPosterPath, '.png')) {
                    $extension = 'png';
                }
                
                $filename = "posters/{$filenameBase}.{$extension}";
                
                // Ensure directory exists (Storage::put handles this but good to be aware)
                Storage::disk('public')->put($filename, $imageContent);
                
                // Return path relative to storage root, which can be used with Storage::url()
                // Or if using symbol link, asset('storage/' . $filename)
                return $filename; 
            } else {
                Log::warning("Failed to download image: {$imageUrl}", ['status' => $response->status()]);
            }
        } catch (\Exception $e) {
            Log::error("Error saving image for {$filenameBase}: " . $e->getMessage());
        }

        return null;
    }
}
