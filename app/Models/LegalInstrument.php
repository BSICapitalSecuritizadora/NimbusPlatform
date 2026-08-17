<?php

namespace App\Models;

use App\Enums\LegalInstrumentType;
use Database\Factories\LegalInstrumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Instrumento jurídico da emissão — CCB, CCI, AFI, cessão, Termo etc.
 *
 * O model guarda só a identidade. Valor, partes, prazos e cobertura não são
 * colunas aqui: eles mudam por aditamento e vivem em
 * {@see LegalInstrumentField}, versionados e com proveniência. Perguntar
 * "quanto vale esta CCB?" é perguntar pela posição vigente, não por um atributo.
 */
class LegalInstrument extends Model
{
    /** @use HasFactory<LegalInstrumentFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_OPTIONS = [
        self::STATUS_ACTIVE => 'Ativo',
        self::STATUS_SETTLED => 'Liquidado',
        self::STATUS_TERMINATED => 'Encerrado',
    ];

    protected $fillable = [
        'emission_id',
        'type',
        'number',
        'name',
        'status',
        'description',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => LegalInstrumentType::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Dossiê documental, na ordem em que a cadeia deve ser lida.
     *
     * Documentos sem data vão para o fim: sem data não há como afirmar que
     * precedem o original, e assumir que sim inverteria a consolidação.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LegalInstrumentDocument::class)
            ->orderByRaw('document_date IS NULL')
            ->orderBy('document_date')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(LegalInstrumentField::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LegalInstrumentEvent::class);
    }

    /** Garantias constituídas por este instrumento (§14 do escopo). */
    public function guarantees(): HasMany
    {
        return $this->hasMany(Guarantee::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if (filled($this->number)) {
            return sprintf('%s nº %s', $this->type->shortLabel(), $this->number);
        }

        return $this->name;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    /**
     * O documento que estabelece a posição base do instrumento.
     */
    public function baseDocument(): ?LegalInstrumentDocument
    {
        return $this->documents
            ->first(fn (LegalInstrumentDocument $document): bool => $document->role->isBase());
    }

    /**
     * Documento mais recente que efetivamente alterou a posição — o que a visão
     * executiva mostra como "última alteração" (§42 do escopo).
     */
    public function latestAmendment(): ?LegalInstrumentDocument
    {
        return $this->documents
            ->filter(fn (LegalInstrumentDocument $document): bool => ! $document->role->isBase()
                && $document->role->canAmendPosition())
            ->sortByDesc(fn (LegalInstrumentDocument $document): string => $document->document_date?->toDateString() ?? '')
            ->first();
    }

    public function hasPendingChanges(): bool
    {
        return $this->fields()->pendingReview()->exists();
    }
}
