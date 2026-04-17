<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundType extends Model
{
    /** @use HasFactory<\Database\Factories\FundTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function fundNames(): HasMany
    {
        return $this->hasMany(FundName::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }
}
