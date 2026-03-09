<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Emission extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'type',
        'if_code',
        'isin_code',
        'status',
        'issuer',
        'fiduciary_regime',
        'issue_date',
        'maturity_date',
        'monetary_update_period',
        'series',
        'emission_number',
        'issued_quantity',
        'monetary_update_months',
        'interest_payment_frequency',
        'offer_type',
        'concentration',
        'issued_price',
        'amortization_frequency',
        'integralized_quantity',
        'trustee_agent',
        'debtor',
        'remuneration',
        'prepayment_possibility',
        'segment',
        'issued_volume',
        'is_public',
        'description',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'maturity_date' => 'date',
        'issued_price' => 'decimal:2',
        'issued_volume' => 'decimal:2',
        'prepayment_possibility' => 'boolean',
        'is_public' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logFillable()
            ->dontSubmitEmptyLogs();
    }

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_emission')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'emission_document')->withTimestamps();
    }
}
