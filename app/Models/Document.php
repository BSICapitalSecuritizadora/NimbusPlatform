<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Document extends Model
{
    protected $fillable = [
        'title',
        'category',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'is_published',
        'is_public',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_published' => 'boolean',
        'is_public' => 'boolean',
    ];

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_document')->withTimestamps();
    }

    public function emissions(): BelongsToMany
    {
        return $this->belongsToMany(Emission::class, 'emission_document')->withTimestamps();
    }
}