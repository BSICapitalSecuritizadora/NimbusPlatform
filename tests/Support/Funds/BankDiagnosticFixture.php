<?php

namespace Tests\Support\Funds;

use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Support\BankAccountNormalizer;

/**
 * Synthetic fund scenarios for the Phase 1B.1 bank-account diagnostic.
 *
 * Every helper creates its own records: funds never share a
 * FundApplication classification unless the caller passes one explicitly,
 * which keeps the (emission, application, account) unique satisfied when
 * several funds intentionally carry the same account number.
 */
class BankDiagnosticFixture
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function fund(array $overrides = []): Fund
    {
        return Fund::factory()->create([
            'fund_application_id' => FundApplication::factory(),
            ...$overrides,
        ]);
    }

    /**
     * Create the BankAccount row matching a fund's legacy identifiers.
     *
     * Parses with the same Phase 1A normalizer the diagnostic uses, so the
     * "existing account matches" scenario can never drift from the
     * production grouping rule.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function matchingAccount(Fund $fund, array $overrides = []): BankAccount
    {
        $agency = BankAccountNormalizer::parseAgency((string) $fund->agency);
        $account = BankAccountNormalizer::parseAccount((string) $fund->account);

        return BankAccount::factory()->create([
            'bank_id' => $fund->bank_id,
            'agency_number' => $agency['number'],
            'agency_digit' => $agency['digit'],
            'account_number' => $account['number'],
            'account_digit' => $account['digit'],
            ...$overrides,
        ]);
    }
}
