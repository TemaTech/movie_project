<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // GINインデックスの作成
        DB::statement('CREATE INDEX idx_movies_genres ON movies USING GIN (genres)');

        // 複合インデックスの作成
        Schema::table('movies', function (Blueprint $table) {
            $table->index(['region', 'box_office'], 'idx_movies_region_box_office');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            // GINインデックスの削除
            DB::statement('DROP INDEX IF EXISTS idx_movies_genres');
            
            // 複合インデックスの削除
            $table->dropIndex('idx_movies_region_box_office');
        });
    }
}; 