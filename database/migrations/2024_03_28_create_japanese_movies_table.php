<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('japanese_movies', function (Blueprint $table) {
            $table->id();
            $table->string('movie_id')->unique();
            $table->string('title');
            $table->bigInteger('box_office')->default(0);
            $table->bigInteger('budget')->default(0);
            $table->date('release_date')->nullable();
            $table->json('genres')->nullable();
            $table->string('production_country')->default('日本');
            $table->string('distributor')->nullable();
            $table->string('data_source')->nullable();
            $table->string('data_source_url')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            // インデックスの追加
            $table->index('release_date');
            $table->index('distributor');
            $table->index(['box_office', 'release_date']);
            $table->index('genres');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('japanese_movies');
    }
}; 