<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectUnitType extends Model
{
    use HasFactory;

    protected $fillable = [
        'characteristic_id', 'order', 'total_units', 'bedrooms', 'parking_spaces',
        'useful_area', 'average_price', 'price_per_m2'
    ];

    public function characteristic()
    {
        return $this->belongsTo(ProjectCharacteristic::class, 'characteristic_id');
    }
}
