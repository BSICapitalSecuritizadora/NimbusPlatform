<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmissionMonthlyReportNote extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionMonthlyReportNoteFactory> */
    use HasFactory, LogsActivity;

    public const CATEGORY_OPTIONS = [
        'Geral' => 'Geral',
        'Obra' => 'Obra',
        'Financeiro' => 'Financeiro',
        'Vendas' => 'Vendas',
        'Inadimplência' => 'Inadimplência',
        'Jurídico' => 'Jurídico',
    ];

    protected $fillable = [
        'emission_id',
        'reference_month',
        'category',
        'title',
        'content',
        'is_visible_on_report',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $note): void {
            $note->reference_month = self::normalizeReferenceMonth($note->reference_month);
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'is_visible_on_report' => 'boolean',
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getReferenceMonthLabelAttribute(): string
    {
        return self::formatReferenceMonthForDisplay($this->reference_month);
    }

    public static function normalizeReferenceMonth(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
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
}
