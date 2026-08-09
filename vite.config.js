import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/cinematic.css',
                'resources/css/ai-analysis.css',
                'resources/css/movie-modal.css',
                'resources/css/filter-modal.css',
                'resources/js/app.js',
                'resources/js/movie-modal.js',
                'resources/js/ai-analysis.js',
                'resources/js/filter-modal.js',
                'resources/js/static-site.js',
            ],
            refresh: true,
        }),
    ],
});
