<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->unsignedBigInteger('tmdb_id')->nullable()->after('movie_id');
            $table->string('poster_path')->nullable()->after('original_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->dropColumn(['tmdb_id', 'poster_path']);
        });
    }
};
