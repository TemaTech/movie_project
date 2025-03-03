<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    // テーブル名
    protected $table = 'movies';

    // 更新可能なカラムの定義
    protected $fillable = [
        'movie_id',
        'title',
        'box_office',
        'budget',
        'release_date',
        'region',
        'genres'
    ];

    // PostgreSQL用にJSON型を明示的に指定
    protected $casts = [
        'genres' => 'array',
        'release_date' => 'date'
    ];
}
