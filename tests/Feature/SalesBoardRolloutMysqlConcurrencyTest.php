<?php

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * As corridas do rollout, em conexões reais.
 *
 * O que se protege aqui é a resposta a "quem produz os próximos quadros desta
 * Emissão?" nunca ficar ambígua: duas ativações simultâneas não podem produzir
 * dois eventos, e ativar contra desativar não pode terminar em modo automatizado
 * sem homologação vigente.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma Emissão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('sales_board_rollout_events')->delete();
    DB::table('emissions')->update(['sales_board_active_homologation_id' => null]);
    DB::table('sales_board_rollout_homologation_constructions')->delete();
    DB::table('sales_board_rollout_homologations')->delete();
    DB::table('sales_board_rollout_recipients')->delete();
    DB::table('sales_board_histories')->delete();
    DB::table('sales_boards')->delete();
});

/**
 * @return array{emission: int, homologation: int, actor: int}
 */
function rolloutRaceScenario(): array
{
    $scenario = RolloutFixture::emission(2);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    return [
        'emission' => (int) $scenario['emission']->getKey(),
        'homologation' => (int) $homologation->getKey(),
        'actor' => (int) User::factory()->create()->getKey(),
    ];
}

/**
 * @param  array{action: string, emission: int, homologation?: int, actor: int}  $instruction
 */
function rolloutTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            $actor = User::query()->findOrFail($instruction['actor']);
            $emission = Emission::query()->findOrFail($instruction['emission']);

            $outcome = match ($instruction['action']) {
                'activate' => app(SalesBoardRolloutActivationService::class)->activate(
                    $emission,
                    SalesBoardRolloutHomologation::query()->findOrFail($instruction['homologation']),
                    $actor,
                    'Ativação concorrente para teste de corrida.',
                )->sales_board_source->value,
                'deactivate' => app(SalesBoardRolloutActivationService::class)->returnToLegacy(
                    $emission,
                    $actor,
                    'Retorno concorrente para teste de corrida.',
                )->sales_board_source->value,
                'approve' => app(SalesBoardRolloutHomologationService::class)->approve(
                    SalesBoardRolloutHomologation::query()->findOrFail($instruction['homologation']),
                    $actor,
                    'Aprovação concorrente para teste de corrida.',
                )->status->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

it('never activates the same emission twice', function () {
    $scenario = rolloutRaceScenario();

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'activate', ...$scenario]),
        rolloutTask(['action' => 'activate', ...$scenario]),
    ]);

    $emission = Emission::query()->findOrFail($scenario['emission']);

    // Um ativa; o outro encontra já automatizada e recusa como domínio.
    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardRolloutException::class])
        ->and($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_active_homologation_id)->toBe($scenario['homologation'])
        // Um único evento na trilha.
        ->and(SalesBoardRolloutEvent::query()->count())->toBe(1);
})->group('mysql');

it('never ends inconsistent when activation races a return to legacy', function () {
    $scenario = rolloutRaceScenario();

    // A Emissão começa automatizada, para as duas ações serem plausíveis.
    RolloutFixture::activate(
        Emission::query()->findOrFail($scenario['emission']),
        SalesBoardRolloutHomologation::query()->findOrFail($scenario['homologation']),
    );

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'deactivate', ...$scenario]),
        rolloutTask(['action' => 'activate', ...$scenario]),
    ]);

    $emission = Emission::query()->findOrFail($scenario['emission']);

    /**
     * O estado final é coerente em qualquer ordem: automatizado **com**
     * homologação vigente, ou legado **sem** competência inicial. O que não
     * pode existir é a combinação impossível -- automatizado sem homologação, ou
     * legado ainda produzindo alvos.
     */
    $consistent = $emission->sales_board_source === SalesBoardSource::Automated
        ? $emission->sales_board_active_homologation_id !== null
            && $emission->sales_board_automation_start_reference_month !== null
        : $emission->sales_board_active_homologation_id === null
            && $emission->sales_board_automation_start_reference_month === null;

    expect($consistent)->toBeTrue()
        ->and(collect($results)->where('success', true))->toHaveCount(1);
})->group('mysql');

it('never approves the same homologation twice', function () {
    $scenario = RolloutFixture::emission(2);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    $instruction = [
        'emission' => (int) $scenario['emission']->getKey(),
        'homologation' => (int) $homologation->getKey(),
        'actor' => (int) User::factory()->create()->getKey(),
    ];

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'approve', ...$instruction]),
        rolloutTask(['action' => 'approve', ...$instruction]),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardRolloutException::class])
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        // Um aprovador só, e uma data só.
        ->and($homologation->fresh()->approved_by_user_id)->not->toBeNull();
})->group('mysql');
