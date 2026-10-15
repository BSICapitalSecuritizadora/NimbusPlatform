<?php

namespace App\Models;

use App\Services\Funds\FundAllocationLinkageGuard;
use Carbon\CarbonInterface;
use Database\Factories\FundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Fund extends Model
{
    /** @use HasFactory<FundFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'emission_id',
        'fund_type_id',
        'fund_name_id',
        'fund_application_id',
        'trade_name',
        'bank_id',
        'agency',
        'account',
        'balance',
        'minimum_balance',
        'balance_updated_at',
        'minimum_balance_alert_sent_at',
        'minimum_balance_alert_balance',
        'minimum_balance_alert_minimum_balance',
        'minimum_balance_alert_recipients_hash',
        'conta_azul_account_id',
        'bank_account_id',
        'bank_application_id',
    ];

    protected static function booted(): void
    {
        // Phase 2B linkage guard: a Fund referenced by an open allocation
        // revision cannot change its emission, account or application until
        // the open revision is concluded or discarded. Runs before the
        // cross-reference check so the allocation message wins when both
        // apply; it returns before any query unless linkage is dirty, so
        // unrelated writes (including every legacy write) are untouched.
        static::saving(function (Fund $fund): void {
            app(FundAllocationLinkageGuard::class)->assertLinkageChangeAllowed($fund);
        });

        // Phase 1A cross-reference guard: a fund may only pair an account
        // with an application that belongs to that same account. Rows with a
        // NULL application (every legacy row, plus direct account links)
        // return before any query, so legacy writes are untouched. The
        // funds(bank_account_id, bank_application_id) composite foreign key
        // enforces the same rule at the database level.
        static::saving(function (Fund $fund): void {
            if ($fund->bank_application_id === null) {
                return;
            }

            $application = $fund->relationLoaded('bankApplication')
                ? $fund->getRelation('bankApplication')
                : null;

            if (! $application instanceof BankApplication || (int) $application->getKey() !== (int) $fund->bank_application_id) {
                $application = BankApplication::query()->find($fund->bank_application_id);
            }

            if (! $application instanceof BankApplication) {
                throw new InvalidArgumentException('A aplicação bancária informada não existe.');
            }

            if (($fund->bank_account_id !== null) && ((int) $fund->bank_account_id !== (int) $application->bank_account_id)) {
                throw new InvalidArgumentException('A aplicação bancária pertence a outra conta.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'minimum_balance' => 'decimal:2',
            'balance_updated_at' => 'datetime',
            'minimum_balance_alert_sent_at' => 'datetime',
            'minimum_balance_alert_balance' => 'decimal:2',
            'minimum_balance_alert_minimum_balance' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['emission_id', 'fund_type_id', 'fund_name_id', 'fund_application_id', 'trade_name', 'bank_id', 'agency', 'account', 'balance', 'minimum_balance'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function fundType(): BelongsTo
    {
        return $this->belongsTo(FundType::class);
    }

    public function fundName(): BelongsTo
    {
        return $this->belongsTo(FundName::class);
    }

    public function fundApplication(): BelongsTo
    {
        return $this->belongsTo(FundApplication::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankApplication(): BelongsTo
    {
        return $this->belongsTo(BankApplication::class);
    }

    public function balanceHistories(): HasMany
    {
        return $this->hasMany(FundBalanceHistory::class);
    }

    public function isBalanceBelowMinimum(): bool
    {
        if (($this->balance === null) || ($this->minimum_balance === null)) {
            return false;
        }

        return ((int) round(((float) $this->balance) * 100)) < ((int) round(((float) $this->minimum_balance) * 100));
    }

    public function requiresMonthlyBalanceUpdate(?CarbonInterface $referenceDate = null): bool
    {
        $referenceDate ??= now();

        if ($this->balance === null) {
            return true;
        }

        if ($this->balance_updated_at === null) {
            return true;
        }

        return $this->balance_updated_at->lt($referenceDate->copy()->startOfMonth());
    }

    public function snapshotPreviousMonthBalanceIfMissing(?CarbonInterface $referenceDate = null): ?FundBalanceHistory
    {
        $referenceDate ??= now();

        if (($this->balance === null) || (! $this->exists) || (! $this->requiresMonthlyBalanceUpdate($referenceDate))) {
            return null;
        }

        $snapshotDate = $referenceDate->copy()->startOfMonth()->subDay()->toDateString();

        $history = $this->balanceHistories()
            ->whereDate('date', $snapshotDate)
            ->first();

        if ($history instanceof FundBalanceHistory) {
            return null;
        }

        $history = $this->balanceHistories()->create([
            'date' => $snapshotDate,
            'balance' => $this->balance,
        ]);

        return $history;
    }
}
