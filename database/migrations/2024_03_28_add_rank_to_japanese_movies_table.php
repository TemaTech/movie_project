<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->integer('rank')->after('box_office')->nullable();
        });
    }

    public function down()
    {
        Schema::table('japanese_movies', function (Blueprint $table) {
            $table->dropColumn('rank');
        });
    }
}; 