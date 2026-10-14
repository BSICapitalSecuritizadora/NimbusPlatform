<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Exceptions\PuGovernedHistoryProtectedException;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Domain\PuCalculator\Services\PuOperationalReadinessService;
use App\Domain\PuCalculator\Support\PuOperationsConfig;
use App\Enums\AccessPermission;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuHistoryAnchor;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use App\Models\Payment;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 7 -- ensaio do primeiro uso numa produção sem histórico de PU, num
 * ambiente isolado (SQLite em memória, dados sintéticos): o deploy não ativa
 * nada, a rotina não gera nada, a ativação é uma decisão autorizada, a primeira
 * curva passa por maker/checker, as obrigações e a liquidação seguem as Fases
 * 5-6, a correção histórica do CDI exige nova versão sem tocar a liquidação, a
 * recuperação retoma o trabalho e a suspensão para a geração sem apagar nada.
 */
uses(RefreshDatabase::class);

function p7fUser(array $permissions = []): User
{
    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo(array_map(fn (AccessPermission $permission): string => $permission->value, $permissions));
    }

    return $user->fresh();
}

/**
 * @return array<string, int>
 */
function p7fGovernedCounts(int $emissionId): array
{
    return [
        'versions' => EmissionPuCurveVersion::query()->where('emission_id', $emissionId)->count(),
        'rows' => EmissionPuDailyCurve::query()->where('emission_id', $emissionId)->count(),
        'obligations' => EmissionPuObligation::query()->where('emission_id', $emissionId)->count(),
        'calculations' => EmissionPuObligationCalculation::query()->where('emission_id', $emissionId)->count(),
        'settlements' => EmissionPuSettlement::query()->where('emission_id', $emissionId)->count(),
    ];
}

it('rehearses the first production use of a greenfield emission, from a disabled environment to suspension', function () {
    Activation::withoutAutomaticActivation();
    $maker = p7fUser([AccessPermission::PuCurveGenerate]);
    $checker = p7fUser([AccessPermission::PuCurveHomologate]);
    $activator = p7fUser([AccessPermission::PuOperationsActivate]);
    $policy = app(PuOperationalActivationPolicy::class);

    // 1. Operações do PU desligadas no ambiente (o padrão de um deploy novo).
    config([PuOperationsConfig::ENABLED_KEY => false]);

    // 2-3. Emissão ativa no cadastro, CDI configurado, cronograma contratual e
    // cronograma informado (prêmio de 15,00 em 20/03) -- PU não ativado.
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::InterestPayment, '2026-03-20'],
        [PuEventType::Amortization, '2026-03-20', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '100.0000000000000000', 'sequence' => 2]],
        [PuEventType::InterestPayment, '2026-04-09'],
    ]);
    Payment::query()->create(['emission_id' => $emission->id, 'payment_date' => '2026-03-20', 'premium_value' => '15.00', 'interest_value' => '1.00', 'amortization_value' => '2.00', 'extra_amortization_value' => '0.00']);

    expect($emission->status)->toBe('active')
        ->and($policy->status($emission))->toBe(PuOperationalActivationStatus::Inactive);

    // 4-5. A rotina diária roda (ambiente desligado e, depois, ligado sem ativação):
    // nenhuma curva de produção nasce.
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();
    config([PuOperationsConfig::ENABLED_KEY => true]);
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    expect(EmissionPuCurveVersion::query()->count())->toBe(0);

    // 6. A avaliação de prontidão diz o que falta: sem bloqueio, com pontos a decidir
    // (o calendário sintético do ensaio não tem ano confirmado) e a lista de
    // verificação contratual que a aprovação precisa cobrir. Nada autorizado ainda.
    $readiness = app(PuOperationalReadinessService::class)->assess($emission);

    expect($readiness->status)->toBe(PuOperationalReadinessStatus::ReadyForReview)
        ->and($readiness->blockers())->toBe([])
        ->and($readiness->reviewItems())->not->toBeEmpty()
        ->and($readiness->contractualChecklist)->not->toBeEmpty();

    // 7. Decisão autorizada de ativação (contexto operacional simulado do ensaio).
    app(PuOperationalActivationService::class)->activate($emission, $activator, 'Ensaio do primeiro uso.', 'ENSAIO-P7');

    expect($policy->status($emission))->toBe(PuOperationalActivationStatus::Activated);

    // 8. Geração da candidata pelo maker (job, como o botão da tela).
    GeneratePuDailyCurveJob::dispatch($emission->id, $maker->id);
    $v1 = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->sole();

    expect($v1->status)->toBe(PuCurveStatus::Generated)
        ->and($v1->generated_by)->toBe($maker->id);

    // 9. Validação registrada (comparação independente com o sistema de origem).
    app(PuCurveVersionService::class)->markValidated($v1, true, ['source' => 'comparação com o sistema antigo'], $maker->id);

    // 10. Maker/checker: quem gerou e validou não homologa; outra pessoa homologa.
    expect(fn () => app(HomologatePuCurve::class)->handle($emission->fresh(), $v1->calculation_version, $maker->id, 'Minha.'))
        ->toThrow(PuMakerCheckerException::class);

    app(HomologatePuCurve::class)->handle($emission->fresh(), $v1->calculation_version, $checker->id);

    expect($v1->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->value('curve_version_id'))->toBe($v1->id);

    // 11. O CDI realizado chega e a rotina estende a oficial.
    Fx::publish('2026-03-16', '2026-03-25');
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    expect(Fx::lastDate($v1->fresh()))->toBeGreaterThanOrEqual('2026-03-20');

    // 12-13. Obrigações financeiras e componentes esperados (prêmio informado à parte).
    $first = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $coupon = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-20');
    $calculation = $coupon->currentCalculation;

    expect($first->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($coupon->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($calculation->componentAmount(PuObligationComponent::OrdinaryAmortization))->toBe('10000.00')
        ->and($calculation->componentAmount(PuObligationComponent::Premium))->toBe('15.00');

    // 14-15. Liquidação igual ao esperado: conciliada.
    $firstExpected = Fx::expectedTotal($first);
    $firstSettlement = Fx::settle($first, $firstExpected, reference: 'ENSAIO-0309')->settlement;

    expect($first->fresh()->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($first->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);

    // 16-17. Divergência controlada (fechada, nunca parcial) detectada pelo monitor.
    $couponSettlement = Fx::settle($coupon, bcsub((string) $calculation->total_amount, '5.00', 2), reference: 'ENSAIO-0320')->settlement;
    app(PuOperationalMonitor::class)->run('ensaio', notify: false);

    expect($coupon->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $coupon->fresh()->latestReconciliation->difference)->toBe('-5.00')
        ->and(PuOperationalIncident::query()->where('type', PuOperationalConditionType::SettlementDivergent->value)->where('status', PuIncidentStatus::Active->value)->exists())->toBeTrue();

    // 18. Correção histórica do CDI: a oficial exige reprocessamento.
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.50000000', 'Revisão publicada pelo Banco Central.', $checker->id);

    expect($first->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired);

    // 19. Nova versão gerada pelo maker (reprocessamento confirmado) e homologada pelo checker.
    GeneratePuDailyCurveJob::dispatch($emission->id, $maker->id, true);
    $v2 = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('id', '>', $v1->id)->sole();
    app(HomologatePuCurve::class)->handle($emission->fresh(), $v2->calculation_version, $checker->id);

    // 20-21. A liquidação não muda; o esperado da v1 continua rastreável.
    expect($firstSettlement->fresh()->amount)->toBe($firstSettlement->amount)
        ->and($couponSettlement->fresh()->amount)->toBe($couponSettlement->amount)
        ->and(EmissionPuSettlement::query()->where('emission_id', $emission->id)->count())->toBe(2)
        ->and($first->fresh()->currentCalculation->curve_version_id)->toBe($v2->id)
        ->and($first->fresh()->calculations()->orderBy('id')->first()->curve_version_id)->toBe($v1->id)
        ->and((string) $first->fresh()->calculations()->orderBy('id')->first()->total_amount)->toBe($firstExpected);

    // 22. Recuperação: um pedido de atualização gravado e nunca executado (o processo
    // morreu depois do commit) é retomado pela varredura.
    $request = app(PuObligationRefreshRecovery::class)->request($emission->id, 'payment_schedule_changed');
    $request->forceFill(['next_attempt_at' => now()->subMinute()])->save();
    $outcomes = app(PuObligationRefreshRecovery::class)->recoverDue();

    expect(collect($outcomes)->pluck('status')->all())->toContain(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded);

    // 23. Suspensão: a rotina, o job e a extensão param; nada é apagado.
    $before = p7fGovernedCounts($emission->id);
    app(PuOperationalActivationService::class)->suspend($emission, $activator, 'Fim do ensaio.');
    Fx::publish('2026-03-26', '2026-04-03');
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();
    GeneratePuDailyCurveJob::dispatch($emission->id, $maker->id, true);

    expect(app(PuCurveExtensionService::class)->extendOfficial($emission->fresh())->action)->toBe(PuCurveExtensionService::ACTION_OPERATIONS_NOT_ACTIVE)
        ->and(fn () => app(HomologatePuCurve::class)->handle($emission->fresh(), $v2->calculation_version, $checker->id))->toThrow(PuOperationalActivationException::class)
        // 24. O histórico governado continua inteiro e protegido.
        ->and(p7fGovernedCounts($emission->id))->toBe($before)
        ->and(fn () => $emission->fresh()->delete())->toThrow(PuGovernedHistoryProtectedException::class);
});
