<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Cnpj implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            $fail('Informe um CNPJ válido.');

            return;
        }

        foreach ([12, 13] as $digitPosition) {
            $sum = 0;
            $weight = $digitPosition === 12 ? 5 : 6;

            for ($index = 0; $index < $digitPosition; $index++) {
                $sum += (int) $cnpj[$index] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $remainder = $sum % 11;
            $expectedDigit = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $cnpj[$digitPosition] !== $expectedDigit) {
                $fail('Informe um CNPJ válido.');

                return;
            }
        }
    }
}
