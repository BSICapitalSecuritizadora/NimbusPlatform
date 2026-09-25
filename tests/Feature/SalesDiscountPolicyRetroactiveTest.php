<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * O alcance compara colunas `date` (venda, competência), que o SQLite guarda
 * como texto com hora e o MySQL como data de verdade: o arquivo roda também no
 * MySQL.
 */
pest()->group('parity');

/**
 * Uma política com início anterior a hoje passa a decidir a conformidade de
 * vendas que já aconteceram. Isso é legítimo -- corrigir uma política errada
 * exige início no passado --, mas não pode acontecer sem que quem registra veja
 * o que está rejulgando, nem alcançar uma competência que já foi publicada.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    // 12h UTC são 9h em Brasília: o dia de negócio e o dia UTC coincidem.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
});

function retroactiveTestManager(Construction $construction): Testable
{
    return Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ]);
}

/**
 * Obra com vendas em julho (duas), nenhuma em agosto e uma em setembro, mais
 * uma de junho que fica fora de um período iniciado em julho.
 */
function retroactiveTestConstruction(): Construction
{
    $construction = DerivationFixture::construction();

    DerivationFixture::contract(DerivationFixture::unit($construction, '100'), '2026-06-30');
    DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-07-10');
    DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-07-20');
    DerivationFixture::contract(DerivationFixture::unit($construction, '103'), '2026-09-05');

    return $construction;
}

function retroactiveTestRegister(
    Construction $construction,
    string $from,
    string $until,
    ?string $confirmedRetroactiveThrough = null,
    ?int $confirmedSubstitutionId = null,
    string $percent = '15.00',
): SalesDiscountPolicy {
    return app(SalesDiscountPolicyRegistrar::class)->register(
        construction: $construction,
        attributes: [
            'maximum_discount_percent' => $percent,
            'effective_from' => $from,
            'effective_until' => $until,
            'reason' => 'Correção comercial',
        ],
        confirmedSubstitutionId: $confirmedSubstitutionId,
        registeredBy: null,
        confirmedRetroactiveThrough: $confirmedRetroactiveThrough,
    );
}

function approvedCycleFor(Construction $construction, string $month, SalesBoardCycleStatus $status = SalesBoardCycleStatus::Approved): SalesBoardCycle
{
    return SalesBoardCycle::factory()
        ->forConstruction($construction)
        ->referenceMonth($month)
        ->create(['status' => $status]);
}

it('no longer tells the user that the period never retroacts', function () {
    retroactiveTestManager(Construction::factory()->create())
        ->mountAction(TestAction::make('newPolicy')->table())
        ->assertMountedActionModalSee('Período em que esta política poderá ser aplicada às vendas.')
        ->assertMountedActionModalSee('Um início anterior a hoje alcança vendas já feitas')
        ->assertMountedActionModalDontSee('não retroagem');
});

it('asks for a separate confirmation that lists the competences and sales a past start reaches', function () {
    $construction = retroactiveTestConstruction();

    retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '15.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Correção comercial',
        ])
        ->assertMountedActionModalSee('Esta política alcança vendas já feitas')
        ->assertMountedActionModalSee('O início, 01/07/2026, é anterior a hoje. A política passará a decidir a conformidade das vendas feitas de 01/07/2026 a 24/09/2026: 07/2026 (2 vendas), 08/2026 (nenhuma venda) e 09/2026 (1 venda).')
        ->assertMountedActionModalSee('Confirmo que a política alcança essas vendas')
        // Não há política anterior: a confirmação é a do alcance, não a da substituição.
        ->assertMountedActionModalDontSee('Esta política substitui outra')
        ->callMountedAction()
        ->assertHasActionErrors(['confirm_retroactive']);

    expect(SalesDiscountPolicy::count())->toBe(0)
        ->and(fn () => retroactiveTestRegister($construction, '2026-07-01', '2026-12-31'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'Revise o período e confirme o alcance retroativo.');

    expect(SalesDiscountPolicy::count())->toBe(0);
});

it('registers a retroactive policy once the reach shown on screen is confirmed', function () {
    $construction = retroactiveTestConstruction();

    retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '15.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Correção comercial',
        ])
        ->fillForm(['confirm_retroactive' => true])
        ->assertSchemaStateSet(['confirmed_retroactive_through' => '2026-09-24'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Política registrada.');

    $policy = SalesDiscountPolicy::sole();

    expect($policy->effective_from->toDateString())->toBe('2026-07-01')
        ->and($policy->effective_until->toDateString())->toBe('2026-12-31');
});

it('needs both confirmations when a retroactive policy also substitutes the current one', function () {
    $construction = retroactiveTestConstruction();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-01-01', '2026-12-31')->allowing('5.00')->create();

    $form = retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '15.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Correção comercial',
        ])
        ->assertMountedActionModalSee('Esta política substitui outra')
        ->assertMountedActionModalSee('Esta política alcança vendas já feitas')
        ->fillForm(['confirm_substitution' => true])
        ->callMountedAction()
        ->assertHasActionErrors(['confirm_retroactive']);

    expect(SalesDiscountPolicy::count())->toBe(1)
        ->and(fn () => retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', null, $current->id))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'confirme o alcance retroativo');

    $form->fillForm(['confirm_retroactive' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(SalesDiscountPolicy::count())->toBe(2);
});

it('does not ask for the retroactive confirmation when the policy starts today or later', function (string $from) {
    $construction = retroactiveTestConstruction();

    retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '15.00',
            'effective_from' => $from,
            'effective_until' => '2026-12-31',
            'reason' => 'Política nova',
        ])
        ->assertMountedActionModalDontSee('Esta política alcança vendas já feitas')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(SalesDiscountPolicy::count())->toBe(1);
})->with([
    'hoje' => ['2026-09-24'],
    'amanhã' => ['2026-09-25'],
]);

it('reaches only up to the end of the period when the whole period is in the past', function () {
    $construction = retroactiveTestConstruction();

    $assessment = app(SalesDiscountPolicyRegistrar::class)
        ->assess($construction->id, CarbonImmutable::parse('2026-07-15'), CarbonImmutable::parse('2026-08-31'));

    expect($assessment->isRetroactive())->toBeTrue()
        ->and($assessment->retroactiveThrough?->toDateString())->toBe('2026-08-31')
        ->and($assessment->retroactivityMessage())
        ->toContain('das vendas feitas de 15/07/2026 a 31/08/2026: 07/2026 (1 venda) e 08/2026 (nenhuma venda).');
});

it('withdraws the retroactive confirmation when the dates change', function () {
    $construction = retroactiveTestConstruction();

    retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm(['effective_from' => '2026-07-01', 'effective_until' => '2026-12-31'])
        ->fillForm(['confirm_retroactive' => true])
        ->assertSchemaStateSet(['confirmed_retroactive_through' => '2026-09-24'])
        ->fillForm(['effective_from' => '2026-08-01'])
        ->assertSchemaStateSet(['confirm_retroactive' => false, 'confirmed_retroactive_through' => null]);
});

it('refuses a confirmation given for a reach that grew when the business day turned', function () {
    $construction = retroactiveTestConstruction();

    // Confirmado às 23h de 24/09 em Brasília; gravado depois da meia-noite.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 03:30:00'));

    expect(fn () => retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', '2026-09-24'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'das vendas feitas de 01/07/2026 a 25/09/2026')
        ->and(SalesDiscountPolicy::count())->toBe(0)
        ->and(retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', '2026-09-25')->exists)->toBeTrue();
});

it('refuses a period that reaches a competence already approved and published', function () {
    $construction = retroactiveTestConstruction();
    approvedCycleFor($construction, '2026-07-01');
    approvedCycleFor($construction, '2026-08-01');

    retroactiveTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '15.00',
            'effective_from' => '2026-08-15',
            'effective_until' => '2026-12-31',
            'reason' => 'Correção comercial',
        ])
        ->assertHasActionErrors(['effective_from'])
        ->assertMountedActionModalSee('O período alcança competência já aprovada e publicada desta obra (08/2026). A conformidade das vendas de uma competência publicada não é rejulgada: o início precisa ser posterior a 31/08/2026.')
        ->assertMountedActionModalDontSee('Confirmo que a política alcança essas vendas')
        ->callMountedAction()
        ->assertHasActionErrors(['effective_from']);

    expect(SalesDiscountPolicy::count())->toBe(0)
        ->and(fn () => retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', '2026-09-24'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'competências já aprovadas e publicadas desta obra (07/2026 e 08/2026)')
        ->and(fn () => retroactiveTestRegister($construction, '2026-08-31', '2026-12-31', '2026-09-24'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'o início precisa ser posterior a 31/08/2026')
        ->and(SalesDiscountPolicy::count())->toBe(0);

    $afterApproved = retroactiveTestRegister($construction, '2026-09-01', '2026-12-31', '2026-09-24');

    expect($afterApproved->effective_from->toDateString())->toBe('2026-09-01');
});

it('only counts approved competences of the same construction', function (SalesBoardCycleStatus $status) {
    $construction = retroactiveTestConstruction();
    approvedCycleFor($construction, '2026-08-01', $status);
    approvedCycleFor(Construction::factory()->create(), '2026-07-01');

    $policy = retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', '2026-09-24');

    expect($policy->exists)->toBeTrue();
})->with([
    'gerado' => [SalesBoardCycleStatus::Generated],
    'em análise da Gestão' => [SalesBoardCycleStatus::ManagementReview],
    'devolvido' => [SalesBoardCycleStatus::Returned],
    'cancelado' => [SalesBoardCycleStatus::Cancelled],
]);

it('keeps the verdict of a sale in a published competence when a looser policy is attempted', function () {
    $construction = DerivationFixture::construction();
    $strict = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-01-01', '2026-12-31')->allowing('5.00')->create();

    $unit = DerivationFixture::unit($construction, '101', '1000000.00');
    DerivationFixture::contract($unit, '2026-07-10', '900000.00');
    approvedCycleFor($construction, '2026-07-01');

    $verdict = fn (): SalesPriceConformityStatus => DerivationFixture::derive($construction->fresh(), '2026-07-01')
        ->movements->sales[0]->conformity->status;

    expect($verdict())->toBe(SalesPriceConformityStatus::NonConform)
        ->and(fn () => retroactiveTestRegister($construction, '2026-07-01', '2026-12-31', '2026-09-24', $strict->id))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'competência já aprovada e publicada desta obra (07/2026)')
        ->and($verdict())->toBe(SalesPriceConformityStatus::NonConform)
        ->and(SalesDiscountPolicy::count())->toBe(1);
});
