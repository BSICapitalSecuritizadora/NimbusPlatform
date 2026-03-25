<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectCharacteristic extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'blocks', 'floors', 'typical_floors', 'units_per_floor', 'total_units'
    ];

    public function project()
    {
        return $this->belongsTo(ProposalProject::class, 'project_id');
    }

    public function unitTypes()
    {
        return $this->hasMany(ProjectUnitType::class, 'characteristic_id');
    }
}
