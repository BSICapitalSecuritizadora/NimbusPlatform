<?php

use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\SalesBoard;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

/**
 * A corrida entre gravar uma competência de garantias e publicar um Quadro de
 * Vendas, em conexões reais.
 *
 * O caso que o SQLite não mostra: a competência ainda não tem snapshot — não há
 * linha a travar —, a apuração lê os quadros, e o quadro do mês entra antes de
 * a apuração gravar. O invalidador não encontra snapshot para marcar e a
 * gravação sai com o número velho e sem a marca de desatualizada. A trava da
 * emissão (exclusiva na gravação, compartilhada no invalidador e na FK do
 * quadro) é o que serializa os dois lados.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar a gravação e a publicação disputando a mesma emissão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/**
 * Os processos concorrentes commitam em conexões próprias: nada é desfeito por
 * transação de teste. Sem esta limpeza, o arquivo seguinte da suíte começaria
 * com a emissão, os quadros e os snapshots deste.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('guarantee_monthly_positions')->delete();
    DB::table('guarantee_snapshots')->delete();
    DB::table('guarantees')->delete();
    DB::table('sales_board_histories')->delete();
    DB::table('sales_boards')->delete();
    DB::table('pu_histories')->delete();
    DB::table('integralization_histories')->delete();
    DB::table('constructions')->delete();
    DB::table('emissions')->delete();
    DB::table('activity_log')->delete();
});

/**
 * Garantia de estoque sobre um empreendimento com quadro só até julho: a
 * apuração de agosto usa a posição transportada (R$ 10 mi).
 *
 * @return array{emission: int, construction: int}
 */
function guaranteeSnapshotRaceScenario(): array
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-01',
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-08-31', 'unit_value' => 8000]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'stock_value' => 10_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->requiringPercentage(1.2)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    return ['emission' => (int) $emission->getKey(), 'construction' => (int) $construction->getKey()];
}

/**
 * @param  array{action: string, emission: int, construction: int, marker: string}  $instruction
 */
function guaranteeSnapshotRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['action'] === 'persist') {
                $held = false;

                /**
                 * Depois de a apuração ler os quadros, segura a transação aberta
                 * e avisa o outro processo: é a janela em que o quadro de agosto
                 * entraria sem ser visto.
                 */
                DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                    $sql = strtolower($query->sql);

                    if ($held || ! str_contains($sql, 'sales_boards') || str_contains($sql, 'for update')) {
                        return;
                    }

                    $held = true;
                    file_put_contents($instruction['marker'], 'read');
                    usleep(800_000);
                });

                app(GuaranteeSnapshotWriter::class)->persist(
                    Emission::query()->findOrFail($instruction['emission']),
                    '2026-08-01',
                );

                return ['success' => true, 'exception' => null];
            }

            $deadline = microtime(true) + 15;

            while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! is_file($instruction['marker'])) {
                throw new RuntimeException('A apuração concorrente não chegou a ler os quadros.');
            }

            SalesBoard::query()->create([
                'emission_id' => $instruction['emission'],
                'construction_id' => $instruction['construction'],
                'reference_month' => '2026-08-01',
                'stock_units' => 12,
                'financed_units' => 0,
                'paid_units' => 0,
                'exchanged_units' => 0,
                'total_units' => 12,
                'stock_value' => 6_000_000,
                'financed_value' => 0,
                'paid_value' => 0,
                'exchanged_value' => 0,
            ]);

            return ['success' => true, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

it('never records a competence computed before a board published meanwhile without marking it outdated', function () {
    $scenario = guaranteeSnapshotRaceScenario();

    $marker = temporaryTestFilePath('guarantee-snapshot-race', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        guaranteeSnapshotRaceTask(['action' => 'persist', ...$scenario, 'marker' => $marker]),
        guaranteeSnapshotRaceTask(['action' => 'publish', ...$scenario, 'marker' => $marker]),
    ]);

    @unlink($marker);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([]);

    $snapshot = GuaranteeSnapshot::query()
        ->where('emission_id', $scenario['emission'])
        ->whereDate('reference_month', '2026-08-01')
        ->sole();

    // A apuração leu os quadros antes de o de agosto existir, e o quadro esperou
    // a gravação terminar: o snapshot fica com o número transportado, mas
    // marcado como desatualizado — nunca calado.
    expect((float) $snapshot->total_eligible_value)->toBe(10_000_000.0)
        ->and($snapshot->isSalesBoardOutdated())->toBeTrue()
        ->and(SalesBoard::query()->whereDate('reference_month', '2026-08-01')->count())->toBe(1);
})->group('mysql');
