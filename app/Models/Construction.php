<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Construction extends Model
{
    /** @use HasFactory<\Database\Factories\ConstructionFactory> */
    use HasFactory;

    public const MEASUREMENT_COMPANY_TYPE_NAME = 'Engenharia';

    public const STATE_OPTIONS = [
        'AC' => 'AC',
        'AL' => 'AL',
        'AP' => 'AP',
        'AM' => 'AM',
        'BA' => 'BA',
        'CE' => 'CE',
        'DF' => 'DF',
        'ES' => 'ES',
        'GO' => 'GO',
        'MA' => 'MA',
        'MT' => 'MT',
        'MS' => 'MS',
        'MG' => 'MG',
        'PA' => 'PA',
        'PB' => 'PB',
        'PR' => 'PR',
        'PE' => 'PE',
        'PI' => 'PI',
        'RJ' => 'RJ',
        'RN' => 'RN',
        'RS' => 'RS',
        'RO' => 'RO',
        'RR' => 'RR',
        'SC' => 'SC',
        'SP' => 'SP',
        'SE' => 'SE',
        'TO' => 'TO',
    ];

    protected $fillable = [
        'emission_id',
        'development_name',
        'development_cnpj',
        'city',
        'state',
        'construction_start_date',
        'construction_end_date',
        'estimated_value',
        'measurement_company_id',
    ];

    protected function casts(): array
    {
        return [
            'construction_start_date' => 'date',
            'construction_end_date' => 'date',
            'estimated_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $construction): void {
            $construction->construction_start_date = self::normalizeMonthDate($construction->construction_start_date);
            $construction->construction_end_date = self::normalizeMonthDate($construction->construction_end_date);
        });
    }

    protected function developmentCnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : Str::digitsOnly((string) $value),
        );
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function measurementCompany(): BelongsTo
    {
        return $this->belongsTo(ExpenseServiceProvider::class, 'measurement_company_id');
    }

    public function salesBoards(): HasMany
    {
        return $this->hasMany(SalesBoard::class);
    }

    public function getFormattedDevelopmentCnpjAttribute(): string
    {
        return ExpenseServiceProvider::formatCnpj($this->development_cnpj);
    }

    public function getFormattedEstimatedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->estimated_value);
    }

    public static function normalizeMonthDate(mixed $value): ?string
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

    public static function formatMonthForDisplay(mixed $value): string
    {
        $monthDate = self::normalizeMonthDate($value);

        if ($monthDate === null) {
            return '';
        }

        return Carbon::parse($monthDate)->format('m/Y');
    }
}
