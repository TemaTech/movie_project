<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movies')) {
            // インデックスの削除
            Schema::table('movies', function (Blueprint $table) {
                $table->dropIndex('idx_movies_genres');
                $table->dropIndex('idx_movies_region_box_office');
            });

            // moviesテーブルをglobal_moviesにリネーム
            Schema::rename('movies', 'global_movies');
        }

        if (Schema::hasTable('global_movies')) {
            Schema::table('global_movies', function (Blueprint $table) {
                if (!Schema::hasColumn('global_movies', 'data_source')) {
                    $table->string('data_source')->nullable();
                }
                if (!Schema::hasColumn('global_movies', 'data_source_url')) {
                    $table->string('data_source_url')->nullable();
                }
                if (!Schema::hasColumn('global_movies', 'last_updated')) {
                    $table->timestamp('last_updated')->nullable();
                }

                // 新しいインデックスの作成
                $table->index('genres', 'idx_global_movies_genres');
                $table->index('box_office', 'idx_global_movies_box_office');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('global_movies')) {
            Schema::table('global_movies', function (Blueprint $table) {
                $table->dropIndex('idx_global_movies_genres');
                $table->dropIndex('idx_global_movies_box_office');
                
                $columns = ['data_source', 'data_source_url', 'last_updated'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('global_movies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            Schema::rename('global_movies', 'movies');
        }
    }
}; 