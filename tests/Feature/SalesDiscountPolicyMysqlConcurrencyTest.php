<?php

use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;

/**
 * Duas substituições confirmadas da mesma política, em conexões reais.
 *
 * O registrador trava a obra e só depois reavalia a substituição. No SQLite o
 * lock não faz nada -- ele serializa escritores de qualquer jeito --, então só
 * aqui um refactor que movesse o lock para depois da avaliação, ou o trocasse
 * por uma leitura simples, apareceria: os dois gestores gravariam, e o
 * resolvedor escolheria em silêncio a de maior id, que um deles nunca viu nem
 * confirmou.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma obra.');
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
 * @param  array{construction_id: int, confirmed_substitution_id: int, percent: string, from: string, until: string, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function salesDiscountPolicyTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                $sql = strtolower($query->sql);

                if (! str_contains($sql, '`constructions`') || ! str_contains($sql, 'for update')) {
                    return;
                }

                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(((int) ($instruction['hold_after_lock_ms'] ?? 0)) * 1000);
            });
        }

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou a aquisição do lock.');
                }
            }

            $policy = app(SalesDiscountPolicyRegistrar::class)->register(
                Construction::query()->findOrFail($instruction['construction_id']),
                [
                    'maximum_discount_percent' => $instruction['percent'],
                    'effective_from' => $instruction['from'],
                    'effective_until' => $instruction['until'],
                    'reason' => 'Substituição concorrente.',
                ],
                $instruction['confirmed_substitution_id'],
                null,
            );

            return ['success' => true, 'policy_id' => (int) $policy->getKey(), 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'policy_id' => null, 'exception' => $exception::class];
        }
    };
}

it('serializes two confirmed substitutions of the same policy', function () {
    /**
     * Datas futuras em relação ao relógio real: os processos filhos não herdam
     * o `travelTo()`, e um início no passado pediria também a confirmação do
     * alcance retroativo -- outra regra, que não é a desta corrida.
     */
    $year = CarbonImmutable::now()->addYears(2)->year;

    $construction = Construction::factory()->create();
    $current = SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->during("{$year}-01-01", "{$year}-12-31")
        ->allowing('5.00')
        ->create();

    $marker = temporaryTestFilePath('sales-discount-policy-lock', 'lock');
    @unlink($marker);

    $substitution = [
        'construction_id' => $construction->id,
        'confirmed_substitution_id' => $current->id,
        'from' => "{$year}-06-01",
        'until' => "{$year}-12-31",
    ];

    $results = Concurrency::driver('process')->run([
        salesDiscountPolicyTask([
            ...$substitution,
            'percent' => '3.00',
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        salesDiscountPolicyTask([
            ...$substitution,
            'percent' => '4.00',
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $winner = collect($results)->firstWhere('success', true);

    /**
     * O primeiro trava a obra, avalia e grava. O segundo espera o lock e, ao
     * reavaliar, encontra a política recém-gravada no lugar da que confirmou:
     * a confirmação dele valia para outra situação e é recusada. Sem o lock --
     * ou com ele depois da avaliação -- os dois gravariam.
     */
    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesDiscountPolicyPeriodException::class])
        ->and(SalesDiscountPolicy::query()->where('construction_id', $construction->id)->pluck('id')->sort()->values()->all())
        ->toBe([$current->id, $winner['policy_id']])
        ->and(app(SalesDiscountPolicyResolver::class)->policyAt($construction, CarbonImmutable::parse("{$year}-06-01"))->policy?->id)
        ->toBe($winner['policy_id'])
        ->and(app(SalesDiscountPolicyResolver::class)->policyAt($construction, CarbonImmutable::parse("{$year}-05-31"))->policy?->id)
        ->toBe($current->id);
})->group('mysql');
