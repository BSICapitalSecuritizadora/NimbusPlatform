<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\DTOs\SalesBoards\SalesBoardRecalculationResult;
use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
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

/**
 * Monta ciclos gerados sem repetir a mesma sequência em cada teste.
 *
 * Complementa {@see DerivationFixture}, que monta a fonte; aqui começa depois
 * dela, no ponto em que a competência é congelada.
 */
final class CycleFixture
{
    /**
     * Um empreendimento de emissão já ativa -- em elaboração nada é gerado.
     */
    public static function construction(string $status = 'active'): Construction
    {
        return Construction::factory()->create([
            'emission_id' => Emission::factory()->create(['status' => $status])->id,
        ]);
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
