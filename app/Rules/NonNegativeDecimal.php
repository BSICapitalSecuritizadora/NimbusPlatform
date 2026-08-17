<?php

namespace App\Rules;

use App\Concerns\MoneyFormatter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class NonNegativeDecimal implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value) || is_float($value)) {
            if ($value >= 0) {
                return;
            }

            $fail('O campo :attribute deve ser um valor não negativo.');

            return;
        }

        $value = trim((string) $value);

        if ($value === '' || preg_match('/^(?:R\$\s*)?(?:\d{1,3}(?:\.\d{3})+|\d+)(?:[,.]\d{1,2})?$/', $value) !== 1) {
            $fail('O campo :attribute deve ser um valor numérico válido.');

            return;
        }

        if (MoneyFormatter::normalizeDecimalValue($value) < 0) {
            $fail('O campo :attribute deve ser um valor não negativo.');
        }
    }
}
