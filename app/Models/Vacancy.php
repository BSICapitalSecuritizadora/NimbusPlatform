<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vacancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'department', 'location', 
        'type', 'description', 'requirements', 
        'benefits', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($vacancy) {
            if (!$vacancy->slug) {
                $vacancy->slug = Str::slug($vacancy->title) . '-' . Str::random(5);
            }
        });
    }

    public function applications()
    {
        return $this->hasMany(JobApplication::class);
    }
}
