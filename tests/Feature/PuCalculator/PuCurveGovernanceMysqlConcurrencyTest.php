<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CommittedRowsSweeper;

/**
 * Fase 2 -- as ações de governança da curva disputando a mesma emissão em
 * conexões reais do MySQL.
 *
 * O SQLite serializa escritores e não mostra a janela entre "a tela viu o status"
 * e "a ação gravou". Aqui um processo trava a versão e segura a transação aberta
 * enquanto o outro tenta agir sobre a mesma emissão: a trava (emissão → versão) é
 * o que serializa os dois, e o segundo relê o status depois do commit do primeiro.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas ações de governança disputando a mesma versão.');
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
 * @return array{emission: int, maker: int, checkers: list<int>}
 */
function governanceRaceScenario(): array
{
    return [
        'emission' => (int) Emission::factory()->create(['type' => 'CRI', 'status' => 'active'])->getKey(),
        'maker' => (int) User::factory()->create()->getKey(),
        'checkers' => [(int) User::factory()->create()->getKey(), (int) User::factory()->create()->getKey()],
    ];
}

function governanceRaceVersion(int $emissionId, string $calculationVersion, PuCurveStatus $status, ?int $maker): int
{
    return (int) EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emissionId,
        'calculation_version' => $calculationVersion,
        'status' => $status->value,
        'generated_by' => $maker,
    ])->getKey();
}

/**
 * Um processo filho que executa uma ação de governança.
 *
 * Com `hold`, ele trava a versão e segura a transação aberta por 800 ms depois
 * de avisar o outro processo pelo marcador. Sem `hold`, ele espera o marcador e
 * só então age -- e precisa esperar a trava do primeiro.
 *
 * @param  array{action: string, emission: int, version: string|int, actor: int, hold: bool, marker: string}  $instruction
 */
function governanceRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['hold']) {
                $held = false;

                DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                    $sql = strtolower($query->sql);

                    if ($held || ! str_contains($sql, 'emission_pu_curve_versions') || ! str_contains($sql, 'for update')) {
                        return;
                    }

                    $held = true;
                    file_put_contents($instruction['marker'], 'locked');
                    usleep(800_000);
                });
            } else {
                $deadline = microtime(true) + 15;

                while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['marker'])) {
                    throw new RuntimeException('O processo concorrente não chegou a travar a versão.');
                }
            }

            $emission = Emission::query()->findOrFail($instruction['emission']);

            match ($instruction['action']) {
                'homologate' => app(HomologatePuCurve::class)->handle($emission, (string) $instruction['version'], $instruction['actor'], 'Conferida pelo processo '.$instruction['actor'].'.'),
                'invalidate' => app(InvalidatePuCurve::class)->handle($emission, (string) $instruction['version'], $instruction['actor']),
                'complete_generation' => app(PuCurveVersionService::class)->markGenerated(
                    EmissionPuCurveVersion::query()->findOrFail((int) $instruction['version']),
                    5,
                ),
            };

            return ['success' => true, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array{action: string, version: string|int, actor: int, hold: bool}>  $tasks
 * @return list<array{success: bool, exception: ?string}>
 */
function governanceRace(int $emissionId, array $tasks): array
{
    $marker = temporaryTestFilePath('pu-governance-race', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $task): Closure => governanceRaceTask([...$task, 'emission' => $emissionId, 'marker' => $marker]),
        $tasks,
    ));

    @unlink($marker);

    return array_values($results);
}

it('lets exactly one of two concurrent homologations of the same version through', function () {
    $scenario = governanceRaceScenario();
    $versionId = governanceRaceVersion($scenario['emission'], 'v1', PuCurveStatus::Validated, $scenario['maker']);
    [$first, $second] = $scenario['checkers'];

    $results = governanceRace($scenario['emission'], [
        ['action' => 'homologate', 'version' => 'v1', 'actor' => $first, 'hold' => true],
        ['action' => 'homologate', 'version' => 'v1', 'actor' => $second, 'hold' => false],
    ]);

    $version = EmissionPuCurveVersion::query()->findOrFail($versionId);

    expect($results[0])->toBe(['success' => true, 'exception' => null])
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toContain('PuCurveGovernanceException')
        ->and($results[1]['exception'])->toContain('não pode ser homologada')
        ->and($version->status)->toBe(PuCurveStatus::Homologated)
        ->and($version->homologated_by)->toBe($first)
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->count())->toBe(1);
})->group('mysql');

it('keeps the homologated v3 when v4 finishes generating while v3 is being homologated', function () {
    $scenario = governanceRaceScenario();
    $v3 = governanceRaceVersion($scenario['emission'], 'v3', PuCurveStatus::Validated, $scenario['maker']);
    $v4 = governanceRaceVersion($scenario['emission'], 'v4', PuCurveStatus::Processing, null);

    $results = governanceRace($scenario['emission'], [
        ['action' => 'homologate', 'version' => 'v3', 'actor' => $scenario['checkers'][0], 'hold' => true],
        ['action' => 'complete_generation', 'version' => $v4, 'actor' => $scenario['maker'], 'hold' => false],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(EmissionPuCurveVersion::query()->findOrFail($v3)->status)->toBe(PuCurveStatus::Homologated)
        ->and(EmissionPuCurveVersion::query()->findOrFail($v4)->status)->toBe(PuCurveStatus::Generated)
        ->and(Emission::query()->findOrFail($scenario['emission'])->officialPuCurveVersion()?->id)->toBe($v3);
})->group('mysql');

it('refuses the reviewed v3, and never homologates v4, when v4 superseded v3 first', function () {
    $scenario = governanceRaceScenario();
    $v3 = governanceRaceVersion($scenario['emission'], 'v3', PuCurveStatus::Validated, $scenario['maker']);
    $v4 = governanceRaceVersion($scenario['emission'], 'v4', PuCurveStatus::Processing, null);

    $results = governanceRace($scenario['emission'], [
        ['action' => 'complete_generation', 'version' => $v4, 'actor' => $scenario['maker'], 'hold' => true],
        ['action' => 'homologate', 'version' => 'v3', 'actor' => $scenario['checkers'][0], 'hold' => false],
    ]);

    expect($results[0])->toBe(['success' => true, 'exception' => null])
        ->and($results[1]['exception'])->toContain('não pode ser homologada')
        ->and(EmissionPuCurveVersion::query()->findOrFail($v3)->status)->toBe(PuCurveStatus::Obsolete)
        ->and(EmissionPuCurveVersion::query()->findOrFail($v4)->status)->toBe(PuCurveStatus::Generated)
        ->and(Emission::query()->findOrFail($scenario['emission'])->officialPuCurveVersion())->toBeNull()
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->exists())->toBeFalse();
})->group('mysql');

it('never lets the generation resurrect a version invalidated while it waited for the lock', function () {
    $scenario = governanceRaceScenario();
    $version = governanceRaceVersion($scenario['emission'], 'v1', PuCurveStatus::Processing, null);

    // A geração conclui primeiro e segura a trava; a invalidação espera, relê o
    // status (agora gerada) e só então invalida. A ordem inversa -- invalidar uma
    // versão em processamento -- é recusada.
    $results = governanceRace($scenario['emission'], [
        ['action' => 'complete_generation', 'version' => $version, 'actor' => $scenario['maker'], 'hold' => true],
        ['action' => 'invalidate', 'version' => 'v1', 'actor' => $scenario['checkers'][0], 'hold' => false],
    ]);
    $afterRace = EmissionPuCurveVersion::query()->findOrFail($version);

    $late = governanceRace($scenario['emission'], [
        ['action' => 'complete_generation', 'version' => $version, 'actor' => $scenario['maker'], 'hold' => true],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($afterRace->status)->toBe(PuCurveStatus::Obsolete)
        ->and($afterRace->obsolete_reason)->toBe('invalidated')
        ->and($late[0]['exception'])->toContain('não está mais em processamento')
        ->and(EmissionPuCurveVersion::query()->findOrFail($version)->status)->toBe(PuCurveStatus::Obsolete);
})->group('mysql');
