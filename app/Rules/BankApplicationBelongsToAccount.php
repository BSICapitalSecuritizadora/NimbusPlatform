<?php

namespace App\Rules;

use App\Models\BankApplication;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects a bank application that does not belong to the selected account.
 *
 * The fund form filters the application options by account, but filtering
 * alone never proves a submitted pair; this rule re-checks the pair
 * server-side so a tampered or stale submission fails validation instead of
 * reaching the model guard as an exception.
 */
class BankApplicationBelongsToAccount implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        // Filament validates the form state nested under "data"; direct rule
        // usage passes a flat array. Both shapes resolve the sibling field.
        $accountId = $this->data['bank_account_id']
            ?? $this->data['data']['bank_account_id']
            ?? null;

        if (blank($accountId)) {
            $fail('Selecione a conta bancária antes da aplicação.');

            return;
        }

        $application = BankApplication::query()->find($value);

        if (! $application instanceof BankApplication) {
            $fail('A aplicação bancária informada não existe.');

            return;
        }

        if ((int) $application->bank_account_id !== (int) $accountId) {
            $fail('A aplicação bancária pertence a outra conta.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
