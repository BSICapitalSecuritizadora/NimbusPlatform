<?php

namespace App\Services\Recruitment;

use App\Models\Vacancy;
use Illuminate\Support\Str;

class VacancySlugService
{
    public static function generate(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'vaga';
        }

        $slug = $base;
        $counter = 1;

        while (self::exists($slug, $ignoreId)) {
            $counter++;
            $slug = $base.'-'.$counter;
        }

        return $slug;
    }

    public static function generateUniqueForTitle(string $title, ?Vacancy $vacancy = null): string
    {
        return self::generate($title, $vacancy?->id);
    }

    private static function exists(string $slug, ?int $ignoreId): bool
    {
        $query = Vacancy::query()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
