<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    // 明示的に接続を指定
    protected $connection = 'pgsql';
    
    // テーブル名
    protected $table = 'movies';
    
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

    // コンストラクタでも接続を確認
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        \Log::debug('Movie Model Connection:', [
            'connection' => $this->connection,
            'database' => config("database.connections.{$this->connection}")
        ]);
    }
}
