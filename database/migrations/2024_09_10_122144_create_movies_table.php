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
        Schema::create('movies', function (Blueprint $table) {
            $table->id(); // 自動的に 'id' カラムが主キーとして追加される
            $table->unsignedBigInteger('movie_id')->unique(); // 映画APIからの映画IDは一意
            $table->string('title');
            $table->bigInteger('box_office')->nullable(); // 興行収入はbigIntegerに変更
            $table->date('release_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
