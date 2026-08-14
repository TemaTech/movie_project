<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JapaneseMovie extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'japanese_movies';
    
    protected $primaryKey = 'movie_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'movie_id',
        'tmdb_id',
        'title',
        'original_title',
        'poster_path',
        'box_office',
        'budget',
        'release_date',
        'release_date_precision',
        'genres',
        'production_country',
        'distributor',
        'data_source',
        'data_source_url',
        'last_updated',
        'is_active'
    ];

    protected $casts = [
        'genres' => 'json',
        'release_date' => 'date',
        'box_office' => 'integer',
        'budget' => 'integer',
        'last_updated' => 'datetime',
        'tmdb_id' => 'integer',
        'is_active' => 'boolean'
    ];

    protected $attributes = [
        'genres' => '[]',
        'production_country' => '日本'
    ];


} 