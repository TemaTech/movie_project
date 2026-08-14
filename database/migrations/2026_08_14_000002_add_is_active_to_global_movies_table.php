<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_movies', function (Blueprint $table) {
            if (! Schema::hasColumn('global_movies', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('rank');
            }
        });
    }

    public function down(): void
    {
        Schema::table('global_movies', function (Blueprint $table) {
            if (Schema::hasColumn('global_movies', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
