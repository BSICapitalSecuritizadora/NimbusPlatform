<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Document extends Model
{
    use LogsActivity;

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logFillable()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Visível no site público (publicado + público).
     *
     * @return Builder<self>
     */
    public function scopeVisibleOnPublicSite(Builder $query): Builder
    {
        return $query->published()->public();
    }

    /**
     * Visível para um investidor no portal (publicado + vínculo ou público).
     *
     * @return Builder<self>
     */
    public function scopeVisibleForInvestor(Builder $query, int $investorId): Builder
    {
        return $query->published()
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
