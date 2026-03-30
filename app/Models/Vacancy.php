<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Vacancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'department', 'location',
        'type', 'description', 'requirements',
        'benefits', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $vacancy): void {
            if (! $vacancy->slug) {
                $vacancy->slug = Str::slug($vacancy->title).'-'.Str::random(5);
            }
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
