<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function forWhatsApp(?string $phone, string $defaultCountryCode = '55'): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = $defaultCountryCode.$digits;
        }

        if (strlen($digits) < 12 || strlen($digits) > 13) {
            return null;
        }

        return $digits;
    }
}
