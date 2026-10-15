<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Support\BankAccountNormalizer;
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
            'display_agency' => BankAccountNormalizer::formatAgency($agencyNumber, $agencyDigit),
            'display_account' => BankAccountNormalizer::formatAccount($accountNumber, $accountDigit),
            'holder_context' => null,
            'conta_azul_account_id' => null,
            'notes' => null,
        ];
    }
}
