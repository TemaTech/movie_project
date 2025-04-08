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
        Schema::table('movies', function (Blueprint $table) {
            // MySQLではJSON型のカラムに対して通常のインデックスを作成
            $table->index('genres', 'idx_movies_genres');
            $table->index(['region', 'box_office'], 'idx_movies_region_box_office');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropIndex('idx_movies_genres');
            $table->dropIndex('idx_movies_region_box_office');
        });
    }
}; 