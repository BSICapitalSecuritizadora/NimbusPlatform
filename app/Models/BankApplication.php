<?php

namespace App\Models;

use App\Support\BankAccountNormalizer;
use Database\Factories\BankApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Whether funds from more than one emission point at this application.
     *
     * Read-only helper for the future shared-application indicator; it runs
     * one aggregate query, so callers must not invoke it inside loops.
     */
    public function isShared(): bool
    {
        return $this->funds()->distinct()->count('emission_id') > 1;
    }
}
