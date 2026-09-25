<?php

use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;
use Tests\Support\CommittedRowsSweeper;

/**
 * Ativação da automação × registro manual da competência inicial, em conexões
 * reais.
 *
 * A ativação trava a Emissão com `FOR UPDATE`, confere que não há quadro manual
 * a partir da competência inicial e só depois refaz a derivação inteira -- o
 * trecho mais lento da transação. Se o guard do quadro manual lesse a Emissão
 * sem lock, enxergaria o modo "legado" ainda não commitado e gravaria o quadro
 * justamente nessa janela: as duas transações commitariam e a Emissão nasceria
 * automatizada com um quadro manual na competência que o ciclo precisa
 * publicar.
 *
 * O teste abre a janela de propósito: a ativação para logo depois de conferir o
 * conflito e espera o registro manual terminar. Com o guard serializado pela
 * Emissão, o registro manual não termina -- fica esperando o lock -- e, quando
 * a ativação commita, é recusado como escrita em competência automatizada.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma Emissão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

/**
 * O cenário e os processos filhos commitam fora de qualquer transação de teste.
 * A limpeza devolve o banco ao estado recém-migrado -- tudo o que entrou depois
 * da foto do `beforeEach`, em qualquer tabela -- e a verificação garante que o
 * arquivo seguinte da suíte não herda nada daqui.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * A ativação, parada logo depois de reconferir o conflito com o legado.
 *
 * @param  array{emission: int, homologation: int, actor: int, own_marker: string, peer_marker: string}  $instruction
 */
function manualWriteRaceActivationTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        $barrierMet = false;
        $matches = 0;

        DB::listen(static function (QueryExecuted $query) use ($instruction, &$barrierMet, &$matches): void {
            if ($matches > 0 || ! str_contains(strtolower($query->sql), 'from `sales_boards` where `construction_id` in')) {
                return;
            }

            $matches++;
            touch($instruction['own_marker']);
            $deadline = microtime(true) + 3;

            while (! is_file($instruction['peer_marker']) && microtime(true) < $deadline) {
                usleep(5_000);
            }

            $barrierMet = is_file($instruction['peer_marker']);
        });

        try {
            $emission = app(SalesBoardRolloutActivationService::class)->activate(
                Emission::query()->findOrFail($instruction['emission']),
                SalesBoardRolloutHomologation::query()->findOrFail($instruction['homologation']),
                User::query()->findOrFail($instruction['actor']),
                'Ativação concorrente com registro manual.',
            );

            return [
                'success' => true,
                'outcome' => $emission->sales_board_source->value,
                'barrier_met' => $barrierMet,
                'exception' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'outcome' => null,
                'barrier_met' => $barrierMet,
                'exception' => $exception::class,
            ];
        }
    };
}

/**
 * O registro manual da competência inicial, disparado dentro da janela da
 * ativação.
 *
 * @param  array{emission: int, construction: int, reference_month: string, own_marker: string, peer_marker: string}  $instruction
 */
function manualWriteRaceBoardTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        $deadline = microtime(true) + 20;

        while (! is_file($instruction['peer_marker']) && microtime(true) < $deadline) {
            usleep(5_000);
        }

        try {
            $salesBoard = SalesBoard::query()->create([
                'emission_id' => $instruction['emission'],
                'construction_id' => $instruction['construction'],
                'reference_month' => $instruction['reference_month'],
                'stock_units' => 2,
                'financed_units' => 0,
                'paid_units' => 0,
                'exchanged_units' => 0,
                'stock_value' => '1000000.00',
                'financed_value' => '0.00',
                'paid_value' => '0.00',
                'exchanged_value' => '0.00',
            ]);

            return ['success' => true, 'sales_board_id' => $salesBoard->getKey(), 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'sales_board_id' => null, 'exception' => $exception::class];
        } finally {
            touch($instruction['own_marker']);
        }
    };
}

it('never lets a manual board of the initial competence slip through a running activation', function () {
    $scenario = RolloutFixture::emission(2);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    $construction = $scenario['constructions'][0];

    $activationMarker = temporaryTestFilePath('rollout-manual-write-activation', 'lock');
    $manualMarker = temporaryTestFilePath('rollout-manual-write-board', 'lock');
    @unlink($activationMarker);
    @unlink($manualMarker);

    [$activation, $manual] = Concurrency::driver('process')->run([
        manualWriteRaceActivationTask([
            'emission' => (int) $scenario['emission']->getKey(),
            'homologation' => (int) $homologation->getKey(),
            // Ativar é da Gestão, e não de quem abriu a homologação.
            'actor' => (int) GovernanceFixture::approver()->getKey(),
            'own_marker' => $activationMarker,
            'peer_marker' => $manualMarker,
        ]),
        manualWriteRaceBoardTask([
            'emission' => (int) $scenario['emission']->getKey(),
            'construction' => (int) $construction->getKey(),
            'reference_month' => RolloutFixture::START_MONTH,
            'own_marker' => $manualMarker,
            'peer_marker' => $activationMarker,
        ]),
    ]);

    $emission = Emission::query()->findOrFail($scenario['emission']->getKey());

    /**
     * A ativação chegou à janela e não viu o registro manual terminar dentro
     * dela: o guard esperou o lock da Emissão. Quando a ativação commitou, o
     * guard leu o modo já automatizado e recusou.
     */
    expect($activation['success'])->toBeTrue()
        ->and($activation['barrier_met'])->toBeFalse()
        ->and($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($manual['success'])->toBeFalse()
        ->and($manual['exception'])->toBe(SalesBoardRolloutException::class)
        ->and(SalesBoard::query()
            ->where('construction_id', $construction->getKey())
            ->where('reference_month', '>=', RolloutFixture::START_MONTH)
            ->count())->toBe(0);
})->group('mysql');
