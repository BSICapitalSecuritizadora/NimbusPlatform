<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Cpf implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            $fail('Informe um CPF válido.');

            return;
        }

        foreach ([9, 10] as $digitPosition) {
            $sum = 0;
            $weight = $digitPosition + 1;

            for ($index = 0; $index < $digitPosition; $index++) {
                $sum += (int) $cpf[$index] * $weight;
                $weight--;
            }

            $remainder = ($sum * 10) % 11;
            $expectedDigit = $remainder === 10 ? 0 : $remainder;

            if ((int) $cpf[$digitPosition] !== $expectedDigit) {
                $fail('Informe um CPF válido.');

                return;
            }
        }
    }
}
