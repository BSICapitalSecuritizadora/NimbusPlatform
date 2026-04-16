<?php

declare(strict_types=1);

namespace App\DTOs;

abstract readonly class BaseDTO
{
    protected static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
