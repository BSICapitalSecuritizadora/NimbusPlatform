<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementConflictKind;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 5 -- liquidação disputando o MySQL real com outra liquidação, com a
 * correção, com a homologação e com a extensão da curva oficial.
 *
 * O SQLite serializa escritores e não mostra a janela entre ler e gravar. Aqui um
 * processo segura a transação aberta logo depois de uma consulta escolhida e o
 * outro age no meio. A emissão travada primeiro enfileira os dois (mesma ordem da
 * homologação e da extensão), e o banco ainda garante uma liquidação ativa por
 * obrigação, uma por chave de ingestão e um sucessor por lançamento.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar processos disputando a liquidação.');
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
 * Curva oficial com o cupom (e amortização de R$ 1/título) de 09/03 calculado e o
 * cupom de 20/03 aguardando o CDI.
 *
 * @return array{emission: int, obligation: int, future: int, expected: string}
 */
function p5mScenario(): array
{
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000', 'sequence' => 2]],
        [PuEventType::InterestPayment, '2026-03-20'],
    ]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');

    return [
        'emission' => (int) $emission->id,
        'obligation' => (int) $obligation->id,
        'future' => (int) Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-20')->id,
        'expected' => Fx::expectedTotal($obligation),
    ];
}

/**
 * Processo filho: com `hold`, segura a conexão por 800 ms logo depois da primeira
 * consulta que contiver todos os trechos de `hold_on`; sem, espera o marcador e
 * age.
 *
 * @param  array{action: string, hold: bool, hold_on: list<string>, marker: string, payload: array<string, mixed>}  $instruction
 */
function p5mTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['hold']) {
                $held = false;

                DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                    $sql = strtolower($query->sql);

                    if ($held) {
                        return;
                    }

                    foreach ($instruction['hold_on'] as $fragment) {
                        if (! str_contains($sql, $fragment)) {
                            return;
                        }
                    }

                    $held = true;
                    file_put_contents($instruction['marker'], 'held');
                    usleep(800_000);
                });
            } else {
                $deadline = microtime(true) + 20;

                while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['marker'])) {
                    throw new RuntimeException('O processo concorrente não chegou ao ponto de espera.');
                }
            }

            $payload = $instruction['payload'];
            $actor = PuSettlementActor::integration('b3-connector-race');

            $outcome = match ($instruction['action']) {
                'settle' => app(PuSettlementService::class)->record(new PuSettlementData(
                    emissionId: $payload['emission'],
                    settlementDate: $payload['date'],
                    amount: $payload['amount'],
                    source: PuSettlementSource::B3,
                    obligationId: $payload['obligation'],
                    externalReference: $payload['reference'],
                ), $actor)->outcome->value,
                'correct' => app(PuSettlementService::class)->correct(
                    $payload['settlement'],
                    new PuSettlementCorrectionData($payload['date'], $payload['amount']),
                    $payload['reason'],
                    $actor,
                )->outcome->value,
                'homologate' => app(HomologatePuCurve::class)
                    ->handle(Emission::query()->findOrFail($payload['emission']), $payload['version'], $payload['actor'], 'Conferida.')
                    ->status->value,
                'extend_official' => app(PuCurveExtensionService::class)
                    ->extendOfficial(Emission::query()->findOrFail($payload['emission']))
                    ->action,
                'refresh' => (string) app(PuFinancialObligationService::class)
                    ->refresh(Emission::query()->findOrFail($payload['emission']), 'race')
                    ->count('created'),
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array{action: string, hold: bool, hold_on?: list<string>, payload: array<string, mixed>}>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}>
 */
function p5mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-settlement-race-'.getmypid(), 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $task): Closure => p5mTask([...$task, 'hold_on' => $task['hold_on'] ?? [], 'marker' => $marker]),
        $tasks,
    ));

    @unlink($marker);

    return array_values($results);
}

/**
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function p5mSettle(array $scenario, string $amount, string $reference): array
{
    return ['emission' => $scenario['emission'], 'obligation' => $scenario['obligation'], 'date' => '2026-03-09', 'amount' => $amount, 'reference' => $reference];
}

it('records exactly one settlement when the same B3 message arrives twice at once', function () {
    $scenario = p5mScenario();

    $results = p5mRace([
        ['action' => 'settle', 'hold' => true, 'hold_on' => ['insert into `emission_pu_settlements`'], 'payload' => p5mSettle($scenario, $scenario['expected'], 'B3-RACE-DUP')],
        ['action' => 'settle', 'hold' => false, 'payload' => p5mSettle($scenario, $scenario['expected'], 'B3-RACE-DUP')],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())->toBe([PuSettlementOutcome::Duplicate->value, PuSettlementOutcome::Recorded->value])
        ->and(EmissionPuSettlement::query()->where('obligation_id', $scenario['obligation'])->count())->toBe(1)
        ->and(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
})->group('mysql');

it('never silently picks one of two conflicting messages that arrive at once', function () {
    $scenario = p5mScenario();

    $results = p5mRace([
        ['action' => 'settle', 'hold' => true, 'hold_on' => ['insert into `emission_pu_settlements`'], 'payload' => p5mSettle($scenario, $scenario['expected'], 'B3-RACE-CONF')],
        ['action' => 'settle', 'hold' => false, 'payload' => p5mSettle($scenario, bcsub($scenario['expected'], '5.00', 2), 'B3-RACE-CONF')],
    ]);
    $conflict = EmissionPuSettlementConflict::query()->where('obligation_id', $scenario['obligation'])->sole();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe(PuSettlementOutcome::Recorded->value)
        ->and($results[1]['outcome'])->toBe(PuSettlementOutcome::Conflict->value)
        ->and($conflict->kind)->toBe(PuSettlementConflictKind::ReferenceDataMismatch)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $scenario['obligation'])->where('status', PuSettlementStatus::Active->value)->count())->toBe(1)
        ->and(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->reconciliation_status)->toBe(PuReconciliationStatus::Conflict);
})->group('mysql');

it('lets only one of two concurrent corrections supersede the settlement', function () {
    $scenario = p5mScenario();
    $settlement = Fx::settle(EmissionPuObligation::query()->findOrFail($scenario['obligation']), '80.00', reference: 'B3-RACE-COR')->settlement;
    $correction = fn (string $amount): array => ['settlement' => $settlement->id, 'date' => '2026-03-09', 'amount' => $amount, 'reason' => 'Arquivo reprocessado.'];

    $results = p5mRace([
        ['action' => 'correct', 'hold' => true, 'hold_on' => ['insert into `emission_pu_settlements`'], 'payload' => $correction('100.00')],
        ['action' => 'correct', 'hold' => false, 'payload' => $correction('90.00')],
    ]);
    $entries = EmissionPuSettlement::query()->where('obligation_id', $scenario['obligation'])->orderBy('id')->get();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe(PuSettlementOutcome::Recorded->value)
        ->and($results[1]['outcome'])->toBe(PuSettlementOutcome::Rejected->value)
        ->and($entries)->toHaveCount(2)
        ->and($entries->where('status', PuSettlementStatus::Active)->count())->toBe(1)
        ->and((string) $entries->firstWhere('status', PuSettlementStatus::Active)->amount)->toBe('100.00')
        ->and($entries->first()->status)->toBe(PuSettlementStatus::Corrected);
})->group('mysql');

it('ends a settlement racing a homologation reconciled against the new official calculation, in either order', function (bool $homologationFirst) {
    $scenario = p5mScenario();
    // O CDI de 04/03 muda: a v2 calcula outro valor esperado para 09/03.
    Fx::publish('2026-03-04', '2026-03-04', '15.50000000');
    $v2 = Fx::generate(Emission::query()->findOrFail($scenario['emission']));
    $v2Interest = (string) Fx::row($v2, '2026-03-09')->interest_payment_value;
    $v1Interest = (string) Fx::row(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->currentCalculation->curveVersion, '2026-03-09')->interest_payment_value;

    expect($v2Interest)->not->toBe($v1Interest);

    $homologate = ['action' => 'homologate', 'payload' => ['emission' => $scenario['emission'], 'version' => $v2->calculation_version, 'actor' => (int) User::factory()->create()->getKey()]];
    $settle = ['action' => 'settle', 'payload' => p5mSettle($scenario, $scenario['expected'], 'B3-RACE-HOM')];

    $results = p5mRace($homologationFirst
        ? [[...$homologate, 'hold' => true, 'hold_on' => ['insert into `emission_pu_obligation_calculations`']], [...$settle, 'hold' => false]]
        : [[...$settle, 'hold' => true, 'hold_on' => ['insert into `emission_pu_settlements`']], [...$homologate, 'hold' => false]]);
    $obligation = EmissionPuObligation::query()->findOrFail($scenario['obligation']);
    $current = EmissionPuObligationCalculation::query()->findOrFail($obligation->current_calculation_id);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        ->and(EmissionPuObligation::query()->where('emission_id', $scenario['emission'])->count())->toBe(2)
        ->and($current->curve_version_id)->toBe($v2->id)
        ->and($obligation->latestReconciliation->calculation_id)->toBe($current->id)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $obligation->latestReconciliation->difference)->toBe(bcsub($scenario['expected'], (string) $current->total_amount, 2));
})->with([
    'homologação trava primeiro' => [true],
    'liquidação trava primeiro' => [false],
])->group('mysql');

it('ends a settlement racing the official extension reconciled once the obligation becomes calculable, in either order', function (bool $extensionFirst) {
    $scenario = p5mScenario();
    Fx::publish('2026-03-16', '2026-03-25');
    $extend = ['action' => 'extend_official', 'payload' => ['emission' => $scenario['emission']]];
    $settle = ['action' => 'settle', 'payload' => ['emission' => $scenario['emission'], 'obligation' => $scenario['future'], 'date' => '2026-03-20', 'amount' => '500.00', 'reference' => 'B3-RACE-EXT']];

    $results = p5mRace($extensionFirst
        ? [[...$extend, 'hold' => true, 'hold_on' => ['insert into `emission_pu_daily_curves`']], [...$settle, 'hold' => false]]
        : [[...$settle, 'hold' => true, 'hold_on' => ['insert into `emission_pu_settlements`']], [...$extend, 'hold' => false]]);
    $future = EmissionPuObligation::query()->findOrFail($scenario['future']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->all())->toContain(PuCurveExtensionService::ACTION_EXTENDED, PuSettlementOutcome::Recorded->value)
        ->and($future->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($future->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and($future->latestReconciliation->calculation_id)->toBe($future->current_calculation_id)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $future->id)->count())->toBe(1);
})->with([
    'extensão trava primeiro' => [true],
    'liquidação trava primeiro' => [false],
])->group('mysql');

it('never duplicates an economic obligation or its current calculation under concurrent refreshes', function () {
    $scenario = p5mScenario();
    DB::table('emission_pu_reconciliations')->where('emission_id', $scenario['emission'])->delete();
    DB::table('emission_pu_obligations')->where('emission_id', $scenario['emission'])->update(['latest_reconciliation_id' => null]);

    $results = p5mRace([
        ['action' => 'refresh', 'hold' => true, 'hold_on' => ['insert into `emission_pu_reconciliations`'], 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'refresh', 'hold' => false, 'payload' => ['emission' => $scenario['emission']]],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(EmissionPuObligation::query()->where('emission_id', $scenario['emission'])->count())->toBe(2)
        ->and(EmissionPuObligationCalculation::query()->where('emission_id', $scenario['emission'])->whereNull('superseded_at')->count())->toBe(1)
        ->and(DB::table('emission_pu_reconciliations')->where('emission_id', $scenario['emission'])->count())->toBe(2);
})->group('mysql');

it('enforces one active settlement, one ingestion key and one successor at the MySQL level', function () {
    $scenario = p5mScenario();
    $settlement = Fx::settle(EmissionPuObligation::query()->findOrFail($scenario['obligation']), '80.00', reference: 'B3-DB')->settlement;
    $row = fn (array $override): array => [
        'emission_id' => $scenario['emission'],
        'obligation_id' => $scenario['obligation'],
        'entry_type' => 'settlement',
        'status' => 'active',
        'settlement_date' => '2026-03-09',
        'amount' => '1.00',
        'currency' => 'BRL',
        'source' => 'b3',
        'recorded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$override,
    ];

    expect(fn () => DB::table('emission_pu_settlements')->insert($row([])))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('emission_pu_settlements')->insert($row(['status' => 'corrected', 'ingestion_key' => 'b3|B3-DB'])))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('emission_pu_settlements')->insert($row(['status' => 'corrected', 'predecessor_id' => $settlement->id])) && DB::table('emission_pu_settlements')->insert($row(['status' => 'corrected', 'predecessor_id' => $settlement->id])))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('emission_pu_obligation_calculations')->insert([
            'obligation_id' => $scenario['obligation'],
            'emission_id' => $scenario['emission'],
            'status' => 'complete',
            'due_date' => '2026-03-09',
            'components_fingerprint' => str_repeat('a', 64),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('emission_pu_obligations')->insert([
            'emission_id' => $scenario['emission'],
            'obligation_type' => 'scheduled_payment',
            'contractual_date' => '2026-03-09',
            'sequence' => 1,
            'calculation_state' => 'awaiting_index',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
})->group('mysql');
