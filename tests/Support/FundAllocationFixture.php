<?php

namespace Tests\Support;

use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication as FundClassification;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\User;
use App\Services\Funds\FundAllocationService;

/**
 * Shared setup for the Phase 2A allocation suites.
 */
class FundAllocationFixture
{
    public static function service(): FundAllocationService
    {
        return app(FundAllocationService::class);
    }

    public static function userWithRole(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public static function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * Maker holds the editor role: full workflow except approval.
     */
    public static function maker(): User
    {
        return self::userWithRole('editor');
    }

    /**
     * Checker holds approval authority only (plus viewing).
     */
    public static function checker(): User
    {
        return self::userWithPermissions(['fund-allocations.view', 'fund-allocations.approve']);
    }

    /**
     * @return array{Emission, FundType, FundName, FundClassification}
     */
    public static function classification(?string $emissionName = null): array
    {
        $emission = Emission::factory()->create($emissionName !== null ? ['name' => $emissionName] : []);
        $fundType = FundType::factory()->create();
        $fundName = FundName::factory()->create(['fund_type_id' => $fundType->id]);
        $classification = FundClassification::factory()->create();

        return [$emission, $fundType, $fundName, $classification];
    }

    public static function application(): BankApplication
    {
        return BankApplication::factory()->create();
    }

    /**
     * A fund linked to the given application (and its account, consistently).
     */
    public static function linkedFund(
        BankApplication $application,
        ?Emission $emission = null,
        ?FundType $fundType = null,
        ?FundName $fundName = null,
        ?FundClassification $classification = null,
        array $overrides = [],
    ): Fund {
        $emission ??= Emission::factory()->create();
        $fundType ??= FundType::factory()->create();
        $fundName ??= FundName::factory()->create(['fund_type_id' => $fundType->id]);
        $classification ??= FundClassification::factory()->create();

        /** @var BankAccount $account */
        $account = $application->bankAccount()->firstOrFail();

        return Fund::factory()->create(array_merge([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $classification->id,
            'bank_account_id' => $account->getKey(),
            'bank_application_id' => $application->getKey(),
        ], $overrides));
    }
}
