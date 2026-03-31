<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectUnitType extends Model
{
    use HasFactory;

    protected $fillable = [
        'characteristic_id', 'order', 'total_units', 'bedrooms', 'parking_spaces',
        'useful_area', 'average_price', 'price_per_m2',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'total_units' => 'integer',
            'useful_area' => 'decimal:2',
            'average_price' => 'decimal:2',
            'price_per_m2' => 'decimal:2',
        ];
    }

    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(ProjectCharacteristic::class, 'characteristic_id');
    }

    public function getFormattedAveragePriceAttribute(): string
    {
        return ProposalProject::formatCurrencyForDisplay($this->average_price);
    }

    public function getFormattedPricePerM2Attribute(): string
    {
        return ProposalProject::formatCurrencyForDisplay($this->price_per_m2);
    }

    public function getFormattedUsefulAreaAttribute(): string
    {
        return number_format((float) $this->useful_area, 2, ',', '.');
    }
}
