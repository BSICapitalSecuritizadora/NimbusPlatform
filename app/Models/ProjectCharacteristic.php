<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectCharacteristic extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'blocks', 'floors', 'typical_floors', 'units_per_floor', 'total_units',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'integer',
            'floors' => 'integer',
            'typical_floors' => 'integer',
            'units_per_floor' => 'integer',
            'total_units' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProposalProject::class, 'project_id');
    }

    public function unitTypes(): HasMany
    {
        return $this->hasMany(ProjectUnitType::class, 'characteristic_id');
    }
}
