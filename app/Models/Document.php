<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @return Builder<self>
     */
    public function scopePublishedOnSite(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->where('is_public', true);
    }

    /**
     * @return Builder<self>
     */
    public function scopeVisibleToInvestor(Builder $query, int $investorId): Builder
    {
        return $query
            ->where('is_published', true)
            ->where(function (Builder $q) use ($investorId): void {
                $q->whereHas('investors', fn (Builder $qq) => $qq->where('investor_id', $investorId))
                    ->orWhere('is_public', true);
            });
    }

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_document')->withTimestamps();
    }

    public function emissions(): BelongsToMany
    {
        return $this->belongsToMany(Emission::class, 'emission_document')->withTimestamps();
    }
}