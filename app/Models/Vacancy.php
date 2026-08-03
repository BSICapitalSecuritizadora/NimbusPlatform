<?php

namespace App\Models;

use App\Services\Security\RichTextSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    protected function requirements(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    protected function benefits(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    private static function sanitizeHtml(?string $html): ?string
    {
        return RichTextSanitizer::sanitize($html);
    }
}
