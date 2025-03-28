<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 既存のインデックスを削除
        if (Schema::hasTable('movies')) {
            Schema::table('movies', function (Blueprint $table) {
                DB::statement('DROP INDEX IF EXISTS idx_movies_genres');
                DB::statement('DROP INDEX IF EXISTS idx_movies_region_box_office');
            });

            // moviesテーブルをglobal_moviesにリネーム
            Schema::rename('movies', 'global_movies');
        }

        // global_moviesテーブルが存在する場合のみカラムを追加
        if (Schema::hasTable('global_movies')) {
            // カラムの存在チェックと追加
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
            });

            // 新しいインデックスの作成
            if (!$this->hasIndex('global_movies', 'idx_global_movies_genres')) {
                DB::statement('CREATE INDEX idx_global_movies_genres ON global_movies USING GIN (genres)');
            }
            if (!$this->hasIndex('global_movies', 'idx_global_movies_box_office')) {
                DB::statement('CREATE INDEX idx_global_movies_box_office ON global_movies (box_office DESC)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('global_movies')) {
            // インデックスの削除
            DB::statement('DROP INDEX IF EXISTS idx_global_movies_genres');
            DB::statement('DROP INDEX IF EXISTS idx_global_movies_box_office');
            
            // カラムの削除（存在する場合のみ）
            Schema::table('global_movies', function (Blueprint $table) {
                $columns = ['data_source', 'data_source_url', 'last_updated'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('global_movies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            // テーブル名を元に戻す
            Schema::rename('global_movies', 'movies');
        }
    }

    /**
     * インデックスが存在するかチェックする
     */
    private function hasIndex($table, $index)
    {
        return DB::select("SELECT to_regclass('public.{$index}') as index")[0]->index !== null;
    }
}; 