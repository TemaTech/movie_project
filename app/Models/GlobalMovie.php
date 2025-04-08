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
        'title',
        'box_office',
        'budget',
        'release_date',
        'region',
        'genres',
        'data_source',
        'data_source_url',
        'last_updated'
    ];

    protected $casts = [
        'genres' => 'json',
        'release_date' => 'date',
        'box_office' => 'integer',
        'budget' => 'integer',
        'last_updated' => 'datetime'
    ];

    protected $attributes = [
        'genres' => '[]'
    ];

    protected function setGenresAttribute($value)
    {
        $this->attributes['genres'] = is_array($value) ? json_encode($value) : $value;
    }

    protected function getGenresAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }
}