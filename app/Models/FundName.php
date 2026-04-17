<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundName extends Model
{
    /** @use HasFactory<\Database\Factories\FundNameFactory> */
    use HasFactory;

    protected $fillable = [
        'fund_type_id',
        'name',
    ];

    public function fundType(): BelongsTo
    {
        return $this->belongsTo(FundType::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }
}
