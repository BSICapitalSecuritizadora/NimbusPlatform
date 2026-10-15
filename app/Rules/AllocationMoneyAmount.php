<?php

namespace App\Rules;

use App\Support\Funds\FundAllocationMoney;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Validates an exact monetary amount for the allocation workflow.
 *
 * Accepts the normalized decimal strings produced by the allocation forms
 * (Brazilian display is converted before validation) and rejects anything
 * the domain money value object cannot represent, with user-safe messages.
 */
class AllocationMoneyAmount implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        try {
            $cents = FundAllocationMoney::toCents((string) $value);
        } catch (InvalidArgumentException) {
            $fail('Informe um valor monetário válido (ex.: 1.234,56).');

            return;
        }

        if ($cents < 0) {
            $fail('O valor não pode ser negativo.');
        }
    }
}
