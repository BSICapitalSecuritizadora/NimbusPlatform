<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExpenseServiceProvider extends Model
{
    /** @use HasFactory<\Database\Factories\ExpenseServiceProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'cnpj',
        'name',
        'expense_service_provider_type_id',
    ];

    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => Str::digitsOnly((string) $value),
        );
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function measuredConstructions(): HasMany
    {
        return $this->hasMany(Construction::class, 'measurement_company_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseServiceProviderType::class, 'expense_service_provider_type_id');
    }

    public function getFormattedCnpjAttribute(): string
    {
        return self::formatCnpj($this->cnpj);
    }

    public static function formatCnpj(?string $value): string
    {
        $digits = substr(Str::digitsOnly((string) $value), 0, 14);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) <= 2) {
            return $digits;
        }

        if (strlen($digits) <= 5) {
            return substr($digits, 0, 2).'.'.substr($digits, 2);
        }

        if (strlen($digits) <= 8) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5);
        }

        if (strlen($digits) <= 12) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8);
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12);
    }
}
