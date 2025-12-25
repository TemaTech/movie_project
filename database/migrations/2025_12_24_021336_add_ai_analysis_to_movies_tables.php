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
        Schema::table('global_movies', function (Blueprint $table) {
            $table->text('ai_analysis')->nullable();
        });

        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->text('ai_analysis')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->dropColumn('ai_analysis');
        });

        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->dropColumn('ai_analysis');
        });
    }
};
