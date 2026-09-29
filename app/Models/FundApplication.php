<?php

namespace App\Models;

use Database\Factories\FundApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundApplication extends Model
{
    /** @use HasFactory<FundApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }
}
