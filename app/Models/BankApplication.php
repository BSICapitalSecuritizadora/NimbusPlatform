<?php

namespace App\Models;

use App\Support\BankAccountNormalizer;
use Database\Factories\BankApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A physical investment application inside a bank account.
 *
 * Identified by the bank-reported product code within its account
 * (UNIQUE on bank_account_id + codigo_produto). This is the bank's own
 * application record, unrelated to the FundApplication classification used
 * on the fund form. Phase 1A carries identity only: no balances, no
 * allocation workflow.
 */
class BankApplication extends Model
{
    /** @use HasFactory<BankApplicationFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var array<string, string>
     */
    public const STATUS_OPTIONS = [
        self::STATUS_ACTIVE => 'Ativa',
        self::STATUS_INACTIVE => 'Inativa',
    ];

    protected $fillable = [
        'bank_account_id',
        'codigo_produto',
        'nome_produto',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (BankApplication $application): void {
            if ($application->codigo_produto !== null) {
                $application->codigo_produto = BankAccountNormalizer::normalizeCodigoProduto(
                    (string) $application->codigo_produto
                );
            }
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class, 'bank_application_id');
    }

    public function allocationPeriods(): HasMany
    {
        return $this->hasMany(FundAllocationPeriod::class, 'bank_application_id');
    }

    /**
     * Whether funds from more than one emission point at this application.
     *
     * Read-only helper for the shared-application indicator; it runs one
     * aggregate query, so callers must not invoke it inside loops. Listings
     * must use scopeWithSharedEmissionCount() instead.
     */
    public function isShared(): bool
    {
        return $this->distinctEmissionCount() > 1;
    }

    /**
     * Distinct emissions across every fund linked to this application.
     *
     * Uses COUNT(DISTINCT emission_id): funds sharing only the parent
     * BankAccount (different or NULL applications) never count here.
     */
    public function distinctEmissionCount(): int
    {
        if (array_key_exists('shared_emission_count', $this->attributes)) {
            return (int) $this->attributes['shared_emission_count'];
        }

        return (int) $this->funds()->distinct()->count('emission_id');
    }

    /**
     * Distinct emissions linked through this application, ordered by name.
     *
     * Presentation layers must check emissions.view before rendering names.
     *
     * @return Collection<int, Emission>
     */
    public function sharedEmissions(): Collection
    {
        $emissionIds = $this->funds()
            ->distinct()
            ->orderBy('emission_id')
            ->pluck('emission_id');

        if ($emissionIds->isEmpty()) {
            return collect();
        }

        return Emission::query()
            ->whereIn('id', $emissionIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function statusLabel(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? (string) $this->status;
    }

    /**
     * Human-readable option label: product code plus product name.
     */
    public function optionLabel(): string
    {
        if (filled($this->nome_produto)) {
            return sprintf('%s · %s', (string) $this->codigo_produto, (string) $this->nome_produto);
        }

        return (string) $this->codigo_produto;
    }

    /**
     * Adds a COUNT(DISTINCT emission_id) subquery as shared_emission_count.
     */
    public function scopeWithSharedEmissionCount(Builder $query): Builder
    {
        return $query->addSelect([
            'shared_emission_count' => Fund::query()
                ->selectRaw('count(distinct emission_id)')
                ->whereColumn('bank_application_id', 'bank_applications.id'),
        ]);
    }
}
