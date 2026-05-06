<?php

namespace App\Models;

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
        if ($html === null) {
            return null;
        }

        $allowedTags = '<p><br><strong><em><b><i><ul><ol><li><h2><h3><h4><span>';
        $html = strip_tags($html, $allowedTags);
        // Remove event handler attributes (onclick, onmouseover, etc.)
        $html = (string) preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);
        // Remove style attributes that can carry CSS expressions
        $html = (string) preg_replace('/\s+style\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);

        return $html;
    }
}
