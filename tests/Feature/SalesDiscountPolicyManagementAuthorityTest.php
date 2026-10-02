<?php

use App\Enums\SalesBoardNonconformityOrigin;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * A política que rejulga venda já registrada é da Gestão.
 *
 * Retroativa, ou começando hoje com venda já feita hoje, ela muda o veredito de
 * conformidade de uma venda que já existe -- e afrouxar a régua de uma venda
 * fora da política neutralizaria a exceção que só a Gestão concede, antes mesmo
 * de a competência ser gerada. A política que vale daqui para a frente continua
 * com quem opera o cadastro comercial. A tela avisa antes de gravar; o
 * registrador recusa no servidor.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    // 12h UTC são 9h em Brasília: o dia de negócio é 05/08/2026.
    $this->travelTo(CarbonImmutable::parse('2026-08-05 12:00:00'));
});

/**
 * Quem opera o cadastro comercial: edita a Emissão, sem a Gestão.
 */
function policyEditor(): User
{
    $editor = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $editor->givePermissionTo(['constructions.view', 'emissions.view', 'emissions.update']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $editor->fresh();
}

function policyManager(): User
{
    $manager = makeAdminUser();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $manager->fresh();
}

function policyAuthorityPage(Construction $construction): Testable
{
    return Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ]);
}

/**
 * Obra sem política, com uma venda em julho.
 */
function policyAuthorityConstruction(?string $saleDate = '2026-07-15'): Construction
{
    $construction = DerivationFixture::construction();

    if ($saleDate !== null) {
        DerivationFixture::contract(DerivationFixture::unit($construction, '101'), $saleDate);
    }

    return $construction;
}

function registerPolicyAs(?User $actor, Construction $construction, string $from, string $until = '2026-12-31', ?string $confirmedRetroactiveThrough = null): SalesDiscountPolicy
{
    return app(SalesDiscountPolicyRegistrar::class)->register(
        construction: $construction,
        attributes: [
            'maximum_discount_percent' => '25.00',
            'effective_from' => $from,
            'effective_until' => $until,
            'reason' => 'Revisão da margem comercial do empreendimento.',
        ],
        confirmedSubstitutionId: null,
        registeredBy: $actor,
        confirmedRetroactiveThrough: $confirmedRetroactiveThrough,
    );
}

it('refuses a retroactive policy from a user without the sales board approval permission', function () {
    $construction = policyAuthorityConstruction();
    $editor = policyEditor();
    $this->actingAs($editor);

    $assessment = app(SalesDiscountPolicyRegistrar::class)->assess(
        $construction->id,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($assessment->requiresManagementAuthority())->toBeTrue()
        ->and($assessment->salesAlreadyRecordedInPeriod)->toBe(1)
        ->and($assessment->managementAuthorityMessage())
        ->toStartWith('A política começa em 01/07/2026, antes de hoje, e passa a decidir a conformidade de vendas já feitas: 1 venda no período')
        ->toContain('Para valer só daqui para a frente, comece amanhã.');

    $page = policyAuthorityPage($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '25.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão da margem comercial do empreendimento.',
        ])
        ->assertMountedActionModalSee('Somente a Gestão registra esta política')
        ->callMountedAction()
        ->assertHasActionErrors(['effective_from']);

    // A mensagem tem dois-pontos, que a asserção de regra do Livewire leria
    // como parâmetro: o texto é conferido direto na bolsa de erros.
    expect($page->errors()->get('mountedActions.0.data.effective_from'))
        ->toContain($assessment->managementAuthorityMessage());

    expect(fn () => registerPolicyAs($editor, $construction, '2026-07-01', confirmedRetroactiveThrough: '2026-08-05'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, (string) $assessment->managementAuthorityMessage());

    expect(SalesDiscountPolicy::query()->where('construction_id', $construction->id)->count())->toBe(0);
});

it('lets management register a retroactive policy with both confirmations', function () {
    $construction = policyAuthorityConstruction();
    $this->actingAs(policyManager());

    policyAuthorityPage($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '25.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão da margem comercial do empreendimento.',
        ])
        ->assertMountedActionModalDontSee('Somente a Gestão registra esta política')
        ->assertMountedActionModalSee('Esta política alcança vendas já feitas')
        ->fillForm(['confirm_retroactive' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $policy = SalesDiscountPolicy::query()->where('construction_id', $construction->id)->sole();

    expect($policy->effective_from->toDateString())->toBe('2026-07-01')
        ->and($policy->created_by_id)->toBe(auth()->id());
});

it('lets the editor register a policy that starts tomorrow', function () {
    $construction = policyAuthorityConstruction();
    $editor = policyEditor();
    $this->actingAs($editor);

    policyAuthorityPage($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '25.00',
            'effective_from' => '2026-08-06',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão da margem comercial do empreendimento.',
        ])
        ->assertHasNoActionErrors();

    expect(SalesDiscountPolicy::query()->where('construction_id', $construction->id)->sole()->created_by_id)->toBe($editor->id);
});

it('treats a policy starting today as management-only when a sale was already recorded today', function (string $case) {
    $construction = policyAuthorityConstruction($case === 'com venda hoje' ? '2026-08-05' : '2026-07-15');
    $editor = policyEditor();

    $register = fn (): SalesDiscountPolicy => registerPolicyAs($editor, $construction, '2026-08-05');

    if ($case === 'com venda hoje') {
        expect($register)->toThrow(
            SalesDiscountPolicyPeriodException::class,
            'Já há 1 venda registrada hoje, 05/08/2026, e a política passaria a decidir a conformidade delas.',
        );

        expect(SalesDiscountPolicy::query()->where('construction_id', $construction->id)->count())->toBe(0);

        // A Gestão registra a mesma política.
        expect(registerPolicyAs(policyManager(), $construction, '2026-08-05')->exists)->toBeTrue();

        return;
    }

    expect($register()->created_by_id)->toBe($editor->id);
})->with([
    'com venda hoje',
    'sem venda hoje',
]);

it('refuses to register without an identified actor or without emissions.update', function () {
    $construction = policyAuthorityConstruction(null);

    $approverOnly = User::factory()->create();
    $approverOnly->givePermissionTo('sales-boards.approve');

    expect(fn () => registerPolicyAs(null, $construction, '2026-08-06'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, SalesDiscountPolicyPeriodException::actorRequired()->getMessage())
        ->and(fn () => registerPolicyAs($approverOnly, $construction, '2026-08-06'))
        ->toThrow(AuthorizationException::class);

    expect(SalesDiscountPolicy::query()->where('construction_id', $construction->id)->count())->toBe(0);
});

it('keeps the non conform sale for management when the editor tries to loosen the policy', function () {
    // A tabela é 500.000 e a política permite 10%: a venda de 400.000 está
    // abaixo do mínimo de 450.000.
    [$construction, $units] = CycleFixture::readyConstruction(1);
    $sale = DerivationFixture::contract($units[0], '2026-07-15', '400000.00');
    DerivationFixture::installment($sale, '001', '2026-08-15', '400000.00');

    // O editor tenta afrouxar a régua de julho para 25% antes da geração.
    expect(fn () => registerPolicyAs(policyEditor(), $construction, '2026-07-01', confirmedRetroactiveThrough: '2026-08-05'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'Uma política que rejulga venda já registrada é registrada pela Gestão');

    $cycle = CycleFixture::generate($construction)->cycle;
    $builderReview = BuilderReviewFixture::open($cycle);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($cycle);

    expect($review->fresh()->nonconformities)->toHaveCount(1)
        ->and($review->fresh()->nonconformities->sole()->origin)->toBe(SalesBoardNonconformityOrigin::SystemSaleNonConform)
        ->and($review->fresh()->nonconformities->sole()->origin->value)->toBe('venda_nao_conforme');
});
