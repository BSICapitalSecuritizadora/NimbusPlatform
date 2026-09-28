<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\ContractSettlementResolver;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use App\Services\SalesBoards\SalesBoardRolloutAssessmentService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PerformanceProbe;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

/**
 * O custo do Quadro no volume de uma carteira real, medido e não estimado.
 *
 * Os outros benchmarks provam que o número de consultas não cresce com a obra.
 * Este mede o que eles não alcançam: memória e tempo crescem com as parcelas, e
 * é a parcela -- não a unidade nem o contrato -- que decide se uma tela cabe nos
 * 256 MB da requisição web (`public/.user.ini`).
 *
 * A massa é inserida em lote, sem factory: 57.600 parcelas por factory levariam
 * minutos, e o que se mede aqui é a apuração, não a montagem.
 *
 * Memória é o pico da seção, com o contador de pico do processo zerado antes
 * ({@see memory_reset_peak_usage()}) e descontado o uso de partida. Medir
 * `memory_get_usage(true)` antes e depois, como se fazia, devolve zero: o
 * alocador já reservou os blocos na montagem, e o que a seção aloca e solta no
 * meio nunca aparece.
 *
 * Opt-in: `NIMBUS_BENCH=1`. Tempo em suíte comum vira teste instável que o time
 * aprende a reexecutar até passar, e um teste que se ignora não mede nada.
 */
beforeEach(function () {
    if (! env('NIMBUS_BENCH')) {
        $this->markTestSkipped('Benchmark opt-in: defina NIMBUS_BENCH=1.');
    }

    DB::connection()->disableQueryLog();
});

/**
 * Orçamentos explícitos. Cada um é o teto que a medição tem de respeitar, com
 * folga sobre o valor observado para não virar teste de máquina.
 *
 * - a observação da fonte guarda um resumo por contrato, não uma linha por
 *   parcela, e lê as parcelas como linhas simples: seu pico por parcela é uma
 *   fração do da derivação (medido: ~0,06 KB no SQLite, ~0,11 KB no MySQL);
 * - a derivação, a geração e a verificação leem as parcelas no resolvedor de
 *   quitação ({@see ContractSettlementResolver}) também como linhas simples,
 *   contadas em fluxo (medido: ~0,11 a ~0,16 KB por parcela, SQLite e MySQL;
 *   quando o resolvedor hidratava um model por parcela eram ~2,0 KB). O teto
 *   de 0,5 KB deixa folga para a variação entre bancos e máquinas, e ainda
 *   pega de volta a hidratação -- que é o que empurrava 57.600 parcelas para
 *   perto dos 256 MB da requisição web;
 * - tempo por parcela (medido: ~0,08 ms no par derivação e observação);
 * - a avaliação do rollout percorre a Emissão empreendimento a empreendimento:
 *   o pico dela acompanha o maior empreendimento, não a soma deles (medido:
 *   1,0 vez o maior; somar três obras dava 3,1 vezes).
 *
 * @return array{observation_kb_per_installment: float, pipeline_kb_per_installment: float, ms_per_installment: float, rollout_peak_over_largest: float}
 */
function volumeBudgets(): array
{
    return [
        'observation_kb_per_installment' => 0.25,
        'pipeline_kb_per_installment' => 0.5,
        'ms_per_installment' => 0.25,
        'rollout_peak_over_largest' => 1.35,
    ];
}

/**
 * Um empreendimento maduro: 800 unidades, 90% vendidas, 80 parcelas por
 * contrato -- 57.600 parcelas.
 *
 * O formato cobre os caminhos da derivação: vendas antigas em aberto, contratos
 * quitados antes do mês, quitações dentro do mês, distratos antigos (unidade de
 * volta ao estoque) e vendas da competência avaliadas contra a política.
 *
 * @return array{construction: Construction, installments: int}
 */
function volumeConstruction(Emission $emission, string $prefix, int $units = 800, float $soldRatio = 0.9, int $installmentsPerContract = 80): array
{
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Volume '.$prefix,
    ]);

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2020-01-01')
        ->allowing('10.00')
        ->create();

    $now = now()->toDateTimeString();

    $unitRows = [];
    foreach (range(1, $units) as $number) {
        $unitRows[] = [
            'construction_id' => $construction->id,
            'block' => str_pad((string) (intdiv($number - 1, 100) + 1), 2, '0', STR_PAD_LEFT),
            'unit' => $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'base_value' => '500000.00',
            'base_value_reference_date' => '2024-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($unitRows, 500) as $chunk) {
        DB::table('construction_units')->insert($chunk);
    }

    $unitIds = DB::table('construction_units')
        ->where('construction_id', $construction->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $sold = (int) floor($units * $soldRatio);
    $contractRows = [];

    foreach (array_slice($unitIds, 0, $sold) as $index => $unitId) {
        $cancelled = ($index % 40) === 39;

        $contractRows[] = [
            'construction_unit_id' => $unitId,
            'construction_id' => $construction->id,
            'code' => 'VOL-'.$prefix.'-'.$index,
            'code_normalized' => 'VOL-'.$prefix.'-'.$index,
            'sale_date' => ($index % 50) === 0 ? '2026-07-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT) : '2024-01-15',
            'sale_value' => '600000.00',
            'status' => $cancelled ? 'distratado' : 'ativo',
            'cancellation_date' => $cancelled ? '2025-06-10' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($contractRows, 500) as $chunk) {
        DB::table('contracts')->insert($chunk);
    }

    $contractIds = DB::table('contracts')
        ->where('construction_id', $construction->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $firstDue = CarbonImmutable::parse('2024-02-15');
    $closing = '2026-07-01';
    $buffer = [];
    $installments = 0;

    foreach ($contractIds as $index => $contractId) {
        $settlesEarly = ($index % 10) === 5;
        $settlesInMonth = ($index % 10) === 7;

        foreach (range(1, $installmentsPerContract) as $number) {
            $due = $firstDue->addMonths($number - 1)->toDateString();

            $paymentDate = match (true) {
                $settlesEarly => '2026-05-20',
                $settlesInMonth => $due < $closing ? $due : '2026-07-10',
                default => $due < $closing ? $due : null,
            };

            $buffer[] = [
                'contract_id' => $contractId,
                'number' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'number_normalized' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'due_date' => $due,
                'expected_value' => '7500.00',
                'payment_date' => $paymentDate,
                'paid_value' => $paymentDate === null ? null : '7500.00',
                'cancellation_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $installments++;

            if (count($buffer) === 500) {
                DB::table('contract_installments')->insert($buffer);
                $buffer = [];
            }
        }
    }

    if ($buffer !== []) {
        DB::table('contract_installments')->insert($buffer);
    }

    return ['construction' => $construction->fresh(), 'installments' => $installments];
}

/**
 * Mede uma seção: tempo de parede e pico de memória acima do uso de partida.
 *
 * É o mesmo instrumento de {@see PerformanceProbe::measure()} (pico zerado
 * antes, `hrtime` em volta), numa passada só. O probe não serve aqui: ele roda
 * o alvo uma vez para aquecer, outra com o log de consultas ligado e mais
 * `$runs` vezes para a mediana. No volume desta massa, isso seria sete
 * derivações de 57.600 parcelas por seção, no padrão, e a geração, que grava o
 * ciclo, ainda pediria um `$reset` entre as passadas. O que se mede aqui é
 * memória e ordem de grandeza de tempo, e não a contagem de consultas, que os
 * outros benchmarks já cobrem.
 *
 * @return array{ms: float, peak_mb: float, result: mixed}
 */
function volumeMeasure(Closure $subject): array
{
    gc_collect_cycles();

    $base = memory_get_usage();
    memory_reset_peak_usage();
    $started = hrtime(true);

    $result = $subject();

    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $peak = memory_get_peak_usage() - $base;

    return [
        'ms' => round($elapsed, 1),
        'peak_mb' => round($peak / 1_048_576, 1),
        'result' => $result,
    ];
}

/**
 * @param  array{ms: float, peak_mb: float}  $measurement
 * @return array{ms: float, peak_mb: float, kb_per_installment: float, ms_per_installment: float}
 */
function volumeReport(array $measurement, int $installments): array
{
    return [
        'ms' => $measurement['ms'],
        'peak_mb' => $measurement['peak_mb'],
        'kb_per_installment' => round(($measurement['peak_mb'] * 1024) / max($installments, 1), 3),
        'ms_per_installment' => round($measurement['ms'] / max($installments, 1), 4),
    ];
}

it('keeps a mature development inside its memory and time budgets', function () {
    // A geração só congela competência coberta pela automação.
    $emission = Emission::factory()->withAutomatedSalesBoard()->create(['status' => 'active']);
    ['construction' => $construction, 'installments' => $installments] = volumeConstruction($emission, 'A');
    $month = CarbonImmutable::parse('2026-07-01');

    $derivation = app(SalesBoardDerivationService::class);
    $fingerprint = app(SalesBoardFingerprintService::class);

    // Uma passada descartada: autoload e resolução de container não são custo
    // da apuração.
    $derivation->deriveForConstruction($construction, $month);
    $fingerprint->observeForConstruction($construction, $month);

    $derive = volumeMeasure(fn () => $derivation->deriveForConstruction($construction, $month));
    $observe = volumeMeasure(fn () => $fingerprint->observeForConstruction($construction, $month));
    $pair = volumeMeasure(fn () => DB::transaction(fn (): array => [
        $derivation->deriveForConstruction($construction, $month),
        $fingerprint->observeForConstruction($construction, $month),
    ]));

    $generate = volumeMeasure(fn () => CycleFixture::generate($construction));
    $cycle = $generate['result']->cycle;

    expect($cycle)->not->toBeNull();

    $baseline = CycleFixture::currentBaseline($cycle);
    $assess = volumeMeasure(fn () => app(SalesBoardStaleDetectionService::class)
        ->assessWithoutPersisting($cycle->fresh(), $baseline->fresh()));

    $report = [
        'installments' => $installments,
        'derivation' => volumeReport($derive, $installments),
        'observation' => volumeReport($observe, $installments),
        'derivation_and_observation' => volumeReport($pair, $installments),
        'generation' => volumeReport($generate, $installments),
        'assess_without_persisting' => volumeReport($assess, $installments),
    ];

    dump($report);

    $budgets = volumeBudgets();

    expect($installments)->toBe(57_600)
        ->and($assess['result']->sourceChanged)->toBeFalse()
        ->and($assess['result']->snapshotChanged)->toBeFalse()
        ->and($report['observation']['kb_per_installment'])->toBeLessThanOrEqual($budgets['observation_kb_per_installment']);

    foreach (['derivation', 'derivation_and_observation', 'generation', 'assess_without_persisting'] as $section) {
        expect($report[$section]['kb_per_installment'])->toBeLessThanOrEqual($budgets['pipeline_kb_per_installment'], $section);
    }

    foreach (['derivation', 'observation', 'derivation_and_observation', 'generation', 'assess_without_persisting'] as $section) {
        expect($report[$section]['ms_per_installment'])->toBeLessThanOrEqual($budgets['ms_per_installment'], $section);
    }
});

it('assesses a whole emission for the rollout within the budget of its largest development', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    $installments = 0;
    $largest = null;

    foreach (['A', 'B', 'C'] as $prefix) {
        $built = volumeConstruction($emission, $prefix);
        $installments += $built['installments'];
        $largest ??= $built['construction'];
    }

    $month = CarbonImmutable::parse('2026-07-01');
    $derivation = app(SalesBoardDerivationService::class);
    $fingerprint = app(SalesBoardFingerprintService::class);

    $homologation = SalesBoardRolloutHomologation::factory()->create([
        'emission_id' => $emission->id,
        'comparison_reference_month' => '2026-07-01',
        'proposed_start_reference_month' => '2026-08-01',
    ]);

    $assessment = app(SalesBoardRolloutAssessmentService::class);
    $assessment->observe($homologation);

    $single = volumeMeasure(fn () => DB::transaction(fn (): array => [
        $derivation->deriveForConstruction($largest, $month),
        $fingerprint->observeForConstruction($largest, $month),
    ]));

    $observe = volumeMeasure(fn () => $assessment->observe($homologation->fresh()));

    $report = [
        'constructions' => 3,
        'installments' => $installments,
        'largest_development_pair' => volumeReport($single, intdiv($installments, 3)),
        'rollout_observe' => volumeReport($observe, $installments),
        'peak_over_largest' => round($observe['peak_mb'] / max($single['peak_mb'], 0.1), 2),
    ];

    dump($report);

    $budgets = volumeBudgets();

    /**
     * Abrir, reavaliar, aprovar e ativar a homologação rodam na requisição web.
     * Se o pico somasse os empreendimentos, uma Emissão com três obras maduras
     * passaria dos 256 MB mesmo com cada obra cabendo sozinha.
     */
    expect($installments)->toBe(172_800)
        ->and(array_keys($observe['result']->constructionRows))->toHaveCount(3)
        ->and($report['peak_over_largest'])->toBeLessThanOrEqual($budgets['rollout_peak_over_largest'])
        ->and($report['rollout_observe']['ms_per_installment'])->toBeLessThanOrEqual($budgets['ms_per_installment']);
});
