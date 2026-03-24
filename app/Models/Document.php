<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, LogsActivity;

    public const CATEGORY_OPTIONS = [
        'anuncios' => 'Anúncios',
        'assembleias' => 'Assembleias',
        'convocacoes_assembleias' => 'Convocações para Assembleias',
        'demonstracoes_financeiras' => 'Demonstrações Financeiras',
        'documentos_operacao' => 'Documentos da Operação',
        'fatos_relevantes' => 'Fatos Relevantes',
        'governanca' => 'Governança',
        'relatorios_anuais' => 'Relatórios Anuais',
    ];

    protected $fillable = [
        'title',
        'category',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'storage_disk',
        'is_published',
        'is_public',
        'version',
        'parent_document_id',
        'replaced_at',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_published' => 'boolean',
        'is_public' => 'boolean',
        'published_at' => 'datetime',
    ];

    public static function defaultStorageDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return $defaultDisk === 'local' ? 'public' : $defaultDisk;
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: self::defaultStorageDisk();
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORY_OPTIONS[$this->category] ?? $this->category;
    }

    public function getWorkflowStatusLabelAttribute(): string
    {
        if ($this->is_public) {
            return 'Público';
        }

        if ($this->is_published) {
            return 'Publicado';
        }

        return 'Rascunho';
    }

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
     * @return Builder<self>
     */
    public function scopeVisibleOnPublicSite(Builder $query): Builder
    {
        return $query->published()->public();
    }

    /**
     * Documentos visíveis para um investidor no portal:
     * - precisa estar publicado
     * - e (vinculado ao investidor OU vinculado a uma emissão do investidor)
     * - opcional: incluir documentos públicos no portal (comenta/descomenta)
     *
     * @return Builder<self>
     */
    public function scopeVisibleToInvestor(Builder $query, int $investorId): Builder
    {
        return $query
            ->published()
            ->where(function (Builder $q) use ($investorId): void {
                // 1) Vínculo direto ao investidor
                $q->whereHas('investors', fn (Builder $qq) => $qq->whereKey($investorId))

                  // 2) Vínculo por emissão (somente se o investidor estiver vinculado à emissão)
                  ->orWhereHas('emissions.investors', fn (Builder $qq) => $qq->whereKey($investorId));

                  // 3) (Opcional) Documentos públicos também aparecem no portal:
                  // ->orWhere('is_public', true);
            });
    }

    /**
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
