<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->integer('rank')->nullable()->after('box_office');
        });
    }

    public function down()
    {
        Schema::table('global_movies', function (Blueprint $table) {
            $table->dropColumn('rank');
        });
    }
}; 