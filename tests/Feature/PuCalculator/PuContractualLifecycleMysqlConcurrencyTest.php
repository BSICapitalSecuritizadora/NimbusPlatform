<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;

/**
 * Fase 4 -- mudança contratual disputando o MySQL real com a extensão, a geração e
 * a homologação da curva.
 *
 * O SQLite serializa escritores e não mostra a janela entre ler e gravar. Aqui um
 * processo segura a transação aberta logo depois de uma consulta escolhida, e o
 * outro age no meio. Quem decide é a releitura dos insumos sob trava
 * compartilhada dentro da transação de quem grava a curva: a mudança concorrente
 * ou comitou antes (e é vista: nada é gravado sob um fingerprint obsoleto) ou
 * espera o commit (e é vista na próxima rodada).
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar processos disputando os insumos contratuais da curva.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Emissão de CDI (defasagem de 1 dia útil) com cupom em 09/03 e CDI até 13/03.
 *
 * @return array{emission: int, event: int}
 */
function p4mEmission(): array
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI', 'issued_quantity' => 1000]);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => false,
    ]);
    $event = EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => '2026-03-09',
        'effective_date' => '2026-03-09',
        'amortization_type' => PuAmortizationType::None->value,
        'sequence' => 1,
    ]);

    for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2027-01-08')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    p4mPublish('2026-02-27', '2026-03-13');

    return ['emission' => (int) $emission->getKey(), 'event' => (int) $event->getKey()];
}

/**
 * Curva homologada realizada até 16/03, com CDI publicado até 18/03 à espera da
 * extensão.
 *
 * @return array{emission: int, event: int, official: int}
 */
function p4mOfficialScenario(): array
{
    $scenario = p4mEmission();
    $emission = Emission::query()->findOrFail($scenario['emission']);
    $result = app(GeneratePuDailyCurve::class)->handle($emission);
    $official = app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Conferida.');
    p4mPublish('2026-03-16', '2026-03-18');

    return [...$scenario, 'official' => (int) $official->getKey()];
}

function p4mPublish(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if (! $date->isWeekend()) {
            IndexRate::query()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->startOfDay(),
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'is_projected' => false,
            ]);
        }
    }
}

/**
 * Processo filho. Com `hold`, segura a conexão por 800 ms logo depois da
 * `occurrence`-ésima consulta que contiver todos os trechos de `hold_on` e nenhum
 * de `skip_on`, e avisa o outro pelo marcador. Sem `hold`, espera o marcador e só
 * então age. Ações de escrita rodam numa transação: a espera acontece com ela
 * aberta.
 *
 * @param  array{action: string, hold: bool, hold_on: list<string>, skip_on: list<string>, occurrence: int, marker: string, payload: array<string, mixed>}  $instruction
 */
function p4mTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['hold']) {
                $seen = 0;
                $held = false;

                DB::listen(static function (QueryExecuted $query) use ($instruction, &$seen, &$held): void {
                    $sql = strtolower($query->sql);

                    if ($held) {
                        return;
                    }

                    foreach ($instruction['hold_on'] as $fragment) {
                        if (! str_contains($sql, $fragment)) {
                            return;
                        }
                    }

                    foreach ($instruction['skip_on'] as $fragment) {
                        if (str_contains($sql, $fragment)) {
                            return;
                        }
                    }

                    if (++$seen < $instruction['occurrence']) {
                        return;
                    }

                    $held = true;
                    file_put_contents($instruction['marker'], 'held');
                    usleep(800_000);
                });
            } else {
                $deadline = microtime(true) + 15;

                while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['marker'])) {
                    throw new RuntimeException('O processo concorrente não chegou ao ponto de espera.');
                }
            }

            $payload = $instruction['payload'];

            $outcome = match ($instruction['action']) {
                'extend_official' => app(PuCurveExtensionService::class)
                    ->extendOfficial(Emission::query()->findOrFail($payload['emission']))
                    ->action,
                'generate' => (string) app(GeneratePuDailyCurve::class)
                    ->handle(Emission::query()->findOrFail($payload['emission']))
                    ->calculationVersion,
                'homologate' => app(HomologatePuCurve::class)
                    ->handle(Emission::query()->findOrFail($payload['emission']), $payload['version'], $payload['actor'], 'Conferida.')
                    ->status->value,
                'move_event' => DB::transaction(static function () use ($payload): string {
                    EmissionPuEvent::query()->findOrFail($payload['event'])->update([
                        'original_date' => $payload['date'],
                        'effective_date' => $payload['date'],
                    ]);

                    return 'moved';
                }),
                'change_spread' => DB::transaction(static function () use ($payload): string {
                    EmissionPuParameter::query()->where('emission_id', $payload['emission'])->firstOrFail()->update([
                        'spread_rate' => $payload['spread'],
                    ]);

                    return 'changed';
                }),
                'create_event' => DB::transaction(static function () use ($payload): string {
                    EmissionPuEvent::query()->create([
                        'emission_id' => $payload['emission'],
                        'event_type' => $payload['type'],
                        'original_date' => $payload['date'],
                        'effective_date' => $payload['date'],
                        'amortization_type' => $payload['amortization_type'],
                        'amortization_value' => $payload['amortization_value'] ?? null,
                        'sequence' => $payload['sequence'],
                    ]);

                    return 'created';
                }),
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array{action: string, hold: bool, hold_on?: list<string>, skip_on?: list<string>, occurrence?: int, payload: array<string, mixed>}>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}>
 */
function p4mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-contractual-race-'.getmypid(), 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $task): Closure => p4mTask([
            ...$task,
            'hold_on' => $task['hold_on'] ?? [],
            'skip_on' => $task['skip_on'] ?? [],
            'occurrence' => $task['occurrence'] ?? 1,
            'marker' => $marker,
        ]),
        $tasks,
    ));

    @unlink($marker);

    return array_values($results);
}

function p4mLast(int $versionId): string
{
    return CarbonImmutable::parse((string) EmissionPuDailyCurve::query()->where('curve_version_id', $versionId)->max('curve_date'))->toDateString();
}

it('appends nothing under an obsolete fingerprint when a retroactive event change commits first', function () {
    $scenario = p4mOfficialScenario();

    $results = p4mRace([
        ['action' => 'move_event', 'hold' => true, 'hold_on' => ['update', 'emission_pu_events'], 'payload' => ['event' => $scenario['event'], 'date' => '2026-03-10']],
        ['action' => 'extend_official', 'hold' => false, 'payload' => ['emission' => $scenario['emission']]],
    ]);

    $official = EmissionPuCurveVersion::query()->findOrFail($scenario['official']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe('moved')
        // A extensão esperou a edição comitar, releu os insumos e não anexou nada.
        ->and($results[1]['outcome'])->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and(p4mLast($official->id))->toBe('2026-03-16')
        ->and($official->extension_diverged_at)->not->toBeNull()
        ->and($official->extension_divergence['first_divergent_date'])->toBe('2026-03-09')
        ->and($official->status)->toBe(PuCurveStatus::Homologated);
})->group('mysql');

it('appends under the approved fingerprint and leaves a concurrent change to the next round when the extension locks first', function () {
    $scenario = p4mOfficialScenario();

    $results = p4mRace([
        ['action' => 'extend_official', 'hold' => true, 'hold_on' => ['emission_pu_events', 'lock in share mode'], 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'move_event', 'hold' => false, 'payload' => ['event' => $scenario['event'], 'date' => '2026-03-10']],
    ]);

    $official = EmissionPuCurveVersion::query()->findOrFail($scenario['official']);
    $rowsAfterRace = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->count();
    $next = app(PuCurveExtensionService::class)->extendOfficial(Emission::query()->findOrFail($scenario['emission']));

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        // Os dias anexados foram calculados com o contrato aprovado, que ainda valia no commit.
        ->and($results[0]['outcome'])->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($results[1]['outcome'])->toBe('moved')
        ->and(p4mLast($official->id))->toBe('2026-03-19')
        // A edição, que esperou, é vista na rodada seguinte: nada mais é anexado nem reescrito.
        ->and(app(PuCurveChangeImpactClassifier::class)->assessVersion($official)->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and($next->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->count())->toBe($rowsAfterRace);
})->group('mysql');

it('refuses the extension when a base parameter change commits during it', function () {
    $scenario = p4mOfficialScenario();

    $results = p4mRace([
        ['action' => 'change_spread', 'hold' => true, 'hold_on' => ['update', 'emission_pu_parameters'], 'payload' => ['emission' => $scenario['emission'], 'spread' => '6.50000000']],
        ['action' => 'extend_official', 'hold' => false, 'payload' => ['emission' => $scenario['emission']]],
    ]);

    $official = EmissionPuCurveVersion::query()->findOrFail($scenario['official']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[1]['outcome'])->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and(p4mLast($official->id))->toBe('2026-03-16')
        ->and($official->extension_divergence['first_divergent_date'])->toBe('2026-03-02');
})->group('mysql');

it('never records a version whose inputs changed between the snapshot and the write', function () {
    $scenario = p4mEmission();

    // A geração segura logo depois de tirar o retrato (a leitura sem trava dos
    // eventos, por `emission_id = ?`; a releitura da gravação é a com trava) e o
    // evento é movido enquanto ela calcula.
    $results = p4mRace([
        ['action' => 'generate', 'hold' => true, 'hold_on' => ['select', 'from `emission_pu_events` where `emission_id` = ?', 'order by `id`'], 'skip_on' => ['lock in share mode'], 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'move_event', 'hold' => false, 'payload' => ['event' => $scenario['event'], 'date' => '2026-03-10']],
    ]);

    expect($results[1]['outcome'])->toBe('moved')
        ->and($results[0]['success'])->toBeFalse()
        ->and($results[0]['exception'])->toContain('mudaram durante a geração')
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $scenario['emission'])->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->where('emission_id', $scenario['emission'])->count())->toBe(0)
        ->and(EmissionPuEvent::query()->findOrFail($scenario['event'])->governed_at)->toBeNull();
})->group('mysql');

it('refuses a homologation that waited for a retroactive change of the version inputs', function () {
    $scenario = p4mEmission();
    $version = app(GeneratePuDailyCurve::class)->handle(Emission::query()->findOrFail($scenario['emission']))->calculationVersion;
    $checker = (int) User::factory()->create()->getKey();

    $results = p4mRace([
        ['action' => 'move_event', 'hold' => true, 'hold_on' => ['update', 'emission_pu_events'], 'payload' => ['event' => $scenario['event'], 'date' => '2026-03-10']],
        ['action' => 'homologate', 'hold' => false, 'payload' => ['emission' => $scenario['emission'], 'version' => $version, 'actor' => $checker]],
    ]);

    expect($results[0]['outcome'])->toBe('moved')
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toContain('não pode ser homologada')
        ->and(Emission::query()->findOrFail($scenario['emission'])->officialPuCurveVersion())->toBeNull();
})->group('mysql');

it('keeps exactly one event per active identity when two writers create it at once', function () {
    $scenario = p4mEmission();
    $amortization = [
        'emission' => $scenario['emission'],
        'type' => PuEventType::Amortization->value,
        'date' => '2026-06-30',
        'amortization_type' => PuAmortizationType::Percentage->value,
        'amortization_value' => '0.1000000000000000',
        'sequence' => 1,
    ];

    $results = p4mRace([
        ['action' => 'create_event', 'hold' => true, 'hold_on' => ['insert', 'emission_pu_events'], 'payload' => $amortization],
        ['action' => 'create_event', 'hold' => false, 'payload' => $amortization],
    ]);

    expect($results[0]['outcome'])->toBe('created')
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toContain('UniqueConstraintViolationException')
        ->and(EmissionPuEvent::query()->where('emission_id', $scenario['emission'])->where('event_type', PuEventType::Amortization->value)->count())->toBe(1);
})->group('mysql');

it('never double-applies interest even when two same-day coupons slip past the form at once', function () {
    $scenario = p4mEmission();
    $coupon = fn (int $sequence): array => [
        'emission' => $scenario['emission'],
        'type' => PuEventType::InterestPayment->value,
        'date' => '2026-03-31',
        'amortization_type' => PuAmortizationType::None->value,
        'sequence' => $sequence,
    ];

    $results = p4mRace([
        ['action' => 'create_event', 'hold' => true, 'hold_on' => ['insert', 'emission_pu_events'], 'payload' => $coupon(1)],
        ['action' => 'create_event', 'hold' => false, 'payload' => $coupon(2)],
    ]);

    $coupons = EmissionPuEvent::query()->where('emission_id', $scenario['emission'])->whereDate('effective_date', '2026-03-31')->count();
    $prerequisites = app(PuCurvePrerequisiteService::class)->handle(Emission::query()->findOrFail($scenario['emission']));

    // A regra "um cupom por data" é do domínio (sem índice parcial no MySQL): se a
    // corrida a fura, a geração recusa com o motivo -- nunca paga juros duas vezes.
    expect($results[0]['outcome'])->toBe('created')
        ->and($coupons)->toBeIn([1, 2])
        ->and($coupons === 1 || ! $prerequisites->passes())->toBeTrue()
        ->and($coupons === 1 || str_contains($prerequisites->blockingSummary(), 'mais de um pagamento de juros'))->toBeTrue();
})->group('mysql');
