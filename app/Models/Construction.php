<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected function developmentCnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => Str::digitsOnly((string) $value),
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

    public function getFormattedDevelopmentCnpjAttribute(): string
    {
        return ExpenseServiceProvider::formatCnpj($this->development_cnpj);
    }

    public function getFormattedEstimatedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->estimated_value);
    }
}
