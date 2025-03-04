<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    // テーブル名
    protected $table = 'movies';
    
    // コネクション名を環境変数から取得
    protected $connection = 'pgsql';

    // プライマリーキー設定
    protected $primaryKey = 'movie_id';
    protected $keyType = 'string';
    public $incrementing = false;

    // タイムスタンプを無効化（必要な場合は削除）
    public $timestamps = false;

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
        'release_date' => 'date',
        'box_office' => 'integer',
        'budget' => 'integer'
    ];
}
