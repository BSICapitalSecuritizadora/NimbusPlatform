<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agencyNumber = fake()->numerify('####');
        $agencyDigit = (string) fake()->randomDigit();
        $accountNumber = fake()->unique()->numerify('#######');
        $accountDigit = (string) fake()->randomDigit();

        return [
            'bank_id' => Bank::factory(),
            'agency_number' => $agencyNumber,
            'agency_digit' => $agencyDigit,
            'account_number' => $accountNumber,
            'account_digit' => $accountDigit,
            // Display columns stay null so the model derives them from the
            // final canonical parts on saving; computing them here from the
            // random definition values would desync them whenever a caller
            // overrides the numbers.
            'display_agency' => null,
            'display_account' => null,
            'holder_context' => null,
            'conta_azul_account_id' => null,
            'notes' => null,
        ];
    }
}
