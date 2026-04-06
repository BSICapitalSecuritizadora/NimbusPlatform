<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectUnitType extends Model
{
    use HasFactory;

    protected $fillable = [
        'characteristic_id', 'order', 'total_units', 'bedrooms', 'parking_spaces',
        'usable_area', 'average_price', 'price_per_square_meter',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'total_units' => 'integer',
            'usable_area' => 'decimal:2',
            'average_price' => 'decimal:2',
            'price_per_square_meter' => 'decimal:2',
        ];
    }

    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(ProjectCharacteristic::class, 'characteristic_id');
    }

    public function getFormattedAveragePriceAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->average_price);
    }

    public function getFormattedPricePerSquareMeterAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->price_per_square_meter);
    }

    public function getFormattedUsableAreaAttribute(): string
    {
        return number_format((float) $this->usable_area, 2, ',', '.');
    }
}
