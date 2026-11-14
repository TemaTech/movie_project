<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalMovie extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $table = 'global_movies';

    protected $primaryKey = 'movie_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

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
        'region',
        'genres',
        'data_source',
        'data_source_url',
        'last_updated',
        'is_active',
        'in_ranking',
        'tmdb_collection_id',
        'tmdb_collection_name',
    ];

    protected $casts = [
        'genres' => 'json',
        'release_date' => 'date',
        'box_office' => 'integer',
        'budget' => 'integer',
        'last_updated' => 'datetime',
        'is_active' => 'boolean',
        'in_ranking' => 'boolean',
        'tmdb_collection_id' => 'integer',
    ];

    protected $attributes = [
        'genres' => '[]',
    ];
}
