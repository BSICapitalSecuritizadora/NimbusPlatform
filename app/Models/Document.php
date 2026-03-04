<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    public function investors(): BelongsToMany
{
    return $this->belongsToMany(Investor::class, 'investor_document')->withTimestamps();
}

public function emissions(): BelongsToMany
{
    return $this->belongsToMany(Emission::class, 'emission_document')->withTimestamps();
}
}
