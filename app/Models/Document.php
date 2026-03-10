<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'title',
        'category',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'is_published',
        'is_public',
        'version',
        'parent_document_id',
        'replaced_at',
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
     * Documentos que um investidor pode acessar no portal:
     * - publicados
     * - e (públicos OU vinculados diretamente ao investidor OU vinculados a emissões do investidor)
     *
     * @return Builder<self>
     */
    public function scopeVisibleToInvestor(Builder $query, int $investorId): Builder
    {
        return $query
            ->published()
            ->where(function (Builder $q) use ($investorId): void {
                $q->where('is_public', true)
                    ->orWhereHas('investors', fn (Builder $qq) => $qq->whereKey($investorId))
                    ->orWhereHas('emissions.investors', fn (Builder $qq) => $qq->whereKey($investorId));
            });
    }

    /**
     * Ordena por prioridade de vínculo:
     * 1 = direto ao investidor, 2 = via emissão, 3 = público.
     *
     * @return Builder<self>
     */
    public function scopeOrderByVisibilityPriority(Builder $query, int $investorId): Builder
    {
        return $query->orderByRaw('
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM investor_document
                    WHERE investor_document.document_id = documents.id
                    AND investor_document.investor_id = ?
                ) THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM emission_document
                    JOIN investor_emission ON investor_emission.emission_id = emission_document.emission_id
                    WHERE emission_document.document_id = documents.id
                    AND investor_emission.investor_id = ?
                ) THEN 2
                ELSE 3
            END
        ', [$investorId, $investorId]);
    }

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_document')->withTimestamps();
    }

    public function emissions(): BelongsToMany
    {
        return $this->belongsToMany(Emission::class, 'emission_document')->withTimestamps();
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }
}
