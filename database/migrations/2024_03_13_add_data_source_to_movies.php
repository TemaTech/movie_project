<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('data_source')->nullable();
            $table->string('data_source_url')->nullable();
            $table->timestamp('last_updated')->nullable();
        });
    }

    public function down()
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn(['data_source', 'data_source_url', 'last_updated']);
        });
    }
}; 