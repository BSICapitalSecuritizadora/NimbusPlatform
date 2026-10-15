<?php

namespace App\Rules;

use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the reporting competence input ("MM/AAAA").
 */
class AllocationCompetence implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (FundAllocationDraftLines::parseCompetenceInput($value) === null) {
            $fail('Informe a competência no formato MM/AAAA.');
        }
    }
}
