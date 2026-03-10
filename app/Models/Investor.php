<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Investor extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\InvestorFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'mobile',
        'cpf',
        'rg',
        'is_active',
        'last_login_at',
        'notes',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logFillable()
            ->dontSubmitEmptyLogs();
    }

    public function emissions(): BelongsToMany
    {
        return $this->belongsToMany(Emission::class, 'investor_emission')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'investor_document')->withTimestamps();
    }
}
