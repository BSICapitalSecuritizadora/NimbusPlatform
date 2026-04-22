<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    /** @use HasFactory<\Database\Factories\FundFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'fund_type_id',
        'fund_name_id',
        'fund_application_id',
        'bank_id',
        'agency',
        'account',
        'balance',
        'balance_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'balance_updated_at' => 'datetime',
        ];
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

    public function balanceHistories(): HasMany
    {
        return $this->hasMany(FundBalanceHistory::class);
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
