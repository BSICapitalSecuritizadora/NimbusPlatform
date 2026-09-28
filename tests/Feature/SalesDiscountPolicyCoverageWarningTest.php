<?php

use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesDiscountPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * Toda política registrada pela tela tem fim. Quando ele chega sem sucessora,
 * a próxima venda bloqueia a competência inteira por falta de política -- e a
 * aba da obra precisa avisar antes, não depois.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    // 12h UTC são 9h em Brasília: o dia de negócio e o dia UTC coincidem.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
});

function coverageTestManager(Construction $construction): Testable
{
    return Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ]);
}

/**
 * @param  list<array{0: string, 1: string|null}>  $periods  início e fim de cada política, na ordem de registro
 */
function coverageTestConstruction(array $periods): Construction
{
    $construction = Construction::factory()->create();

    foreach ($periods as [$from, $until]) {
        SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom($from)->effectiveUntil($until)->create();
    }

    return $construction;
}

it('warns that the discount policy coverage ends soon', function (array $periods, string $heading, array $description) {
    $manager = coverageTestManager(coverageTestConstruction($periods))
        ->assertSee($heading);

    foreach ($description as $sentence) {
        $manager->assertSee($sentence);
    }
})->with([
    'vence em 16 dias' => [
        [['2026-01-01', '2026-10-10']],
        'A política de desconto vence em 16 dias',
        ['A cobertura termina em 10/10/2026 e não há política registrada para 11/10/2026.', 'bloquearão a competência'],
    ],
    'vence hoje' => [
        [['2026-01-01', '2026-09-24']],
        'A política de desconto vence hoje',
        ['A cobertura termina em 24/09/2026 e não há política registrada para 25/09/2026.'],
    ],
    'vence amanhã' => [
        [['2026-01-01', '2026-09-25']],
        'A política de desconto vence amanhã',
        ['não há política registrada para 26/09/2026.'],
    ],
    'no limite de 30 dias' => [
        [['2026-01-01', '2026-10-24']],
        'A política de desconto vence em 30 dias',
        ['não há política registrada para 25/10/2026.'],
    ],
    'lacuna antes da próxima' => [
        [['2026-01-01', '2026-09-30'], ['2026-10-15', '2027-06-30']],
        'A política de desconto vence em 6 dias',
        ['não há política registrada para 01/10/2026.', 'A próxima registrada só começa em 15/10/2026.'],
    ],
    'substituída por uma política mais curta' => [
        [['2026-01-01', '2027-12-31'], ['2026-10-01', '2026-10-20']],
        'A política de desconto vence em 26 dias',
        ['A cobertura termina em 20/10/2026 e não há política registrada para 21/10/2026.'],
    ],
    'corrigida por outra com o mesmo início' => [
        [['2026-01-01', '2026-12-31'], ['2026-01-01', '2026-10-10']],
        'A política de desconto vence em 16 dias',
        ['A cobertura termina em 10/10/2026'],
    ],
]);

it('warns in red when the construction already has no policy in force', function (array $periods, array $description) {
    $manager = coverageTestManager(coverageTestConstruction($periods))
        ->assertSee('Obra sem política de desconto vigente')
        ->assertSee('bloqueiam a competência até que uma política seja registrada.');

    foreach ($description as $sentence) {
        $manager->assertSee($sentence);
    }
})->with([
    'terminou sem sucessora' => [
        [['2026-01-01', '2026-06-30']],
        ['Nenhuma política vale desde 01/07/2026.'],
    ],
    'só existe política futura' => [
        [['2026-10-01', '2026-12-31']],
        ['Nenhuma política vale hoje.', 'A próxima registrada começa em 01/10/2026.'],
    ],
    'lacuna entre a encerrada e a próxima' => [
        [['2026-01-01', '2026-06-30'], ['2026-10-01', '2026-12-31']],
        ['Nenhuma política vale desde 01/07/2026.', 'A próxima registrada começa em 01/10/2026.'],
    ],
]);

it('stays quiet while the coverage lasts more than 30 days', function (array $periods) {
    coverageTestManager(coverageTestConstruction($periods))
        ->assertDontSee('A política de desconto vence')
        ->assertDontSee('Obra sem política de desconto vigente');
})->with([
    '31 dias' => [[['2026-01-01', '2026-10-25']]],
    'fim do ano' => [[['2026-01-01', '2026-12-31']]],
    'encadeada na próxima' => [[['2026-01-01', '2026-09-30'], ['2026-10-01', '2027-06-30']]],
    'legada sem fim' => [[['2020-01-01', null]]],
    'nenhuma política' => [[]],
]);

it('clears the warning as soon as the next policy is registered', function () {
    $construction = coverageTestConstruction([['2026-01-01', '2026-10-10']]);

    coverageTestManager($construction)
        ->assertSee('A política de desconto vence em 16 dias')
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '5.00',
            'effective_from' => '2026-10-11',
            'effective_until' => '2027-12-31',
            'reason' => 'Renovação',
        ])
        ->assertHasNoActionErrors()
        ->assertDontSee('A política de desconto vence');
});

it('offers a closed-period factory state, the format the screen registers', function () {
    $policy = SalesDiscountPolicy::factory()->effectiveFrom('2020-01-01')->closedPeriod()->create();

    expect($policy->effective_until?->toDateString())->toBe('2099-12-31')
        ->and($policy->durationInDays())->not->toBeNull();
});

it('builds the cycle, rollout and automation scenarios on closed-period policies', function () {
    CycleFixture::readyConstruction(1);
    RolloutFixture::construction(Emission::factory()->create(['status' => 'active']), 'R');
    AutomationFixture::readyConstruction('7');
    AutomationFixture::blockedConstruction('8');

    expect(SalesDiscountPolicy::query()->count())->toBe(4)
        ->and(SalesDiscountPolicy::query()->whereNull('effective_until')->exists())->toBeFalse();
});
