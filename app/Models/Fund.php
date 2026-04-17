<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fund extends Model
{
    /** @use HasFactory<\Database\Factories\FundFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'fund_type_id',
        'fund_name_id',
        'fund_application_id',
        'bank_id',
        'agency',
        'account',
    ];

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function fundType(): BelongsTo
    {
        return $this->belongsTo(FundType::class);
    }

    public function fundName(): BelongsTo
    {
        return $this->belongsTo(FundName::class);
    }

    public function fundApplication(): BelongsTo
    {
        return $this->belongsTo(FundApplication::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
