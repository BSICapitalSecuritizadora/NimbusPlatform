<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardRolloutEvent;
use App\Models\User;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * @return array{emission: Emission, constructions: list<Construction>}
 */
function activatableEmission(int $constructions = 2): array
{
    $scenario = RolloutFixture::emission($constructions);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    return $scenario;
}

function eligibleConstructionIds(): array
{
    return collect(app(SalesBoardAutomationEligibilityProvider::class)->eligibleTargets())
        ->map(fn ($target): int => $target->constructionId)
        ->sort()
        ->values()
        ->all();
}

it('activates the emission and records the event', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    $actor = User::factory()->create();

    $emission = RolloutFixture::activate($scenario['emission'], $homologation, $actor);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_automation_start_reference_month->format('Y-m'))->toBe('2026-08')
        ->and($emission->sales_board_active_homologation_id)->toBe($homologation->id)
        ->and($emission->sales_board_auto_open_builder_review)->toBeFalse()
        ->and($homologation->fresh()->wasActivated())->toBeTrue();

    $event = SalesBoardRolloutEvent::query()->sole();

    expect($event->event_type)->toBe(SalesBoardRolloutEventType::Activated)
        ->and($event->from_source)->toBe(SalesBoardSource::Legacy)
        ->and($event->to_source)->toBe(SalesBoardSource::Automated)
        ->and($event->start_reference_month->format('Y-m'))->toBe('2026-08')
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->sales_board_rollout_homologation_id)->toBe($homologation->id);
});

it('does no work at all at the moment of activation', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    RolloutFixture::activate($scenario['emission'], $homologation);

    // A próxima execução da Fase F é que descobre e gera. Ativar apenas muda o
    // modo -- misturar as duas coisas produziria catch-up dentro da transação.
    expect(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0);
});

it('refuses to activate a homologation that was not approved', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::open($scenario['emission']);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'exige uma homologação aprovada');

    expect($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('refuses to activate twice with the same homologation', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    RolloutFixture::activate($scenario['emission'], $homologation);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'já está no modo automatizado');
});

it('refuses to activate when a manual board appeared after approval', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    // O quadro manual nasce depois da aprovação, na competência inicial.
    $manual = RolloutFixture::legacyBoard($scenario['constructions'][0], '2026-08-01');

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'Já existe Quadro de Vendas registrado');

    expect($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy)
        ->and($manual->fresh()->exists)->toBeTrue();
});

it('reconfirms the conflict read-only, without rewriting the approved homologation', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    $hashBefore = $homologation->fresh()->assessment_hash;
    $assessedBefore = $homologation->fresh()->assessed_at;

    // A fonte muda **e** um quadro manual aparece depois da aprovação. A
    // reconferência precisa recusar a ativação sem tentar reescrever o retrato
    // que a Gestão revisou -- a homologação aprovada é imutável.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '765',
        'base_value' => '700000.00', 'base_value_reference_date' => '2026-01-01',
    ]);
    RolloutFixture::legacyBoard($scenario['constructions'][0], '2026-08-01');

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'Já existe Quadro de Vendas registrado');

    expect($homologation->fresh()->assessment_hash)->toBe($hashBefore)
        ->and($homologation->fresh()->assessed_at->toDateTimeString())->toBe($assessedBefore->toDateTimeString())
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('refuses to activate when the emission gained a construction after approval', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    RolloutFixture::construction($scenario['emission'], 'Z');

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'empreendimentos da Emissão mudaram');

    expect($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('gives the automation every construction of an activated emission', function () {
    $scenario = activatableEmission(3);
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    $expected = collect($scenario['constructions'])->map(fn ($c): int => $c->id)->sort()->values()->all();

    expect(eligibleConstructionIds())->toBe($expected);
});

it('gives the automation nothing while the emission is still legacy', function () {
    $scenario = activatableEmission();
    RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::enableGlobalAutomation();

    // Aprovar não é ativar.
    expect(eligibleConstructionIds())->toBe([]);
});

it('gives the automation nothing while the global switch is off', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    config()->set('sales_board.automation.enabled', false);

    expect(eligibleConstructionIds())->toBe([]);
});

it('suspends the whole emission when a construction is added after activation', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    expect(eligibleConstructionIds())->toHaveCount(2);

    RolloutFixture::construction($scenario['emission'], 'Z');

    // Rollout é por Emissão inteira: continuar automatizando só os antigos
    // seria rollout parcial, e a Emissão passaria a ter metade das competências
    // saindo pelo motor e metade não saindo de lugar nenhum.
    expect(eligibleConstructionIds())->toBe([])
        ->and(app(DatabaseSalesBoardAutomationEligibilityProvider::class)
            ->hasScopeDrift($scenario['emission']->fresh()))->toBeTrue()
        // E o modo não muda sozinho: a suspensão é de elegibilidade, não de source.
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Automated);
});

it('suspends the whole emission when a construction is removed after activation', function () {
    $scenario = activatableEmission(3);
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    expect(eligibleConstructionIds())->toHaveCount(3);

    // Sair da Emissão é a forma real de o escopo encolher: apagar o
    // empreendimento é recusado pela FK da homologação, que é registro de
    // auditoria e não evapora.
    $scenario['constructions'][2]->update([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    expect(eligibleConstructionIds())->toBe([]);
});

it('returns to legacy without erasing any history', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    // A automação produziu uma competência antes do retorno.
    app(SalesBoardAutomationService::class)->run(
        asOf: CarbonImmutable::parse('2026-09-13'),
    );

    $cyclesBefore = SalesBoardCycle::query()->count();
    $targetsBefore = SalesBoardAutomationTarget::query()->count();

    expect($cyclesBefore)->toBe(2)->and($targetsBefore)->toBe(2);

    $actor = User::factory()->create();
    $emission = RolloutFixture::returnToLegacy($scenario['emission'], $actor);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Legacy)
        ->and($emission->sales_board_automation_start_reference_month)->toBeNull()
        ->and($emission->sales_board_active_homologation_id)->toBeNull()
        ->and($emission->sales_board_auto_open_builder_review)->toBeFalse()
        // Nada é apagado: o rollout decide o futuro, não reescreve o passado.
        ->and(SalesBoardCycle::query()->count())->toBe($cyclesBefore)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe($targetsBefore)
        ->and($homologation->fresh()->exists)->toBeTrue()
        // E a elegibilidade para.
        ->and(eligibleConstructionIds())->toBe([]);

    $event = SalesBoardRolloutEvent::query()->latest('id')->first();

    expect($event->event_type)->toBe(SalesBoardRolloutEventType::ReturnedToLegacy)
        ->and($event->from_source)->toBe(SalesBoardSource::Automated)
        ->and($event->to_source)->toBe(SalesBoardSource::Legacy)
        ->and($event->actor_user_id)->toBe($actor->id);
});

it('refuses to return to legacy without a reason', function (string $reason) {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    expect(fn () => RolloutFixture::returnToLegacy($scenario['emission'], null, $reason))
        ->toThrow(SalesBoardRolloutException::class, 'pelo menos 10 caracteres');

    expect($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Automated);
})->with(['', ' ', 'ok', '.']);

it('never lets an old target be attempted again after returning to legacy', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    // Uma competência bloqueada, para o alvo continuar aberto.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '900',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);

    app(SalesBoardAutomationService::class)->run(
        asOf: CarbonImmutable::parse('2026-09-13'),
    );

    $attemptsBefore = SalesBoardAutomationAttempt::query()->count();

    RolloutFixture::returnToLegacy($scenario['emission']);

    SalesBoardAutomationTarget::query()->update(['next_attempt_at' => null]);

    app(SalesBoardAutomationService::class)->run(
        asOf: CarbonImmutable::parse('2026-09-14'),
    );

    // A Emissão saiu da elegibilidade: nenhum alvo dela é descoberto de novo.
    expect(SalesBoardAutomationAttempt::query()->count())->toBe($attemptsBefore);
});

it('requires a brand new homologation to reactivate', function () {
    $scenario = activatableEmission();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::returnToLegacy($scenario['emission']);

    // A homologação antiga já foi usada: os fatos mudaram desde então, e foi
    // por isso que a automação foi desligada.
    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'já foi usada numa ativação');

    $second = RolloutFixture::open($scenario['emission'], null, '2026-10-01');

    expect($second->attempt)->toBe(2)
        ->and($second->proposed_start_reference_month->format('Y-m'))->toBe('2026-10');
});

it('opens a draft builder review only when the homologation asked for it', function () {
    $scenario = activatableEmission(1);
    $homologation = RolloutFixture::approvedHomologation($scenario['emission'], autoOpen: true);

    $emission = RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    expect($emission->sales_board_auto_open_builder_review)->toBeTrue();

    app(SalesBoardAutomationService::class)->run(
        asOf: CarbonImmutable::parse('2026-09-13'),
    );

    $review = SalesBoardBuilderReview::query()->sole();

    expect($review->status->value)->toBe('em_andamento')
        ->and($review->sections)->toHaveCount(7)
        ->and($review->opened_by_user_id)->toBeNull()
        ->and($review->submitted_at)->toBeNull()
        ->and(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});
