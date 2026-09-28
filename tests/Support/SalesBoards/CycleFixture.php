<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\DTOs\SalesBoards\SalesBoardRecalculationResult;
use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Monta ciclos gerados sem repetir a mesma sequência em cada teste.
 *
 * Complementa {@see DerivationFixture}, que monta a fonte; aqui começa depois
 * dela, no ponto em que a competência é congelada.
 */
final class CycleFixture
{
    /**
     * A competência a partir da qual a automação das Emissões destes cenários
     * cobre. Antes das competências que os testes congelam, para que a geração
     * as aceite.
     */
    public const AUTOMATION_START = '2026-01-01';

    /**
     * Um empreendimento de emissão já ativa -- em elaboração nada é gerado -- e
     * com a automação do Quadro ligada: fora dela o ciclo não é gerado.
     */
    public static function construction(string $status = 'active'): Construction
    {
        return Construction::factory()->create([
            'emission_id' => Emission::factory()
                ->withAutomatedSalesBoard(self::AUTOMATION_START)
                ->create(['status' => $status])
                ->id,
        ]);
    }

    /**
     * Liga a automação do Quadro numa Emissão criada pelo próprio teste.
     *
     * Sem homologação: estes cenários exercitam o ciclo, não o rollout.
     */
    public static function automate(Emission $emission, string $startReferenceMonth = self::AUTOMATION_START): Emission
    {
        $emission->forceFill([
            'sales_board_source' => SalesBoardSource::Automated,
            'sales_board_automation_start_reference_month' => $startReferenceMonth,
        ])->save();

        return $emission;
    }

    /**
     * Executa a escrita como se a Emissão ainda fosse legada, e depois devolve
     * o modo que ela tinha.
     *
     * Serve para montar o quadro manual que um cenário de conflito precisa na
     * competência do ciclo. Com a Emissão automatizada o guard de escrita recusa
     * esse quadro -- e é esse o ponto: ele só existe se foi digitado antes da
     * ativação, ou gravado por fora. O portão de publicação continua precisando
     * reconhecê-lo. O modo é trocado pelo query builder, sem eventos, porque o
     * cenário não é uma ativação nem um retorno ao legado.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function whileLegacy(Emission|int $emission, Closure $write): mixed
    {
        $emissionId = $emission instanceof Emission ? (int) $emission->getKey() : $emission;

        $saved = DB::table('emissions')
            ->where('id', $emissionId)
            ->first(['sales_board_source', 'sales_board_automation_start_reference_month']);

        DB::table('emissions')->where('id', $emissionId)->update([
            'sales_board_source' => SalesBoardSource::Legacy->value,
            'sales_board_automation_start_reference_month' => null,
        ]);

        try {
            return $write();
        } finally {
            DB::table('emissions')->where('id', $emissionId)->update([
                'sales_board_source' => $saved->sales_board_source,
                'sales_board_automation_start_reference_month' => $saved->sales_board_automation_start_reference_month,
            ]);
        }
    }

    /**
     * Um empreendimento pronto: unidades com valor e política vigente.
     *
     * @return array{0: Construction, 1: list<ConstructionUnit>}
     */
    public static function readyConstruction(int $units = 3, string $status = 'active'): array
    {
        $construction = self::construction($status);

        SalesDiscountPolicy::factory()
            ->forConstruction($construction)
            ->effectiveFrom('2020-01-01')
            ->closedPeriod()
            ->allowing('10.00')
            ->create();

        $created = [];

        foreach (range(1, $units) as $number) {
            $created[] = DerivationFixture::unit($construction, (string) (100 + $number));
        }

        return [$construction, $created];
    }

    public static function generate(
        Construction $construction,
        string $referenceMonth = '2026-07-01',
        ?User $actor = null,
        bool $dryRun = false,
    ): SalesBoardGenerationResult {
        return app(SalesBoardGenerationService::class)->generateForConstruction(
            $construction->fresh(),
            CarbonImmutable::parse($referenceMonth),
            $actor,
            $dryRun,
        );
    }

    public static function check(SalesBoardCycle $cycle): SalesBoardStaleAssessment
    {
        return app(SalesBoardStaleDetectionService::class)->check($cycle->fresh());
    }

    public static function recalculate(
        SalesBoardCycle $cycle,
        string $reason = 'Conferência mensal.',
        ?User $actor = null,
        ?int $expectedBaselineId = null,
    ): SalesBoardRecalculationResult {
        return app(SalesBoardRecalculationService::class)->recalculate(
            $cycle->fresh(),
            $actor,
            $reason,
            $expectedBaselineId,
        );
    }

    public static function currentBaseline(SalesBoardCycle $cycle): SalesBoardCycleBaseline
    {
        return SalesBoardCycleBaseline::query()->findOrFail($cycle->fresh()->current_baseline_id);
    }
}
