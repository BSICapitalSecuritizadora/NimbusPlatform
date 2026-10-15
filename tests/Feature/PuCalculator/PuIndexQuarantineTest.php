<?php

use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexAnomalyType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateQuarantineReviewService;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Enums\AccessPermission;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Filament\Widgets\IndexRates\IndexSyncOverview;
use App\Filament\Widgets\IndexRates\PuIndexQuarantineWidget;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\IndexRateQuarantine;
use App\Models\PuIndexSyncAttempt;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8.1 (NEW-02): a observação que a sincronização recebeu e não aceitou fica
 * em quarentena -- evidência auditável, nunca índice realizado.
 *
 * Fx::emission() publica CDI de 27/02 a 13/03 (14,90% a.a.); 16/03 é a primeira
 * data sem observação, e um 149,00 nela é dez vezes a anterior (unidade errada).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'pu_indexes.bcb.chunk_months' => 12,
        'pu_indexes.bcb.retries' => 1,
        'pu_indexes.bcb.retry_sleep_ms' => 0,
    ]);
    Http::preventStrayRequests();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, string>  $observations  data (Y-m-d) => valor como o SGS informa
 * @param  list<array<string, mixed>>  $extra  entradas cruas adicionais
 */
function p81Sync(array $observations, array $extra = [], string $from = '2026-03-02', string $to = '2026-03-16', ?string $policy = null): IndexRateSyncResult
{
    $payload = [
        ...collect($observations)->map(fn (string $value, string $date): array => ['data' => CarbonImmutable::parse($date)->format('d/m/Y'), 'valor' => $value])->values()->all(),
        ...$extra,
    ];
    // O primeiro stub registrado vence no Http::fake: ele lê a resposta da vez.
    $GLOBALS['p81Payload'] = $payload;
    Http::fake(['api.bcb.gov.br/*' => fn () => Http::response($GLOBALS['p81Payload'])]);

    return app(IndexRateSyncService::class)->sync(PuIndexer::Cdi, CarbonImmutable::parse($from), CarbonImmutable::parse($to), overwritePolicy: $policy);
}

/**
 * @param  list<AccessPermission>  $permissions
 */
function p81User(array $permissions = [AccessPermission::PuIndexImport, AccessPermission::PuIndexCorrect]): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(array_map(fn (AccessPermission $permission): string => $permission->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function p81Cdi(string $date): ?IndexRate
{
    return IndexRate::query()->where('indexer', 'CDI')->whereDate('rate_date', $date)->first();
}

function p81Review(): IndexRateQuarantineReviewService
{
    return app(IndexRateQuarantineReviewService::class);
}

it('accepts a plausible synchronized observation through the existing pipeline and retains nothing', function () {
    Fx::emission();

    $result = p81Sync(['2026-03-16' => '14.95']);

    expect((string) p81Cdi('2026-03-16')?->rate_value)->toBe('14.95000000')
        ->and($result->quarantined)->toBe(0)
        ->and(IndexRateQuarantine::query()->count())->toBe(0);
});

it('retains an implausible synchronized observation as review evidence without recording it', function () {
    Fx::emission();

    $result = p81Sync(['2026-03-16' => '149.00']);
    $attempt = PuIndexSyncAttempt::query()->sole();
    $retained = IndexRateQuarantine::query()->sole();

    expect(p81Cdi('2026-03-16'))->toBeNull()
        ->and($result->quarantined)->toBe(1)
        ->and($retained->status)->toBe(PuIndexQuarantineStatus::PendingReview)
        ->and($retained->anomaly_type)->toBe(PuIndexAnomalyType::ImplausibleJump)
        ->and($retained->indexer)->toBe('CDI')
        ->and($retained->rate_date->toDateString())->toBe('2026-03-16')
        ->and($retained->source)->toBe('bcb_sgs')
        ->and($retained->external_series_code)->toBe('4389')
        ->and((string) $retained->received_value)->toBe('149.00000000')
        ->and($retained->raw_value)->toBe('149.00')
        ->and((string) $retained->reference_value)->toBe('14.90000000')
        ->and($retained->reference_date->toDateString())->toBe('2026-03-13')
        ->and($retained->reason)->toContain('149.00000000', '10x')
        ->and($retained->first_sync_attempt_id)->toBe($attempt->id)
        ->and($retained->last_sync_attempt_id)->toBe($attempt->id)
        ->and($retained->first_detected_at)->not->toBeNull();
});

it('never lets the realized index readers or the official curve use a retained value', function () {
    $emission = Fx::emission();
    $official = Fx::official($emission);
    $lastBefore = Fx::lastDate($official);

    p81Sync(['2026-03-16' => '149.00']);
    app(IndexRateLookupService::class)->flushCache();
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect(app(IndexRateLookupService::class)->realizedRateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-03-16')))->toBeNull()
        ->and(app(IndexRateLookupService::class)->latestRealizedRateDate(PuIndexer::Cdi)?->toDateString())->toBe('2026-03-13')
        ->and(Fx::lastDate($official->fresh()))->toBe($lastBefore)
        ->and(DB::table('emission_pu_daily_curves')->whereDate('index_rate_date', '2026-03-16')->exists())->toBeFalse();
});

it('does not flood the queue when the same anomaly comes back on every synchronization', function () {
    Fx::emission();

    p81Sync(['2026-03-16' => '149.00']);
    p81Sync(['2026-03-16' => '149.00']);
    p81Sync(['2026-03-16' => '149.00']);
    [$first, , $third] = PuIndexSyncAttempt::query()->orderBy('id')->get()->all();
    $retained = IndexRateQuarantine::query()->sole();

    expect($retained->occurrences)->toBe(3)
        ->and($retained->first_sync_attempt_id)->toBe($first->id)
        ->and($retained->last_sync_attempt_id)->toBe($third->id)
        ->and($retained->isPending())->toBeTrue();
});

it('keeps a changed provider value as a new anomaly and supersedes the previous response', function () {
    Fx::emission();

    p81Sync(['2026-03-16' => '149.00']);
    p81Sync(['2026-03-16' => '1.49']);
    [$old, $new] = IndexRateQuarantine::query()->orderBy('id')->get()->all();

    expect($old->status)->toBe(PuIndexQuarantineStatus::Superseded)
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and((string) $old->received_value)->toBe('149.00000000')
        ->and($new->isPending())->toBeTrue()
        ->and((string) $new->received_value)->toBe('1.49000000')
        ->and(p81Cdi('2026-03-16'))->toBeNull();
});

it('retains a value that diverges from the accepted history and leaves the accepted value untouched', function () {
    Fx::emission();

    p81Sync(['2026-03-05' => '15.40'], policy: IndexRateSyncService::POLICY_SKIP);
    $retained = IndexRateQuarantine::query()->sole();

    expect($retained->anomaly_type)->toBe(PuIndexAnomalyType::ValueConflict)
        ->and((string) $retained->received_value)->toBe('15.40000000')
        ->and((string) $retained->reference_value)->toBe('14.90000000')
        ->and((string) p81Cdi('2026-03-05')->rate_value)->toBe('14.90000000')
        ->and(IndexRateCorrection::query()->count())->toBe(0);

    // A fonte voltou a concordar com o registrado: a divergência deixa de aguardar revisão.
    p81Sync(['2026-03-05' => '14.90'], policy: IndexRateSyncService::POLICY_SKIP);

    expect($retained->fresh()->status)->toBe(PuIndexQuarantineStatus::Superseded)
        ->and($retained->fresh()->resulting_index_rate_id)->toBe(p81Cdi('2026-03-05')->id);
});

it('never applies an implausible provider revision on its own, even with the update policy', function () {
    Fx::emission();

    $result = p81Sync(['2026-03-05' => '1.49'], policy: IndexRateSyncService::POLICY_UPDATE);
    $retained = IndexRateQuarantine::query()->sole();

    expect((string) p81Cdi('2026-03-05')->rate_value)->toBe('14.90000000')
        ->and($result->updated)->toBe(0)
        ->and(IndexRateCorrection::query()->count())->toBe(0)
        ->and($retained->anomaly_type)->toBe(PuIndexAnomalyType::ImplausibleJump)
        ->and((string) $retained->reference_value)->toBe('14.90000000');

    // A revisão plausível segue o caminho governado de antes e encerra a pendência da data.
    p81Sync(['2026-03-05' => '15.00'], policy: IndexRateSyncService::POLICY_UPDATE);

    expect((string) p81Cdi('2026-03-05')->rate_value)->toBe('15.00000000')
        ->and(IndexRateCorrection::query()->sole()->origin)->toBe(IndexRateCorrection::ORIGIN_PROVIDER_REVISION)
        ->and($retained->fresh()->status)->toBe(PuIndexQuarantineStatus::Superseded)
        ->and($retained->fresh()->resulting_correction_id)->toBe(IndexRateCorrection::query()->sole()->id);
});

it('approves a retained observation for a missing date through the observation recorder', function () {
    $emission = Fx::emission();
    $official = Fx::official($emission);
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $reviewer = p81User();

    $decided = p81Review()->approve($reviewer, $retained->id, 'Conferido no SGS em 17/03: valor publicado.', null);
    $rate = p81Cdi('2026-03-16');
    $activity = Activity::query()->where('event', 'index_observation_reviewed')->sole();

    expect($decided->status)->toBe(PuIndexQuarantineStatus::Approved)
        ->and($decided->decided_by)->toBe($reviewer->id)
        ->and($decided->decision_reason)->toBe('Conferido no SGS em 17/03: valor publicado.')
        ->and($decided->resulting_index_rate_id)->toBe($rate->id)
        ->and($decided->resulting_correction_id)->toBeNull()
        ->and((string) $rate->rate_value)->toBe('149.00000000')
        ->and($rate->source)->toBe('bcb_sgs')
        ->and($rate->source_reference)->toBe('quarantine:'.$retained->id)
        ->and($rate->notes)->toContain('Aprovada na revisão da quarentena #'.$retained->id)
        ->and($activity->causer_id)->toBe($reviewer->id)
        ->and($activity->properties['decision'])->toBe('approved')
        ->and($activity->properties['received_value'])->toBe('149.00000000');

    // Só depois da aprovação governada a extensão diária pode usar o valor.
    app(IndexRateLookupService::class)->flushCache();
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect(DB::table('emission_pu_daily_curves')->where('curve_version_id', $official->id)->whereDate('index_rate_date', '2026-03-16')->exists())->toBeTrue();
});

it('approves a divergent value only through the governed correction and flags the official curve for reprocessing', function () {
    $emission = Fx::emission();
    $official = Fx::official($emission);
    $homologatedRow = (string) Fx::row($official, '2026-03-06')->residual_unit_value;
    p81Sync(['2026-03-05' => '15.40'], policy: IndexRateSyncService::POLICY_SKIP);
    $retained = IndexRateQuarantine::query()->sole();

    $decided = p81Review()->approve(p81User(), $retained->id, 'BCB republicou a Taxa DI de 05/03.', '14.90000000');
    $correction = IndexRateCorrection::query()->sole();

    expect($decided->status)->toBe(PuIndexQuarantineStatus::Approved)
        ->and($decided->resulting_correction_id)->toBe($correction->id)
        ->and((string) $correction->previous_rate_value)->toBe('14.90000000')
        ->and((string) $correction->new_rate_value)->toBe('15.40000000')
        ->and($correction->reason)->toContain('observação retida #'.$retained->id, 'BCB republicou')
        ->and($correction->new_source_reference)->toBe('quarantine:'.$retained->id)
        ->and((string) p81Cdi('2026-03-05')->rate_value)->toBe('15.40000000')
        ->and($official->fresh()->extension_diverged_at)->not->toBeNull()
        ->and($official->fresh()->extension_divergence['index_rate_correction_id'])->toBe($correction->id)
        // A linha homologada não muda: o novo valor só chega ao oficial por nova versão.
        ->and((string) Fx::row($official, '2026-03-06')->residual_unit_value)->toBe($homologatedRow);
});

it('rejects with the evidence preserved and does not put the same response back in the queue', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $reviewer = p81User();

    p81Review()->reject($reviewer, $retained->id, 'Erro de unidade da fonte; aguardar republicação.');
    p81Sync(['2026-03-16' => '149.00']);
    $rejected = IndexRateQuarantine::query()->sole();

    expect($rejected->status)->toBe(PuIndexQuarantineStatus::Rejected)
        ->and($rejected->decided_by)->toBe($reviewer->id)
        ->and($rejected->decision_reason)->toBe('Erro de unidade da fonte; aguardar republicação.')
        ->and($rejected->occurrences)->toBe(2)
        ->and((string) $rejected->received_value)->toBe('149.00000000')
        ->and(IndexRateQuarantine::query()->pending()->count())->toBe(0)
        ->and(p81Cdi('2026-03-16'))->toBeNull();
});

it('refuses decisions from whoever lacks pu.index.correct or is not active', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $inactive = p81User();
    $inactive->forceFill(['is_active' => false])->save();

    expect(fn () => p81Review()->approve(p81User([AccessPermission::PuIndexImport, AccessPermission::PuIndexSync]), $retained->id, 'x', null))->toThrow(AuthorizationException::class)
        ->and(fn () => p81Review()->reject(p81User([AccessPermission::PuIndexImport]), $retained->id, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => p81Review()->approve($inactive->fresh(), $retained->id, 'x', null))->toThrow(AuthorizationException::class)
        ->and($retained->fresh()->isPending())->toBeTrue()
        ->and(p81Cdi('2026-03-16'))->toBeNull();

    $this->actingAs(p81User([AccessPermission::PuIndexImport]));

    Livewire::test(PuIndexQuarantineWidget::class)
        ->assertCanSeeTableRecords([$retained])
        ->assertActionHidden(TestAction::make('approveQuarantine')->table($retained))
        ->assertActionHidden(TestAction::make('rejectQuarantine')->table($retained));
});

it('refuses stale decisions', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $reviewer = p81User();

    // Reason vazio não decide nada.
    expect(fn () => p81Review()->approve($reviewer, $retained->id, '   ', null))->toThrow(InvalidArgumentException::class, 'motivo');

    // Outra pessoa registrou a data enquanto a revisão estava aberta.
    IndexRate::query()->create(['indexer' => 'CDI', 'rate_date' => '2026-03-16', 'rate_value' => '14.95000000', 'source' => 'manual_import', 'is_projected' => false]);
    expect(fn () => p81Review()->approve($reviewer, $retained->id, 'Conferido.', null))->toThrow(InvalidArgumentException::class, 'mudou desde que a revisão foi aberta');

    // Já decidida: a segunda decisão é recusada.
    p81Review()->reject($reviewer, $retained->id, 'Erro da fonte.');
    expect(fn () => p81Review()->approve($reviewer, $retained->id, 'Conferido.', '14.95000000'))->toThrow(InvalidArgumentException::class, 'já foi encerrada')
        ->and(fn () => p81Review()->reject($reviewer, $retained->id, 'De novo.'))->toThrow(InvalidArgumentException::class, 'já foi encerrada')
        ->and((string) p81Cdi('2026-03-16')->rate_value)->toBe('14.95000000');

    // Superada por outra resposta da fonte.
    p81Sync(['2026-03-17' => '150.00'], to: '2026-03-17');
    $first = IndexRateQuarantine::query()->whereDate('rate_date', '2026-03-17')->sole();
    p81Sync(['2026-03-17' => '1.40'], to: '2026-03-17');

    expect(fn () => p81Review()->approve($reviewer, $first->id, 'Conferido.', null))->toThrow(InvalidArgumentException::class, 'já foi encerrada');
});

it('cannot approve what has no approvable value', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '-150.00'], extra: [['data' => '17/03/2026', 'valor' => 'abc'], ['data' => 'ontem', 'valor' => '1']], to: '2026-03-17');
    $domain = IndexRateQuarantine::query()->where('anomaly_type', PuIndexAnomalyType::DomainRejected->value)->sole();
    $malformed = IndexRateQuarantine::query()->where('anomaly_type', PuIndexAnomalyType::MalformedEntry->value)->orderBy('id')->get();

    expect($domain->received_value)->toBeNull()
        ->and($domain->raw_value)->toBe('-150.00')
        ->and($domain->rate_date->toDateString())->toBe('2026-03-16')
        ->and($malformed)->toHaveCount(2)
        ->and($malformed[0]->rate_date?->toDateString())->toBe('2026-03-17')
        ->and($malformed[0]->raw_value)->toBe('{"data":"17/03/2026","valor":"abc"}')
        ->and($malformed[1]->rate_date)->toBeNull()
        ->and(fn () => p81Review()->approve(p81User(), $domain->id, 'Conferido.', null))->toThrow(InvalidArgumentException::class, 'não tem valor aprovável')
        ->and(fn () => p81Review()->approve(p81User(), $malformed[0]->id, 'Conferido.', null))->toThrow(InvalidArgumentException::class, 'não tem valor aprovável')
        ->and(IndexRate::query()->whereDate('rate_date', '>=', '2026-03-16')->count())->toBe(0);

    // Reencontradas na próxima consulta, continuam uma linha cada.
    p81Sync(['2026-03-16' => '-150.00'], extra: [['data' => '17/03/2026', 'valor' => 'abc'], ['data' => 'ontem', 'valor' => '1']], to: '2026-03-17');

    expect(IndexRateQuarantine::query()->count())->toBe(3)
        ->and($domain->fresh()->occurrences)->toBe(2);

    $this->actingAs(p81User());

    Livewire::test(PuIndexQuarantineWidget::class)
        ->assertActionHidden(TestAction::make('approveQuarantine')->table($domain))
        ->assertActionVisible(TestAction::make('rejectQuarantine')->table($domain));
});

it('resolves the retained observation when the governed import records the date', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $csv = temporaryTestFilePath('p81-importacao', 'csv');
    file_put_contents($csv, "rate_date,rate_value\n2026-03-16,14.95\n");

    app(IndexRateImportService::class)->importPublished(PuIndexer::Cdi, $csv, 'manual_import', p81User()->id);

    expect($retained->fresh()->status)->toBe(PuIndexQuarantineStatus::Superseded)
        ->and($retained->fresh()->resulting_index_rate_id)->toBe(p81Cdi('2026-03-16')->id)
        ->and((string) p81Cdi('2026-03-16')->rate_value)->toBe('14.95000000');
});

it('decides through the queue table of the index page with the governed service', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00', '2026-03-17' => '0.149'], to: '2026-03-17');
    [$approve, $reject] = IndexRateQuarantine::query()->orderBy('rate_date')->get()->all();
    $this->actingAs($reviewer = p81User());

    Livewire::test(PuIndexQuarantineWidget::class)
        ->assertCanSeeTableRecords([$approve, $reject])
        ->callAction(TestAction::make('approveQuarantine')->table($approve), data: ['reason' => 'Conferido no SGS.', 'confirm' => true])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('rejectQuarantine')->table($reject), data: ['reason' => 'Erro de unidade.'])
        ->assertHasNoActionErrors();

    expect($approve->fresh()->status)->toBe(PuIndexQuarantineStatus::Approved)
        ->and($approve->fresh()->decided_by)->toBe($reviewer->id)
        ->and((string) p81Cdi('2026-03-16')->rate_value)->toBe('149.00000000')
        ->and($reject->fresh()->status)->toBe(PuIndexQuarantineStatus::Rejected)
        ->and(p81Cdi('2026-03-17'))->toBeNull();

    // Decidida sai da fila padrão (pendentes) e aparece com a situação certa no filtro.
    Livewire::test(PuIndexQuarantineWidget::class)
        ->assertCanNotSeeTableRecords([$approve, $reject])
        ->filterTable('status', PuIndexQuarantineStatus::Rejected->value)
        ->assertCanSeeTableRecords([$reject])
        ->assertTableColumnFormattedStateSet('status', 'Recusada', $reject)
        ->filterTable('status', PuIndexQuarantineStatus::Approved->value)
        ->assertCanSeeTableRecords([$approve])
        ->assertTableColumnFormattedStateSet('status', 'Aprovada', $approve);
});

it('refuses an approval from the table when the registered value changed while the form was open', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();
    $this->actingAs(p81User());

    $table = Livewire::test(PuIndexQuarantineWidget::class)
        ->mountAction(TestAction::make('approveQuarantine')->table($retained));
    IndexRate::query()->create(['indexer' => 'CDI', 'rate_date' => '2026-03-16', 'rate_value' => '14.95000000', 'source' => 'manual_import', 'is_projected' => false]);
    $table->setActionData(['reason' => 'Conferido.', 'confirm' => true])->callMountedAction();

    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->map(fn ($n) => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');

    expect($body)->toContain('Aprovação não registrada', 'mudou desde que a revisão foi aberta')
        ->and($retained->fresh()->isPending())->toBeTrue()
        ->and((string) p81Cdi('2026-03-16')->rate_value)->toBe('14.95000000');
});

it('shows the retained observation on the index page review and summary without presenting the index as healthy', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $this->actingAs(p81User());

    Livewire::test(ListIndexRates::class)
        ->mountAction('observationReview')
        ->assertMountedActionModalSee(['Observações retidas da sincronização (1 aguardando revisão)', 'CDI 16/03/2026', '149,00000000', '14,90000000', 'Salto suspeito (possível erro de unidade)']);

    Livewire::test(IndexSyncOverview::class)
        ->assertSee('Revisão pendente')
        ->assertSee('retida(s) para revisão')
        ->assertDontSee('Sincronizado');

    Livewire::test(PuIndexQuarantineWidget::class)
        ->assertCanSeeTableRecords(IndexRateQuarantine::query()->get())
        ->assertSee(['149,00000000', 'Fonte: 149.00', 'Aguardando revisão']);
});

it('keeps the review evidence through the activity log cleanup', function () {
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00', '2026-03-17' => '0.149'], to: '2026-03-17');
    [$approve, $reject] = IndexRateQuarantine::query()->orderBy('rate_date')->get()->all();
    p81Review()->approve(p81User(), $approve->id, 'Conferido.', null);
    p81Review()->reject(p81User(), $reject->id, 'Erro de unidade.');
    DB::table('activity_log')->update(['created_at' => now()->subDays((int) config('audit.retention_workflow_days') + 30)]);

    $this->artisan('audit:clean-filtered')->assertSuccessful();

    expect(IndexRateQuarantine::query()->count())->toBe(2)
        ->and($approve->fresh()->decision_reason)->toBe('Conferido.')
        ->and($approve->fresh()->resulting_index_rate_id)->not->toBeNull()
        ->and($reject->fresh()->decision_reason)->toBe('Erro de unidade.')
        ->and(config('audit.protected_events.pu-calculation'))->toContain('index_observation_reviewed')
        ->and(fn () => $approve->fresh()->delete())->toThrow(LogicException::class);
});

it('does not accept the observation when the evidence cannot be stored', function () {
    Fx::emission();
    // A gravação da evidência falha no meio da sincronização (banco recusa a linha).
    DB::unprepared("CREATE TRIGGER p81_quarantine_down BEFORE INSERT ON index_rate_quarantines BEGIN SELECT RAISE(ABORT, 'disco cheio'); END;");

    $result = p81Sync(['2026-03-16' => '149.00', '2026-03-13' => '14.90']);

    expect(p81Cdi('2026-03-16'))->toBeNull()
        ->and(IndexRateQuarantine::query()->count())->toBe(0)
        ->and($result->quarantined)->toBe(0)
        ->and(implode(' ', $result->errors))->toContain('não pôde ser guardada para revisão', 'disco cheio')
        ->and(PuIndexSyncAttempt::query()->sole()->finished_at)->not->toBeNull();
});

it('reports the retained observation to the monitor and keeps the missing CDI missing', function () {
    // Sem emissão em operação (produção antes do lançamento): visível, só informativo.
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    [$quiet] = app(PuOperationalHealthService::class)->snapshot()->conditionsOfType(PuOperationalConditionType::IndexObservationPendingReview);

    expect($quiet->severity)->toBe(PuOperationalSeverity::Info)
        ->and($quiet->context['pending_review'])->toBe(1);

    // Com curva oficial de CDI, depois da divulgação esperada e da consulta à fonte.
    $emission = Fx::emission();
    Fx::official($emission);
    $this->travelTo(CarbonImmutable::parse('2026-03-17 08:00', 'America/Sao_Paulo'));
    p81Sync(['2026-03-16' => '149.00'], to: '2026-03-17');
    app(PuOperationalMonitor::class)->run('test');
    $pending = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::IndexObservationPendingReview->value)->sole();
    $missing = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::IndexObservationMissing->value)->sole();

    expect($pending->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($pending->reason)->toContain('1 observação(ões) de CDI', '16/03/2026', 'Nenhuma vale como índice realizado')
        ->and($missing->reason)->toContain('O CDI de 16/03/2026', 'entregou 149.00000000', 'ficou retido')
        ->and(p81Cdi('2026-03-16'))->toBeNull();

    // A mesma anomalia na próxima consulta não abre outro incidente.
    p81Sync(['2026-03-16' => '149.00'], to: '2026-03-17');
    app(PuOperationalMonitor::class)->run('test');

    expect(PuOperationalIncident::query()->where('type', PuOperationalConditionType::IndexObservationPendingReview->value)->count())->toBe(1);
});

it('keeps synchronization, quarantine and review working with global PU operations off, without touching curves', function () {
    config(['pu_calculator.operations.enabled' => false]);
    Fx::emission();
    p81Sync(['2026-03-16' => '149.00']);
    $retained = IndexRateQuarantine::query()->sole();

    p81Review()->approve(p81User(), $retained->id, 'Conferido no SGS.', null);
    $this->artisan('pu:curves:generate-realized');

    expect((string) p81Cdi('2026-03-16')->rate_value)->toBe('149.00000000')
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0);
});
