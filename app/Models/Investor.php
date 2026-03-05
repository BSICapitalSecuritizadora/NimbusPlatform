<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Investor extends Authenticatable
{
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

    public function emissions(): BelongsToMany
    {
        return $this->belongsToMany(Emission::class, 'investor_emission')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'investor_document')->withTimestamps();
    }
}