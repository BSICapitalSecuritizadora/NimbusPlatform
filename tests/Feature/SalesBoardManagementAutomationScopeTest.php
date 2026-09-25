<?php

use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * O ciclo só publica competência que a automação cobre.
 *
 * O rollout decide quem escreve os quadros de uma Emissão. Um quadro publicado é
 * imutável em qualquer modo, e publicá-lo numa competência que a automação não
 * cobre -- Emissão que voltou ao legado, competência anterior ao início --
 * congelaria a competência sem homologação e sem forma de correção. A saída
 * para um ciclo nessa situação é "Cancelar competência".
 */

/**
 * @param  array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}  $gate
 * @return array{label: string, passed: bool, detail: string|null}
 */
function automationScopeCheck(array $gate): array
{
    return collect($gate['checks'])->firstWhere('label', 'Competência coberta pela automação');
}

it('refuses to publish once the emission returned to legacy', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    app(SalesBoardRolloutActivationService::class)->returnToLegacy(
        Emission::query()->findOrFail($scenario['cycle']->emission_id),
        User::factory()->create(),
        'A Emissão volta ao registro manual até a revisão do contrato.',
    );

    $gate = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());

    expect($gate['ready'])->toBeFalse()
        ->and(automationScopeCheck($gate)['passed'])->toBeFalse()
        ->and(automationScopeCheck($gate)['detail'])->toContain('Cancelar competência');

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'use "Cancelar competência" para encerrá-la');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('refuses to publish a competence before the automation start', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    // O ciclo é de 07/2026; a automação desta Emissão só começa em 08/2026.
    Emission::query()->findOrFail($scenario['cycle']->emission_id)
        ->forceFill(['sales_board_automation_start_reference_month' => '2026-08-01'])
        ->save();

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'não cobre 07/2026 pela automação');

    expect(SalesBoardPublication::query()->count())->toBe(0);
});

it('publishes when the automation covers the competence', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $emission = Emission::query()->findOrFail($scenario['cycle']->emission_id);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and(automationScopeCheck(app(SalesBoardManagementApprovalService::class)->gate($review->fresh()))['passed'])->toBeTrue()
        ->and(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
});

it('tells the manager in the workspace why the publication is blocked', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Emission::query()->findOrFail($scenario['cycle']->emission_id)
        ->forceFill([
            'sales_board_source' => SalesBoardSource::Legacy,
            'sales_board_automation_start_reference_month' => null,
        ])
        ->save();

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Publicação bloqueada')
        ->assertSee('Competência coberta pela automação')
        ->assertSee('Cancelar competência');
});
