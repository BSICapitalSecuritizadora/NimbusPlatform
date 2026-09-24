<?php

use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesDiscountPolicyPosition;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * A resolução compara colunas `date`, que o SQLite guarda como texto com hora e
 * o MySQL como data de verdade: o arquivo roda também no MySQL.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    // 12h UTC são 9h em Brasília: o dia de negócio e o dia UTC coincidem.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
});

function periodTestManager(Construction $construction): Testable
{
    return Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ]);
}

function periodTestPolicyIdAt(Construction $construction, string $date): ?int
{
    return app(SalesDiscountPolicyResolver::class)
        ->policyAt($construction, CarbonImmutable::parse($date))
        ->policy?->id;
}

function periodTestRegister(
    Construction $construction,
    string $from,
    string $until,
    ?int $confirmedSubstitutionId = null,
    string $percent = '3.00',
): SalesDiscountPolicy {
    return app(SalesDiscountPolicyRegistrar::class)->register(
        $construction,
        [
            'maximum_discount_percent' => $percent,
            'effective_from' => $from,
            'effective_until' => $until,
            'reason' => 'Revisão comercial',
        ],
        $confirmedSubstitutionId,
        null,
    );
}

it('registers a policy with explicit start and end dates', function () {
    $construction = Construction::factory()->create();

    periodTestManager($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '5.00',
            'effective_from' => '2026-09-24',
            'effective_until' => '2026-12-31',
            'reason' => 'Aprovação comercial',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Política registrada.');

    $policy = SalesDiscountPolicy::sole();

    expect($policy->construction_id)->toBe($construction->id)
        ->and($policy->maximum_discount_percent)->toBe('5.00')
        ->and($policy->effective_from->toDateString())->toBe('2026-09-24')
        ->and($policy->effective_until->toDateString())->toBe('2026-12-31')
        ->and($policy->durationInDays())->toBe(99)
        ->and($policy->reason)->toBe('Aprovação comercial')
        ->and($policy->created_by_id)->toBe(auth()->id());
});

it('replaces the single date field with start, end and a read-only duration', function () {
    periodTestManager(Construction::factory()->create())
        ->mountAction(TestAction::make('newPolicy')->table())
        ->assertMountedActionModalSee('Período de vigência')
        ->assertMountedActionModalSee('Período em que esta política poderá ser aplicada às vendas.')
        ->assertMountedActionModalSee('Início')
        ->assertMountedActionModalSee('Fim')
        ->assertMountedActionModalSee('Duração')
        ->assertMountedActionModalSee('Calculada automaticamente após informar início e fim.')
        ->assertSchemaStateSet(['effective_from' => '2026-09-24', 'effective_until' => null]);
});

it('counts the duration inclusively, both ends included', function (string $from, string $until, ?int $expected) {
    expect(SalesDiscountPolicy::inclusiveDayCount(CarbonImmutable::parse($from), CarbonImmutable::parse($until)))
        ->toBe($expected);
})->with([
    'exemplo do modal' => ['2026-09-24', '2026-12-31', 99],
    'um único dia' => ['2026-09-24', '2026-09-24', 1],
    'ano inteiro' => ['2026-01-01', '2026-12-31', 365],
    'fevereiro de ano bissexto' => ['2028-02-28', '2028-03-01', 3],
    'fim antes do início' => ['2026-12-31', '2026-09-24', null],
]);

it('recalculates the duration as the dates change, without saving', function () {
    periodTestManager(Construction::factory()->create())
        ->mountAction(TestAction::make('newPolicy')->table())
        ->assertMountedActionModalSee('Calculada automaticamente após informar início e fim.')
        ->fillForm(['effective_from' => '2026-09-24', 'effective_until' => '2026-12-31'])
        ->assertMountedActionModalSee('99 dias')
        ->assertMountedActionModalSee('24/09/2026 até 31/12/2026')
        ->fillForm(['effective_until' => '2026-09-30'])
        ->assertMountedActionModalSee('7 dias')
        ->assertMountedActionModalSee('24/09/2026 até 30/09/2026')
        ->assertMountedActionModalDontSee('99 dias')
        ->fillForm(['effective_until' => '2026-09-24'])
        ->assertMountedActionModalSee('1 dia');

    expect(SalesDiscountPolicy::count())->toBe(0);
});

it('shows a validation error, not a duration, when the end comes before the start', function () {
    periodTestManager(Construction::factory()->create())
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm(['effective_from' => '2026-12-31', 'effective_until' => '2026-09-24', 'reason' => 'Campanha'])
        ->assertHasActionErrors(['effective_until'])
        ->assertMountedActionModalDontSee('deve ser uma data posterior')
        ->assertSchemaStateSet(['reason' => 'Campanha'])
        ->assertMountedActionModalSee('O fim precisa ser igual ou posterior ao início.')
        ->assertMountedActionModalSee('Ajuste o período para calcular a duração.')
        ->assertMountedActionModalDontSee('dias');
});

it('refuses to register an end date before the start date', function () {
    $construction = Construction::factory()->create();

    periodTestManager($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '5.00',
            'effective_from' => '2026-12-31',
            'effective_until' => '2026-09-24',
            'reason' => 'Teste',
        ])
        ->assertHasActionErrors(['effective_until']);

    expect(SalesDiscountPolicy::count())->toBe(0)
        ->and(fn () => periodTestRegister($construction, '2026-12-31', '2026-09-24'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'O fim da vigência precisa ser igual ou posterior ao início.');
});

it('accepts a one-day policy that keeps the default start date', function () {
    $construction = Construction::factory()->create();

    periodTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '5.00',
            'effective_until' => '2026-09-24',
            'reason' => 'Campanha de um dia',
        ])
        ->assertMountedActionModalSee('1 dia')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $policy = SalesDiscountPolicy::sole();

    expect($policy->effective_from->toDateString())->toBe('2026-09-24')
        ->and($policy->effective_until->toDateString())->toBe('2026-09-24');
});

it('requires start, end, discount and reason', function () {
    periodTestManager(Construction::factory()->create())
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => null,
            'effective_from' => null,
            'effective_until' => null,
            'reason' => null,
        ])
        ->assertHasActionErrors([
            'maximum_discount_percent' => 'required',
            'effective_from' => 'required',
            'effective_until' => 'required',
            'reason' => 'required',
        ]);

    expect(SalesDiscountPolicy::count())->toBe(0);
});

it('accepts back-to-back periods without asking for confirmation', function () {
    $construction = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-01', '2026-09-30')->create();

    $assessment = app(SalesDiscountPolicyRegistrar::class)
        ->assess($construction->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31'));

    expect($assessment->isBlocked())->toBeFalse()
        ->and($assessment->substitutes())->toBeFalse();

    periodTestManager($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '4.00',
            'effective_from' => '2026-10-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Renovação',
        ])
        ->assertHasNoActionErrors();

    expect(SalesDiscountPolicy::count())->toBe(2);
});

it('blocks a period that a later-starting policy would hide', function () {
    $construction = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->during('2027-01-01', '2027-06-30')->allowing('4.00')->create();

    periodTestManager($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '5.00',
            'effective_from' => '2026-10-01',
            'effective_until' => '2027-03-31',
            'reason' => 'Campanha',
        ])
        ->assertHasActionErrors(['effective_until'])
        ->assertMountedActionModalSee('Já existe a política de 4,00% com início em 01/01/2027, dentro do período informado. O fim desta política precisa ser anterior a 01/01/2027.');

    expect(SalesDiscountPolicy::count())->toBe(1)
        ->and(fn () => periodTestRegister($construction, '2026-10-01', '2027-03-31'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'Já existe a política de 4,00% com início em 01/01/2027');
});

it('names the correction, not the corrected policy, when blocking a period', function () {
    $construction = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->during('2027-01-01', '2027-06-30')->allowing('40.00')->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->during('2027-01-01', '2027-06-30')->allowing('4.00')->create();

    $assessment = app(SalesDiscountPolicyRegistrar::class)
        ->assess($construction->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2027-03-31'));

    expect($assessment->isBlocked())->toBeTrue()
        ->and($assessment->blockingMessage())->toStartWith('Já existe a política de 4,00% com início em 01/01/2027');
});

it('asks for explicit confirmation before a new policy substitutes the current one', function () {
    $construction = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-01', '2026-09-30')->allowing('5.00')->create();

    periodTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '3.00',
            'effective_from' => '2026-09-24',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão de margem',
        ])
        ->assertMountedActionModalSee('Esta política substitui outra')
        ->assertMountedActionModalSee('A política de 5,00% (01/09/2026 a 30/09/2026) deixa de valer a partir de 24/09/2026. As vendas anteriores continuam com ela.')
        ->assertMountedActionModalSee('Confirmo a substituição')
        ->callMountedAction()
        ->assertHasActionErrors(['confirm_substitution']);

    expect(SalesDiscountPolicy::count())->toBe(1)
        ->and(fn () => periodTestRegister($construction, '2026-09-24', '2026-12-31'))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'Revise o período e confirme a substituição.');
});

it('registers a confirmed substitution and keeps the substituted policy for the sales before it', function () {
    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-01', '2026-09-30')->allowing('5.00')->create();

    $manager = periodTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '3.00',
            'effective_from' => '2026-09-24',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão de margem',
        ])
        ->fillForm(['confirm_substitution' => true])
        ->assertSchemaStateSet(['confirmed_substitution_id' => $current->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Política registrada.');

    $substitute = SalesDiscountPolicy::query()->whereKeyNot($current->id)->sole();

    // A tabela é redesenhada na mesma requisição do registro: a posição já
    // precisa refletir a política nova, sem recarregar a página.
    $manager
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Superseded, $current)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $substitute);

    expect(periodTestPolicyIdAt($construction, '2026-09-23'))->toBe($current->id)
        ->and(periodTestPolicyIdAt($construction, '2026-09-24'))->toBe($substitute->id)
        ->and(periodTestPolicyIdAt($construction, '2026-12-31'))->toBe($substitute->id)
        ->and(periodTestPolicyIdAt($construction, '2027-01-01'))->toBeNull()
        ->and($current->fresh()->effective_until->toDateString())->toBe('2026-09-30');

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Superseded, $current)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $substitute)
        ->assertSee('em 24/09/2026');
});

it('changing the dates withdraws a confirmation given for another substitution', function () {
    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-01', '2026-12-31')->create();

    periodTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm(['effective_from' => '2026-10-01', 'effective_until' => '2026-12-31'])
        ->fillForm(['confirm_substitution' => true])
        ->assertSchemaStateSet(['confirmed_substitution_id' => $current->id])
        ->fillForm(['effective_until' => '2026-11-30'])
        ->assertSchemaStateSet(['confirm_substitution' => false, 'confirmed_substitution_id' => null]);
});

it('refuses a confirmation that no longer matches the policy being substituted', function () {
    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-01', '2026-12-31')->allowing('5.00')->create();

    $form = periodTestManager($construction)
        ->mountAction(TestAction::make('newPolicy')->table())
        ->fillForm([
            'maximum_discount_percent' => '3.00',
            'effective_from' => '2026-10-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão de margem',
        ])
        ->fillForm(['confirm_substitution' => true]);

    // Enquanto o formulário estava aberto, outra pessoa substituiu a política
    // vigente. A confirmação dada valia para outra situação.
    $intervening = periodTestRegister($construction, '2026-09-20', '2026-12-31', $current->id, '4.00');

    $form->callMountedAction()
        ->assertNotified('Não foi possível registrar a política.');

    expect(SalesDiscountPolicy::count())->toBe(2)
        ->and(fn () => periodTestRegister($construction, '2026-10-01', '2026-12-31', $current->id))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'A política de 4,00% (20/09/2026 a 31/12/2026) deixa de valer a partir de 01/10/2026.')
        ->and(periodTestRegister($construction, '2026-10-01', '2026-12-31', $intervening->id)->exists)->toBeTrue();
});

it('says which dates will be left without policy when the substitute ends first', function () {
    $registrar = app(SalesDiscountPolicyRegistrar::class);

    $openEnded = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($openEnded)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    $bounded = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($bounded)->during('2026-09-01', '2027-03-31')->allowing('5.00')->create();

    $covered = Construction::factory()->create();
    SalesDiscountPolicy::factory()->forConstruction($covered)->during('2026-09-01', '2026-11-30')->allowing('5.00')->create();

    $period = [CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31')];

    expect($registrar->assess($openEnded->id, ...$period)->substitutionMessage())
        ->toContain('A política de 5,00% (desde 01/01/2026, sem data de fim) deixa de valer a partir de 01/10/2026.')
        ->toContain('Ela não volta a valer depois de 31/12/2026: a partir de 01/01/2027 a obra ficará sem política vigente até que outra seja registrada.')
        ->and($registrar->assess($bounded->id, ...$period)->substitutionMessage())
        ->toContain('de 01/01/2027 a 31/03/2027 a obra ficará sem política vigente.')
        ->and($registrar->assess($covered->id, ...$period)->substitutionMessage())
        ->not->toContain('sem política vigente');
});

it('lets a correction with the same start replace the policy entirely', function () {
    $construction = Construction::factory()->create();
    $wrong = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-09-24', '2026-12-31')->allowing('50.00')->create();

    $assessment = app(SalesDiscountPolicyRegistrar::class)
        ->assess($construction->id, CarbonImmutable::parse('2026-09-24'), CarbonImmutable::parse('2026-12-31'));

    expect($assessment->substitutionMessage())
        ->toBe('A política de 50,00% (24/09/2026 a 31/12/2026) começa no mesmo dia e será substituída por inteiro.');

    $fixed = periodTestRegister($construction, '2026-09-24', '2026-12-31', $wrong->id, '5.00');

    expect(periodTestPolicyIdAt($construction, '2026-09-24'))->toBe($fixed->id)
        ->and(periodTestPolicyIdAt($construction, '2026-12-31'))->toBe($fixed->id)
        ->and(SalesDiscountPolicy::count())->toBe(2);

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Superseded, $wrong)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $fixed);
});

it('accepts a future policy that does not retroact', function () {
    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-01-01', '2026-12-31')->allowing('5.00')->create();

    periodTestManager($construction)
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '4.00',
            'effective_from' => '2027-01-01',
            'effective_until' => '2027-12-31',
            'reason' => 'Política do próximo ano',
        ])
        ->assertHasNoActionErrors();

    $future = SalesDiscountPolicy::query()->whereKeyNot($current->id)->sole();

    expect(periodTestPolicyIdAt($construction, '2026-09-24'))->toBe($current->id)
        ->and(periodTestPolicyIdAt($construction, '2026-12-31'))->toBe($current->id)
        ->and(periodTestPolicyIdAt($construction, '2027-01-01'))->toBe($future->id);

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $current)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Scheduled, $future)
        ->assertSee('01/01/2027 a 31/12/2027')
        ->assertSee('365 dias');
});

it('tells how long the current policy lasts when a scheduled one will substitute it', function () {
    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-01-01', '2026-12-31')->create();

    $scheduled = periodTestRegister($construction, '2026-11-01', '2027-06-30', $current->id);

    expect(periodTestPolicyIdAt($construction, '2026-10-31'))->toBe($current->id)
        ->and(periodTestPolicyIdAt($construction, '2026-11-01'))->toBe($scheduled->id);

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $current)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Scheduled, $scheduled)
        ->assertSee('até 31/10/2026');
});

it('marks a policy that reached its end without substitution as ended', function () {
    $construction = Construction::factory()->create();
    $ended = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-01-01', '2026-06-30')->create();

    expect(periodTestPolicyIdAt($construction, '2026-09-24'))->toBeNull();

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Ended, $ended);
});

it('applies the policy by sale date and leaves a sale after the end without policy', function () {
    $construction = DerivationFixture::construction();
    $policy = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-06-01', '2026-07-10')->allowing('10.00')->create();

    $saleOnTheLastDay = DerivationFixture::contract(DerivationFixture::unit($construction, '301'), '2026-07-10', '500000.00');
    DerivationFixture::installment($saleOnTheLastDay, '001', '2026-08-10', '500000.00');

    $saleAfterTheEnd = DerivationFixture::contract(DerivationFixture::unit($construction, '302'), '2026-07-11', '500000.00');
    DerivationFixture::installment($saleAfterTheEnd, '001', '2026-08-11', '500000.00');

    $position = DerivationFixture::derive($construction);
    $sales = collect($position->movements->sales)->keyBy('contractId');

    expect($sales[$saleOnTheLastDay->id]->salesDiscountPolicyId)->toBe($policy->id)
        ->and($sales[$saleOnTheLastDay->id]->conformity->status)->not->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($sales[$saleAfterTheEnd->id]->salesDiscountPolicyId)->toBeNull()
        ->and($sales[$saleAfterTheEnd->id]->conformity->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($sales[$saleAfterTheEnd->id]->conformity->reasonWhenUndetermined)
        ->toBe('O empreendimento não tinha política de desconto vigente na data da venda.')
        ->and(DerivationFixture::issueCodes($position))->toContain(SalesBoardIssueCode::SaleDiscountPolicyMissing->value);
});

it('keeps every past sale on the policy that was valid on its date as new policies are registered', function () {
    $construction = Construction::factory()->create();
    $third = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-07-01', '2026-09-30')->allowing('5.00')->create();

    $before = [
        '2026-07-15' => periodTestPolicyIdAt($construction, '2026-07-15'),
        '2026-09-30' => periodTestPolicyIdAt($construction, '2026-09-30'),
    ];

    $fourth = periodTestRegister($construction, '2026-10-01', '2026-12-31', null, '4.00');
    $campaign = periodTestRegister($construction, '2026-11-15', '2027-03-31', $fourth->id, '3.00');

    expect($before)->toBe(['2026-07-15' => $third->id, '2026-09-30' => $third->id])
        ->and(periodTestPolicyIdAt($construction, '2026-07-15'))->toBe($third->id)
        ->and(periodTestPolicyIdAt($construction, '2026-09-30'))->toBe($third->id)
        ->and(periodTestPolicyIdAt($construction, '2026-10-15'))->toBe($fourth->id)
        ->and(periodTestPolicyIdAt($construction, '2026-11-14'))->toBe($fourth->id)
        ->and(periodTestPolicyIdAt($construction, '2026-11-15'))->toBe($campaign->id)
        ->and($third->fresh()->effective_until->toDateString())->toBe('2026-09-30')
        ->and($fourth->fresh()->effective_until->toDateString())->toBe('2026-12-31');
});

it('keeps legacy policies without end date on the previous rule', function () {
    $construction = Construction::factory()->create();
    $july = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-01')->allowing('5.00')->create();
    $august = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-08-01')->allowing('1.00')->create();

    expect(periodTestPolicyIdAt($construction, '2026-07-31'))->toBe($july->id)
        ->and(periodTestPolicyIdAt($construction, '2026-08-01'))->toBe($august->id)
        ->and(periodTestPolicyIdAt($construction, '2030-01-01'))->toBe($august->id)
        ->and($july->durationInDays())->toBeNull();

    periodTestManager($construction)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Superseded, $july)
        ->assertTableColumnStateSet('position', SalesDiscountPolicyPosition::Current, $august)
        ->assertSee('Desde 01/07/2026')
        ->assertSee('Sem data de fim')
        ->assertSee('em 01/08/2026');
});

it('adds the end date as a nullable column, so existing rows keep no invented end', function () {
    $construction = Construction::factory()->create();

    $legacy = SalesDiscountPolicy::query()->create([
        'construction_id' => $construction->id,
        'maximum_discount_percent' => '5.00',
        'effective_from' => '2026-01-01',
        'reason' => 'Registro anterior ao fim explícito',
    ]);

    expect(Schema::hasColumn('sales_discount_policies', 'effective_until'))->toBeTrue()
        ->and($legacy->fresh()->effective_until)->toBeNull()
        ->and(periodTestPolicyIdAt($construction, '2026-09-24'))->toBe($legacy->id);
});

it('keeps the fingerprint row of a legacy policy unchanged and adds the end only when it exists', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);
    $sale = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sale, '001', '2026-08-05', '600000.00');

    $legacy = SalesDiscountPolicy::query()->where('construction_id', $construction->id)->sole();
    $bounded = SalesDiscountPolicy::factory()->forConstruction($construction)->during('2026-07-01', '2026-12-31')->allowing('4.00')->create();

    $observe = fn (): array => app(SalesBoardFingerprintService::class)
        ->observeForConstruction($construction->fresh(), CarbonImmutable::parse('2026-07-01'))
        ->policyRows;

    $rows = $observe();

    expect($legacy->effective_until)->toBeNull()
        ->and($rows)->toContain(CanonicalDigest::row([$legacy->id, $construction->id, 1000, $legacy->effective_from]))
        ->and($rows)->toContain(CanonicalDigest::row([$bounded->id, $construction->id, 400, $bounded->effective_from, $bounded->effective_until]));

    $bounded->forceFill(['effective_until' => '2026-07-31'])->save();

    expect($observe())->not->toBe($rows);
});

it('only lets users who can update emissions register a policy', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('emissions.view');
    $this->actingAs($viewer);

    $construction = Construction::factory()->create();

    periodTestManager($construction)
        ->assertActionHidden(TestAction::make('newPolicy')->table())
        ->call('mountAction', 'newPolicy', [], ['table' => true])
        ->assertSet('mountedActions', []);

    expect(SalesDiscountPolicy::count())->toBe(0);
});
