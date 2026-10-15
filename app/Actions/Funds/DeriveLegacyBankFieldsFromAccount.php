<?php

namespace App\Actions\Funds;

use App\Models\BankAccount;

/**
 * Derives the legacy fund bank columns from the selected global account.
 *
 * Transitional compatibility: existing financial readers (balances,
 * guarantees, Conta Azul sync, reports) still consume funds.bank_id,
 * funds.agency and funds.account, so every fund linked to a BankAccount
 * carries the same identity in both shapes. The form also pre-fills these
 * values client-side, but programmatic submissions bypass that hook — this
 * action runs in the create/edit pages after validation, so the legacy
 * columns can never stay blank or diverge for a linked fund.
 *
 * Balance fields are never touched here.
 */
class DeriveLegacyBankFieldsFromAccount
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data): array
    {
        $accountId = $data['bank_account_id'] ?? null;

        if (blank($accountId)) {
            return $data;
        }

        $account = BankAccount::query()->find($accountId);

        if (! $account instanceof BankAccount) {
            return $data;
        }

        $data['bank_id'] = $account->bank_id;
        $data['agency'] = $account->formattedAgency();
        $data['account'] = $account->formattedAccount();

        return $data;
    }
}
