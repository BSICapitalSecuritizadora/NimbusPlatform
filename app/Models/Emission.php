<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Emission extends Model
{
    public function investors(): BelongsToMany
{
    return $this->belongsToMany(Investor::class, 'investor_emission')->withTimestamps();
}
}
