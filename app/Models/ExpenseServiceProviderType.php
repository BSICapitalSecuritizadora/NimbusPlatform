<?php

namespace App\Models;

use Database\Factories\ExpenseServiceProviderTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseServiceProviderType extends Model
{
    /** @use HasFactory<ExpenseServiceProviderTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function serviceProviders(): HasMany
    {
        return $this->hasMany(ExpenseServiceProvider::class);
    }
}
