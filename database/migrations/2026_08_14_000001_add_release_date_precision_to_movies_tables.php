<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            if (! Schema::hasColumn('japanese_movies', 'release_date_precision')) {
                $table->string('release_date_precision', 16)->nullable()->after('release_date');
            }
        });

        Schema::table('global_movies', function (Blueprint $table) {
            if (! Schema::hasColumn('global_movies', 'release_date_precision')) {
                $table->string('release_date_precision', 16)->nullable()->after('release_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            if (Schema::hasColumn('japanese_movies', 'release_date_precision')) {
                $table->dropColumn('release_date_precision');
            }
        });

        Schema::table('global_movies', function (Blueprint $table) {
            if (Schema::hasColumn('global_movies', 'release_date_precision')) {
                $table->dropColumn('release_date_precision');
            }
        });
    }
};
