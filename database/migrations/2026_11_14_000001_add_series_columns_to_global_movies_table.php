<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->boolean('in_ranking')->default(true)->after('is_active');
            $table->unsignedInteger('tmdb_collection_id')->nullable()->after('tmdb_id');
            $table->string('tmdb_collection_name')->nullable()->after('tmdb_collection_id');
            $table->index('in_ranking');
            $table->index('tmdb_collection_id');
        });
    }

    public function down(): void
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->dropIndex(['in_ranking']);
            $table->dropIndex(['tmdb_collection_id']);
            $table->dropColumn(['in_ranking', 'tmdb_collection_id', 'tmdb_collection_name']);
        });
    }
};
