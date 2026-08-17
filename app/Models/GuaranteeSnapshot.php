<?php

namespace App\Models;

use App\Enums\GuaranteeCoverageStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\GuaranteeSnapshotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GuaranteeSnapshot extends Model
{
    /** @use HasFactory<GuaranteeSnapshotFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'emission_id',
        'reference_month',
        'quota_value',
        'outstanding_balance',
        'total_gross_value',
        'total_eligible_value',
        'total_required_value',
        'coverage_ratio',
        'required_ratio',
        'surplus_deficit',
        'coverage_status',
        'active_guarantees_count',
        'pending_sources',
        'metadata',
        'computed_at',
        'closed_at',
        'closed_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // Formato explícito: a competência compõe o índice único com a
            // emissão, e gravá-la como datetime faria o `updateOrCreate` criar
            // uma segunda linha para o mesmo mês.
            'reference_month' => 'date:Y-m-d',
            'quota_value' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'total_gross_value' => 'decimal:2',
            'total_eligible_value' => 'decimal:2',
            'total_required_value' => 'decimal:2',
            'coverage_ratio' => 'decimal:6',
            'required_ratio' => 'decimal:6',
            'surplus_deficit' => 'decimal:2',
            'coverage_status' => GuaranteeCoverageStatus::class,
            'active_guarantees_count' => 'integer',
            'pending_sources' => 'array',
            'metadata' => 'array',
            'computed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * A competência foi fechada? Um mês fechado só volta a ser editável por
     * reabertura explícita, que é permissão própria e fica auditada.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(GuaranteeMonthlyPosition::class, 'emission_id', 'emission_id')
            ->whereColumn('guarantee_monthly_positions.reference_month', 'guarantee_snapshots.reference_month');
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

    public static function normalizeReferenceMonth(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value))
                ->startOfMonth()
                ->toDateString();
        }

        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            $month = (int) $matches[1];
            $year = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        try {
            return Carbon::parse($value)->startOfMonth()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatReferenceMonthForDisplay(mixed $value): string
    {
        $referenceMonth = self::normalizeReferenceMonth($value);

        if ($referenceMonth === null) {
            return '';
        }

        return Carbon::parse($referenceMonth)->format('m/Y');
    }

    public function getFormattedReferenceMonthAttribute(): string
    {
        return self::formatReferenceMonthForDisplay($this->reference_month);
    }
}
