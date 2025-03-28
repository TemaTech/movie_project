<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->string('production_country')->nullable()->after('genres');
        });
    }

    public function down()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->dropColumn('production_country');
        });
    }
}; 