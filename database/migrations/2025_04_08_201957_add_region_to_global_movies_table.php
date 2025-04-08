<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            if (!Schema::hasColumn('global_movies', 'region')) {
                $table->string('region')->default('global')->after('production_country');
            }
        });
    }

    public function down()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            if (Schema::hasColumn('global_movies', 'region')) {
                $table->dropColumn('region');
            }
        });
    }
};
