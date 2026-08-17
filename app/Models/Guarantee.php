<?php

namespace App\Models;

use App\Enums\GuaranteeCategory;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueSource;
use Carbon\CarbonInterface;
use Database\Factories\GuaranteeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Garantia de uma emissão: o que o instrumento jurídico determina que exista,
 * cruzado com o que os dados operacionais dizem que ela vale.
 *
 * `type` nulo é estado válido — significa "pendente de classificação", situação
 * das garantias cadastradas antes deste módulo. Nada aqui presume classificação.
 */
class Guarantee extends Model
{
    /** @use HasFactory<GuaranteeFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'emission_id',
        'legal_instrument_id',
        'type',
        'name',
        'legal_status',
        'construction_id',
        'fund_id',
        'identification',
        'contracted_value',
        'documentary_value',
        'requirement_basis',
        'requirement_value',
        'requirement_percentage',
        'requirement_base',
        'requirement_multiplier',
        'requirement_formula',
        'requirement_conditions',
        'eligibility_factor',
        'value_source',
        'counts_toward_coverage',
        'constituted_at',
        'registered_at',
        'released_at',
        'guarantee_type',
        'minimum_value',
        'validity_start_date',
        'validity_end_date',
        'description',
        'notes',
        'evaluation_frequency',
    ];

    protected function casts(): array
    {
        return [
            'type' => GuaranteeType::class,
            'legal_status' => GuaranteeLegalStatus::class,
            'requirement_basis' => GuaranteeRequirementBasis::class,
            'requirement_base' => GuaranteeRequirementBase::class,
            'value_source' => GuaranteeValueSource::class,
            'identification' => 'array',
            'minimum_value' => 'decimal:2',
            'contracted_value' => 'decimal:2',
            'documentary_value' => 'decimal:2',
            'requirement_value' => 'decimal:2',
            'requirement_percentage' => 'decimal:6',
            'requirement_multiplier' => 'decimal:4',
            'eligibility_factor' => 'decimal:6',
            'counts_toward_coverage' => 'boolean',
            'validity_start_date' => 'date',
            'validity_end_date' => 'date',
            'constituted_at' => 'date',
            'registered_at' => 'date',
            'released_at' => 'date',
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

    /** Instrumento jurídico que constitui esta garantia (§14 do escopo). */
    public function legalInstrument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrument::class);
    }

    /**
     * Campos consolidados próprios da garantia — matrícula vigente, cartório,
     * percentual cedido — versionados junto com o instrumento que os alterou.
     */
    public function instrumentFields(): HasMany
    {
        return $this->hasMany(LegalInstrumentField::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function documentReferences(): HasMany
    {
        return $this->hasMany(GuaranteeDocumentReference::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GuaranteeEvent::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(GuaranteeValuation::class);
    }

    public function monthlyPositions(): HasMany
    {
        return $this->hasMany(GuaranteeMonthlyPosition::class);
    }

    public function extractedGuarantees(): HasMany
    {
        return $this->hasMany(ExtractedGuarantee::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name
            ?? $this->guarantee_type
            ?? GuaranteeType::labelFor($this->type);
    }

    public function getTypeLabelAttribute(): string
    {
        return GuaranteeType::labelFor($this->type);
    }

    public function category(): ?GuaranteeCategory
    {
        return $this->type?->category();
    }

    /**
     * Fonte efetiva do valor atual: a escolhida manualmente, ou a padrão do
     * tipo. Sem tipo não há como adivinhar, e a resposta honesta é "manual".
     */
    public function resolvedValueSource(): GuaranteeValueSource
    {
        return $this->value_source
            ?? $this->type?->defaultValueSource()
            ?? GuaranteeValueSource::Manual;
    }

    /**
     * Fator de elegibilidade (haircut). Ausente significa 1,0 — sem deságio —
     * e não zero, que apagaria a garantia do cálculo.
     */
    public function resolvedEligibilityFactor(): float
    {
        $factor = $this->eligibility_factor;

        if ($factor === null) {
            return 1.0;
        }

        return max(0.0, (float) $factor);
    }

    public function resolvedRequirementBasis(): GuaranteeRequirementBasis
    {
        return $this->requirement_basis ?? GuaranteeRequirementBasis::None;
    }

    /**
     * A garantia estava juridicamente vigente na data informada?
     *
     * A liberação vale a partir da data juridicamente válida (§ cenário 5 do
     * escopo), e não da data em que o sistema soube dela — por isso a
     * comparação usa `released_at`, alimentada pelo evento de liberação.
     */
    public function isEffectiveOn(CarbonInterface $date): bool
    {
        if ($this->released_at !== null && $this->released_at->lte($date)) {
            return false;
        }

        if ($this->validity_start_date !== null && $this->validity_start_date->gt($date)) {
            return false;
        }

        if ($this->validity_end_date !== null && $this->validity_end_date->lt($date)) {
            return false;
        }

        return true;
    }

    /**
     * A garantia entra no somatório da cobertura na data informada?
     *
     * A situação consultada é a da data, não a atual: uma garantia liberada em
     * julho ainda compunha a cobertura em junho, e usar o status corrente
     * reescreveria retroativamente todas as competências anteriores.
     */
    public function contributesToCoverageOn(CarbonInterface $date): bool
    {
        if (! $this->counts_toward_coverage) {
            return false;
        }

        if (! $this->isEffectiveOn($date)) {
            return false;
        }

        return $this->legalStatusAsOf($date)->countsTowardCoverage();
    }

    /**
     * Avaliação vigente numa data-base: a mais recente cuja `valuation_date`
     * não ultrapasse a competência analisada.
     *
     * Um laudo emitido depois do fechamento não pode reescrever a cobertura de
     * um mês já apurado, então a busca é para trás, nunca a mais recente
     * em termos absolutos.
     */
    public function valuationAsOf(CarbonInterface $date): ?GuaranteeValuation
    {
        return $this->valuations
            ->filter(fn (GuaranteeValuation $valuation): bool => $valuation->valuation_date !== null
                && $valuation->valuation_date->lte($date))
            ->sortByDesc(fn (GuaranteeValuation $valuation): string => $valuation->valuation_date->toDateString())
            ->first();
    }

    /**
     * Posição jurídica reconstruída numa data, a partir dos eventos com efeito
     * até ela. Sem eventos aplicáveis vale a situação corrente do cadastro.
     */
    public function legalStatusAsOf(CarbonInterface $date): GuaranteeLegalStatus
    {
        $status = $this->events
            ->filter(fn (GuaranteeEvent $event): bool => $event->effective_date !== null
                && $event->effective_date->lte($date))
            ->sortBy([
                fn (GuaranteeEvent $a, GuaranteeEvent $b): int => $a->effective_date->toDateString() <=> $b->effective_date->toDateString(),
                fn (GuaranteeEvent $a, GuaranteeEvent $b): int => $a->id <=> $b->id,
            ])
            ->reduce(
                fn (?GuaranteeLegalStatus $carry, GuaranteeEvent $event): ?GuaranteeLegalStatus => $event->event_type?->resultingLegalStatus() ?? $carry,
                null,
            );

        if ($status !== null) {
            return $status;
        }

        $currentStatus = $this->legal_status ?? GuaranteeLegalStatus::PendingConfirmation;

        // Sem eventos registrados, o encerramento datado ainda informa o
        // passado: uma garantia liberada em 10/07 estava ativa em 30/06.
        // Tratá-la como liberada naquela data apagaria uma cobertura que
        // juridicamente existia.
        if ($currentStatus->isClosed() && $this->released_at !== null && $this->released_at->gt($date)) {
            return GuaranteeLegalStatus::Active;
        }

        return $currentStatus;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCountingTowardCoverage(Builder $query): Builder
    {
        $countingStatuses = array_values(array_map(
            static fn (GuaranteeLegalStatus $status): string => $status->value,
            array_filter(
                GuaranteeLegalStatus::cases(),
                static fn (GuaranteeLegalStatus $status): bool => $status->countsTowardCoverage(),
            ),
        ));

        return $query
            ->where('counts_toward_coverage', true)
            ->whereIn('legal_status', $countingStatuses);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return $query
            ->where(fn (Builder $inner) => $inner->whereNull('released_at')->orWhere('released_at', '>', $date))
            ->where(fn (Builder $inner) => $inner->whereNull('validity_start_date')->orWhere('validity_start_date', '<=', $date))
            ->where(fn (Builder $inner) => $inner->whereNull('validity_end_date')->orWhere('validity_end_date', '>=', $date));
    }
}
