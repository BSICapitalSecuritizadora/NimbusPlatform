<?php

use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Domain\PuCalculator\Services\PuObligationRefreshScheduler;
use App\Domain\PuCalculator\Services\PuOperationalFailureClassifier;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuObligationComponent;
use App\Models\EmissionPuSettlement;
use App\Models\Payment;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 (dívida D5) -- a atualização das obrigações pedida depois do commit é
 * durável: falha passageira volta sozinha, processo que morre entre o commit e a
 * primeira tentativa não perde o pedido, falha permanente não entra em laço, e a
 * repetição nunca duplica obrigação, cálculo, liquidação ou conciliação.
 *
 * Gatilho usado: a correção governada de um CDI já usado pela oficial
 * (`index_rate_corrected`), que marca o cupom de 09/03 para reprocessamento.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'pu_calculator.obligation_refresh.max_attempts' => 3,
        'pu_calculator.obligation_refresh.backoff_seconds' => [60, 300],
        'pu_calculator.obligation_refresh.scanner_grace_seconds' => 60,
        'pu_calculator.obligation_refresh.lease_seconds' => 900,
    ]);
});

/**
 * @return array{0: Emission, 1: EmissionPuObligation}
 */
function p6rScenario(): array
{
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);

    return [$emission->fresh(), Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')];
}

function p6rCorrect(): void
{
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.10000000', 'Revisão do BCB.', User::factory()->create()->id);
}

/**
 * Faz a próxima recomposição das obrigações falhar com a exceção informada, tantas
 * vezes quanto pedido (null = sempre).
 */
function p6rFailRefresh(Closure $exception, ?int $times = 1): void
{
    $remaining = $times;

    DB::listen(function ($query) use ($exception, &$remaining): void {
        // A leitura com trava das obrigações da emissão, no início da recomposição.
        if (! str_contains($query->sql, 'from "emission_pu_obligations" where "emission_id" = ? order by "id" asc')) {
            return;
        }

        if ($remaining !== null && $remaining <= 0) {
            return;
        }

        if ($remaining !== null) {
            $remaining--;
        }

        throw $exception();
    });
}

/**
 * @return array<string, int>
 */
function p6rFinancialCounts(): array
{
    return [
        'obligations' => EmissionPuObligation::query()->count(),
        'calculations' => EmissionPuObligationCalculation::query()->count(),
        'current_calculations' => EmissionPuObligationCalculation::query()->whereNull('superseded_at')->count(),
        'components' => EmissionPuObligationComponent::query()->count(),
        'settlements' => EmissionPuSettlement::query()->count(),
    ];
}

function p6rRecover(): array
{
    return app(PuObligationRefreshRecovery::class)->recoverDue();
}

it('retries a transient refresh failure on its own, without any unrelated future event, and converges without duplicates', function () {
    [$emission, $obligation] = p6rScenario();
    $settlement = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-D5')->settlement;
    p6rFailRefresh(fn () => new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));

    p6rCorrect();
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();

    // A tentativa logo depois do commit falhou de forma passageira: pedido guardado.
    expect($request->status)->toBe(PuObligationRefreshStatus::RetryScheduled)
        ->and($request->trigger)->toBe('index_rate_corrected')
        ->and($request->attempts)->toBe(1)
        ->and($request->last_error_category)->toBe(PuOperationalFailureCategory::TransientDatabase)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::Calculated);

    // Antes da espera, a varredura não repete.
    expect(p6rRecover())->toBe([]);

    $this->travel(61)->seconds();
    [$outcome] = p6rRecover();
    $converged = p6rFinancialCounts();
    $again = app(PuObligationRefreshRecovery::class)->process($emission->id, PuObligationRefreshRecovery::VIA_MANUAL);

    expect($outcome->status)->toBe(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($outcome->attempts)->toBe(2)
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate)
        // A liquidação é fato: nem valor nem situação mudam.
        ->and((string) $settlement->fresh()->amount)->toBe((string) $settlement->amount)
        ->and($settlement->fresh()->status)->toBe(PuSettlementStatus::Active)
        // Repetir não acrescenta nada.
        ->and($again->status)->toBe(PuObligationRefreshOutcome::NOTHING_TO_DO)
        ->and(p6rFinancialCounts())->toBe($converged)
        ->and(Activity::query()->where('event', 'obligation_refresh_failed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'obligation_refresh_recovered')->count())->toBe(1);
});

it('keeps the required refresh discoverable when the process dies between the commit and the first attempt', function () {
    [$emission, $obligation] = p6rScenario();
    // O processo morre depois do commit, antes da tentativa pós-commit.
    app()->scoped(PuObligationRefreshScheduler::class, fn () => new class(app(PuObligationRefreshRecovery::class)) extends PuObligationRefreshScheduler
    {
        protected function afterCommit(Closure $callback): void {}
    });
    app()->forgetScopedInstances();

    p6rCorrect();
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();

    expect($request->status)->toBe(PuObligationRefreshStatus::Pending)
        ->and($request->attempts)->toBe(0)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::Calculated);

    // Nem homologação nova, nem reparo manual: só a varredura, depois da carência.
    $this->travel(30)->seconds();
    expect(p6rRecover())->toBe([]);

    $this->travel(31)->seconds();
    $this->artisan('pu:obligations:recover')->assertSuccessful();

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($request->fresh()->claimed_via)->toBe(PuObligationRefreshRecovery::VIA_SCANNER)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($request->fresh()->result['emission_missing'])->toBeFalse();
});

it('blocks a permanent failure at once instead of retrying it, and lets only an authorized operator retry it', function () {
    [$emission, $obligation] = p6rScenario();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    p6rFailRefresh(fn () => new LogicException('An expected PU obligation calculation is immutable.'), null);

    p6rCorrect();
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();
    $this->travel(2)->hours();
    $scanned = p6rRecover();
    app(PuOperationalMonitor::class)->run('test');
    $incident = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->sole();

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Blocked)
        ->and($request->fresh()->attempts)->toBe(1)
        ->and($request->fresh()->last_error_category)->toBe(PuOperationalFailureCategory::Invariant)
        ->and($scanned)->toBe([])
        ->and($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->context['request_ids'])->toBe([$request->id])
        ->and(fn () => app(PuObligationRefreshRecovery::class)->retry($emission->id, $editor, 'Tentativa sem autoridade.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PuObligationRefreshRecovery::class)->retry($emission->id, $admin, ' '))->toThrow(InvalidArgumentException::class);

    // A causa foi tratada (o listener sai de cena com um app novo não é possível aqui:
    // a falha continua); a retomada autorizada registra a tentativa e volta a bloquear.
    $blockedAgain = app(PuObligationRefreshRecovery::class)->retry($emission->id, $admin, 'Imutabilidade investigada.');

    expect($blockedAgain->status)->toBe(PuObligationRefreshOutcome::BLOCKED)
        ->and(Activity::query()->where('event', 'obligation_refresh_retried')->sole()->causer_id)->toBe($admin->id)
        ->and(Activity::query()->where('event', 'obligation_refresh_retried')->sole()->properties['reason'])->toBe('Imutabilidade investigada.');
});

it('recovers a blocked refresh after the cause is gone through the authorized retry', function () {
    [$emission, $obligation] = p6rScenario();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    p6rFailRefresh(fn () => new PuCurveGovernanceException('Recusa de governança simulada.'), 1);

    p6rCorrect();
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();
    $outcome = app(PuObligationRefreshRecovery::class)->retry($emission->id, $admin, 'Versão revisada; repetir.');
    app(PuOperationalMonitor::class)->run('test');

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($outcome->status)->toBe(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and(PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->exists())->toBeFalse();
});

it('stops automatic retries at the configured limit and keeps the exhausted work actionable until a manual rebuild', function () {
    [$emission, $obligation] = p6rScenario();
    p6rFailRefresh(fn () => new DeadlockException('Deadlock found when trying to get lock'), 3);

    p6rCorrect();
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();
    $this->travel(61)->seconds();
    p6rRecover();
    $this->travel(301)->seconds();
    p6rRecover();
    $this->travel(3600)->seconds();
    $afterLimit = p6rRecover();
    app(PuOperationalMonitor::class)->run('test');
    $incident = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshExhausted->value)->sole();

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Exhausted)
        ->and($request->fresh()->attempts)->toBe(3)
        ->and($afterLimit)->toBe([])
        ->and($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->context['recovery'])->toBe('manual_retry_required')
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::Calculated);

    // A reconstrução manual dos fatos atende o pedido esgotado.
    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id]])->assertSuccessful();
    app(PuOperationalMonitor::class)->run('test');

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Superseded)
        ->and($request->fresh()->satisfied_by_request_id)->not->toBeNull()
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($incident->fresh()->resolved_at)->not->toBeNull();
});

it('reclaims a request whose worker died in the middle of the attempt as an interrupted attempt', function () {
    [$emission, $obligation] = p6rScenario();
    $request = app(PuObligationRefreshRecovery::class)->request($emission->id, 'official_curve_diverged');
    // Uma execução reservou e morreu: a concessão venceu.
    $request->forceFill([
        'status' => PuObligationRefreshStatus::Running,
        'claim_token' => (string) Str::uuid(),
        'claimed_via' => PuObligationRefreshRecovery::VIA_AFTER_COMMIT,
        'claimed_at' => now(),
        'claim_expires_at' => now()->addSeconds(900),
        'attempts' => 1,
    ])->save();

    $this->travel(10)->minutes();
    expect(p6rRecover())->toBe([]);

    $this->travel(6)->minutes();
    [$outcome] = p6rRecover();

    expect($outcome->status)->toBe(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($request->fresh()->attempts)->toBe(2)
        ->and($request->fresh()->last_error_category)->toBe(PuOperationalFailureCategory::Interrupted);
});

it('executes stale queued work against the current governed version, never replaying an obsolete expected value', function () {
    [$emission, $obligation] = p6rScenario();
    $v1Calculation = $obligation->fresh()->current_calculation_id;
    app()->scoped(PuObligationRefreshScheduler::class, fn () => new class(app(PuObligationRefreshRecovery::class)) extends PuObligationRefreshScheduler
    {
        protected function afterCommit(Closure $callback): void {}
    });
    app()->forgetScopedInstances();
    p6rCorrect();
    $stale = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();

    // Enquanto o pedido está parado, a versão nova é gerada e homologada.
    $v2 = Fx::homologate($emission, Fx::generate($emission->fresh()));
    $afterHomologation = p6rFinancialCounts();
    $this->travel(2)->minutes();
    [$outcome] = p6rRecover();

    expect($outcome->status)->toBe(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($outcome->result?->changedAnything())->toBeFalse()
        ->and($stale->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and(p6rFinancialCounts())->toBe($afterHomologation)
        ->and($obligation->fresh()->currentCalculation->curve_version_id)->toBe($v2->id)
        ->and($obligation->fresh()->current_calculation_id)->not->toBe($v1Calculation)
        ->and($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::Calculated);
});

it('records one durable request per emission and transaction, and none when the transaction rolls back', function () {
    [$emission] = p6rScenario();
    $before = PuObligationRefreshRequest::query()->count();

    DB::transaction(function () use ($emission): void {
        foreach (['2026-04-30', '2026-05-29', '2026-06-30'] as $date) {
            Payment::query()->create(['emission_id' => $emission->id, 'payment_date' => $date, 'premium_value' => '0.00', 'interest_value' => '0.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);
        }
    });

    expect(PuObligationRefreshRequest::query()->count())->toBe($before + 1)
        ->and(PuObligationRefreshRequest::query()->latest('id')->first()->status)->toBe(PuObligationRefreshStatus::Succeeded);

    try {
        DB::transaction(function () use ($emission): void {
            Payment::query()->create(['emission_id' => $emission->id, 'payment_date' => '2026-07-31', 'premium_value' => '0.00', 'interest_value' => '0.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);

            throw new RuntimeException('desfeita');
        });
    } catch (RuntimeException) {
    }

    expect(PuObligationRefreshRequest::query()->count())->toBe($before + 1);

    Payment::query()->create(['emission_id' => $emission->id, 'payment_date' => '2026-08-31', 'premium_value' => '0.00', 'interest_value' => '0.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);

    expect(PuObligationRefreshRequest::query()->count())->toBe($before + 2)
        ->and(PuObligationRefreshRequest::query()->open()->count())->toBe(0);
});

it('reports pending, retrying, exhausted and blocked work in the operational snapshot', function () {
    [$emission] = p6rScenario();
    $recovery = app(PuObligationRefreshRecovery::class);
    $pending = $recovery->request($emission->id, 'informed_schedule_changed');
    $this->travel(31)->minutes();
    $stalled = app(PuOperationalHealthService::class)->snapshot()->emission($emission->id);

    expect($stalled->refresh)->toBe([PuObligationRefreshStatus::Pending->value => 1])
        ->and(collect($stalled->conditions)->firstWhere('type', PuOperationalConditionType::ObligationRefreshStalled)?->severity)->toBe(PuOperationalSeverity::Warning);

    $pending->forceFill(['status' => PuObligationRefreshStatus::RetryScheduled, 'attempts' => 1, 'next_attempt_at' => now()->addMinute(), 'last_error_category' => PuOperationalFailureCategory::TransientDatabase])->save();
    $retrying = app(PuOperationalHealthService::class)->snapshot()->emission($emission->id);

    expect(collect($retrying->conditions)->firstWhere('type', PuOperationalConditionType::ObligationRefreshRetrying)?->severity)->toBe(PuOperationalSeverity::Warning);
});

it('classifies failures centrally: only transient ones are retryable, and stored messages carry no secrets', function () {
    $classifier = app(PuOperationalFailureClassifier::class);

    expect($classifier->classify(new DeadlockException('Deadlock found'))->isRetryable())->toBeTrue()
        ->and($classifier->classify(new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('UNIQUE constraint failed')))->isRetryable())->toBeTrue()
        ->and($classifier->classify(new LockTimeoutException('busy'))->isRetryable())->toBeTrue()
        ->and($classifier->classify(new PuCurveGovernanceException('recusa'))->isRetryable())->toBeFalse()
        ->and($classifier->classify(new PuCurveInputsException('insumo'))->isRetryable())->toBeFalse()
        ->and($classifier->classify(PuRateDomainException::nonPositiveCompoundingBase('-101', '-0.01')))->toBe(PuOperationalFailureCategory::Numerical)
        ->and($classifier->classify(new LogicException('imutável'))->isRetryable())->toBeFalse()
        ->and($classifier->classify(new RuntimeException('?'))->isRetryable())->toBeTrue()
        ->and($classifier->sanitize('Falha em https://user:s3cr3t@api.example/x token=abc123 Authorization: Bearer eyJhbGci password="p@ss"'))
        ->not->toContain('s3cr3t')
        ->not->toContain('abc123')
        ->not->toContain('eyJhbGci')
        ->not->toContain('p@ss');
});
