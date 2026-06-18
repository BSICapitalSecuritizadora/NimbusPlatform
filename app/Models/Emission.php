<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Emission extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionFactory> */
    use HasFactory, LogsActivity;

    protected static function booted(): void
    {
        static::saving(function (self $emission): void {
            $emission->offer_type = 'CVM 160';

            if ($emission->isDirty(['remuneration_indexer', 'remuneration_rate'])) {
                $emission->remuneration = self::formatRemuneration(
                    $emission->remuneration_indexer,
                    $emission->remuneration_rate,
                );
            }
        });

        static::created(function (self $emission): void {
            if (filled($emission->bsi_code)) {
                return;
            }

            $emission->forceFill([
                'bsi_code' => self::generateBsiCode($emission),
            ])->saveQuietly();
        });
    }

    public static function defaultStorageDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return $defaultDisk === 'local' ? 'public' : $defaultDisk;
    }

    public function getLogoStorageDiskAttribute(): string
    {
        return self::defaultStorageDisk();
    }

    public const TYPE_OPTIONS = [
        'CR' => 'CR',
        'CRA' => 'CRA',
        'CRI' => 'CRI',
    ];

    public const REMUNERATION_INDEXER_OPTIONS = [
        'CDI' => 'CDI',
        'IPCA' => 'IPCA',
        'Prefixado' => 'Prefixado',
    ];

    public const STATUS_OPTIONS = [
        'draft' => 'Em Elaboração',
        'default' => 'Default',
        'active' => 'Em Distribuição',
        'closed' => 'Liquidada',
    ];

    public const ISSUER_SITUATION_OPTIONS = [
        'Recuperação Judicial' => 'Recuperação Judicial',
        'Inadimplente' => 'Inadimplente',
        'Adimplente' => 'Adimplente',
        'Falência' => 'Falência',
    ];

    public const FORM_OPTIONS = [
        'Nominativa e escritural' => 'Nominativa e escritural',
        'Nominativa' => 'Nominativa',
        'Escritural' => 'Escritural',
        'Cartular' => 'Cartular',
    ];

    protected $fillable = [
        'name',
        'logo_path',
        'type',
        'if_code',
        'isin_code',
        'status',
        'issuer_situation',
        'bsi_code',
        'issuer',
        'lead_coordinator',
        'settlement_bank',
        'registrar',
        'distributor',
        'fiduciary_regime',
        'issue_date',
        'maturity_date',
        'monetary_update_period',
        'series',
        'emission_number',
        'issued_quantity',
        'monetary_update_months',
        'interest_payment_frequency',
        'offer_type',
        'concentration',
        'issued_price',
        'amortization_frequency',
        'integralized_quantity',
        'trustee_agent',
        'debtor',
        'law_firm',
        'remuneration_indexer',
        'remuneration_rate',
        'remuneration',
        'prepayment_possibility',
        'registered_with_cvm',
        'form_type',
        'segment',
        'target_audience',
        'issued_volume',
        'corporate_purpose',
        'subscription_and_integralization_terms',
        'amortization_payment_schedule',
        'remuneration_payment_schedule',
        'use_of_proceeds',
        'repactuation',
        'optional_early_redemption',
        'early_amortization',
        'remuneration_calculation',
        'guarantee_fund',
        'expense_fund',
        'reserve_fund',
        'works_fund',
        'fiduciary_assignment',
        'vehicle_fiduciary_alienation',
        'quota_fiduciary_alienation',
        'surety',
        'real_estate_guarantee',
        'property_fiduciary_alienation',
        'aval',
        'property_description',
        'segregated_estate',
        'guarantees_description',
        'covenants',
        'is_public',
        'description',
        'current_pu',
        'integralization_status',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'maturity_date' => 'date',
            'issued_price' => 'decimal:2',
            'issued_volume' => 'decimal:2',
            'remuneration_rate' => 'decimal:2',
            'prepayment_possibility' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function getFormattedRemunerationAttribute(): ?string
    {
        return self::formatRemuneration($this->remuneration_indexer, $this->remuneration_rate) ?? $this->remuneration;
    }

    public static function formatRemuneration(?string $indexer, string|float|int|null $rate): ?string
    {
        $normalizedIndexer = self::normalizeRemunerationIndexer($indexer);
        $hasRate = filled($rate);

        if (! filled($normalizedIndexer) && ! $hasRate) {
            return null;
        }

        if (filled($normalizedIndexer) && $hasRate) {
            return sprintf('%s + %s%% a.a.', $normalizedIndexer, number_format((float) $rate, 2, ',', '.'));
        }

        if (filled($normalizedIndexer)) {
            return $normalizedIndexer;
        }

        return sprintf('%s%% a.a.', number_format((float) $rate, 2, ',', '.'));
    }

    private static function normalizeRemunerationIndexer(?string $indexer): ?string
    {
        if (! filled($indexer)) {
            return null;
        }

        $trimmedIndexer = trim($indexer);

        foreach (array_keys(self::REMUNERATION_INDEXER_OPTIONS) as $option) {
            if (mb_strtolower($option) === mb_strtolower($trimmedIndexer)) {
                return $option;
            }
        }

        return $trimmedIndexer;
    }

    private static function generateBsiCode(self $emission): string
    {
        $referenceDate = $emission->created_at ?? now();

        return sprintf('BSI-%s-%04d', $referenceDate->format('Y'), $emission->getKey());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logFillable()
            ->dontSubmitEmptyLogs();
    }

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_emission')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'emission_document')->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function calculateIntegralizedQuantity(): int
    {
        return (int) round((float) $this->integralizationHistories()->sum('quantity'));
    }

    public function ensureIntegralizationQuantityWithinIssuedLimit(
        float|int|string|null $quantity,
        ?IntegralizationHistory $ignoringIntegralizationHistory = null,
    ): void {
        if (! filled($this->issued_quantity) && $this->issued_quantity !== 0 && $this->issued_quantity !== '0') {
            throw ValidationException::withMessages([
                'quantity' => 'Defina a Quantidade Emitida da emissão antes de registrar integralizações.',
            ]);
        }

        $requestedQuantity = (float) ($quantity ?? 0);
        $alreadyIntegralizedQuantity = $this->sumIntegralizationHistoriesQuantity($ignoringIntegralizationHistory);
        $availableQuantity = max(0, (float) $this->issued_quantity - $alreadyIntegralizedQuantity);

        if ($requestedQuantity <= ($availableQuantity + 0.0001)) {
            return;
        }

        throw ValidationException::withMessages([
            'quantity' => sprintf(
                'A quantidade informada excede a Quantidade Emitida. Restam %s disponíveis para integralização.',
                self::formatQuantityForValidationMessage($availableQuantity),
            ),
        ]);
    }

    public function syncIntegralizedQuantityFromHistories(): void
    {
        $this->forceFill([
            'integralized_quantity' => $this->calculateIntegralizedQuantity(),
        ])->saveQuietly();
    }

    public function constructions(): HasMany
    {
        return $this->hasMany(Construction::class);
    }

    public function salesBoards(): HasMany
    {
        return $this->hasMany(SalesBoard::class);
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(Guarantee::class);
    }

    public function guaranteeSnapshots(): HasMany
    {
        return $this->hasMany(GuaranteeSnapshot::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function puHistories(): HasMany
    {
        return $this->hasMany(PuHistory::class);
    }

    public function puParameter(): HasOne
    {
        return $this->hasOne(EmissionPuParameter::class);
    }

    public function puEvents(): HasMany
    {
        return $this->hasMany(EmissionPuEvent::class);
    }

    public function puDailyCurves(): HasMany
    {
        return $this->hasMany(EmissionPuDailyCurve::class);
    }

    public function integralizationHistories(): HasMany
    {
        return $this->hasMany(IntegralizationHistory::class);
    }

    public function extractedObligations(): HasMany
    {
        return $this->hasMany(ExtractedObligation::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(Obligation::class);
    }

    public function obligationEvidences(): HasManyThrough
    {
        return $this->hasManyThrough(ObligationEvidence::class, Obligation::class);
    }

    public function obligationGenerationRuns(): HasMany
    {
        return $this->hasMany(ObligationGenerationRun::class);
    }

    public function latestObligationGenerationRun(): HasOne
    {
        return $this->hasOne(ObligationGenerationRun::class)->latestOfMany();
    }

    public function requiresMonthlyGuaranteeSnapshotUpdate(?CarbonInterface $referenceDate = null): bool
    {
        if (! self::hasGuaranteeSnapshotsTable()) {
            return false;
        }

        $referenceDate ??= now();

        $latestSnapshot = $this->guaranteeSnapshots()
            ->latest('reference_month')
            ->first();

        if (! $latestSnapshot instanceof GuaranteeSnapshot) {
            return true;
        }

        if ($latestSnapshot->reference_month === null) {
            return true;
        }

        return $latestSnapshot->reference_month->lt($referenceDate->copy()->startOfMonth());
    }

    public static function hasGuaranteeSnapshotsTable(): bool
    {
        return Schema::hasTable('guarantee_snapshots');
    }

    private function sumIntegralizationHistoriesQuantity(?IntegralizationHistory $ignoringIntegralizationHistory = null): float
    {
        return (float) $this->integralizationHistories()
            ->when(
                $ignoringIntegralizationHistory?->exists,
                fn ($query) => $query->whereKeyNot($ignoringIntegralizationHistory->getKey()),
            )
            ->sum('quantity');
    }

    private static function formatQuantityForValidationMessage(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, ',', '.'), '0'), ',');
    }
}
