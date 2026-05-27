<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
