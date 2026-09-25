<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\Enums\SalesBoardAutomationRunTrigger;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\ConfiguredSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Monta empreendimentos habilitados para a automação.
 *
 * Habilita **por configuração**, e por isso amarra explicitamente o provider de
 * configuração no container. Depois da Fase G o binding normal da aplicação é o
 * de banco -- rollout por Emissão --, e estes cenários continuam sendo o que
 * sempre foram: testes do **motor** da automação, dados alvos elegíveis. Quem
 * responde de onde a elegibilidade vem é a suíte da Fase G.
 *
 * Nenhum id real entra em arquivo versionado: cada teste declara quem quer ver
 * automatizado.
 */
final class AutomationFixture
{
    public const DEFAULT_MONTH = '2026-08-01';

    /**
     * Depois do dia 13 de setembro: a competência de agosto está devida.
     */
    public const AFTER_DUE_DATE = '2026-09-13';

    /**
     * Antes do dia 13: agosto ainda não venceu.
     */
    public const BEFORE_DUE_DATE = '2026-09-12';

    /**
     * Liga a automação para os empreendimentos indicados.
     *
     * @param  array<int, Construction|int>  $constructions
     */
    public static function enable(
        array $constructions,
        string $startReferenceMonth = self::DEFAULT_MONTH,
        bool $autoOpenBuilderReview = false,
    ): void {
        Config::set('sales_board.automation.enabled', true);

        app()->bind(
            SalesBoardAutomationEligibilityProvider::class,
            ConfiguredSalesBoardAutomationEligibilityProvider::class,
        );

        Config::set('sales_board.automation.targets', array_map(
            fn (Construction|int $construction): array => [
                'construction_id' => $construction instanceof Construction
                    ? (int) $construction->getKey()
                    : $construction,
                'start_reference_month' => $startReferenceMonth,
                'auto_open_builder_review' => $autoOpenBuilderReview,
            ],
            array_values($constructions),
        ));

        self::coverEmissionsFrom($constructions, $startReferenceMonth);
    }

    /**
     * Faz a Emissão de cada empreendimento cobrir a competência inicial.
     *
     * A geração só congela competência coberta pela automação da Emissão, e o
     * provider de configuração não olha a Emissão. Sem isto o motor descobriria
     * o alvo e a geração o recusaria. Uma Emissão que já cubra desde antes fica
     * como está: adiantar a competência inicial dela recusaria ciclos que o
     * cenário já gerou.
     *
     * @param  array<int, Construction|int>  $constructions
     */
    private static function coverEmissionsFrom(array $constructions, string $startReferenceMonth): void
    {
        $start = CarbonImmutable::parse($startReferenceMonth)->startOfMonth();

        $constructionIds = array_map(
            fn (Construction|int $construction): int => $construction instanceof Construction
                ? (int) $construction->getKey()
                : $construction,
            array_values($constructions),
        );

        $emissions = Emission::query()
            ->whereIn('id', Construction::query()->whereKey($constructionIds)->select('emission_id'))
            ->get();

        foreach ($emissions as $emission) {
            if ($emission->automationCovers($start)) {
                continue;
            }

            $emission->forceFill([
                'sales_board_source' => SalesBoardSource::Automated,
                'sales_board_automation_start_reference_month' => $start->toDateString(),
            ])->save();
        }
    }

    public static function disable(): void
    {
        Config::set('sales_board.automation.enabled', false);
        Config::set('sales_board.automation.targets', []);

        /**
         * Volta ao binding normal da aplicação -- o de banco --, para que um
         * teste que desabilite não herde o provider de configuração amarrado
         * por outro.
         */
        app()->bind(
            SalesBoardAutomationEligibilityProvider::class,
            DatabaseSalesBoardAutomationEligibilityProvider::class,
        );
    }

    /**
     * Um empreendimento cuja competência de agosto gera sem bloqueio.
     */
    public static function readyConstruction(string $unitPrefix = '1'): Construction
    {
        $construction = Construction::factory()->create([
            'emission_id' => Emission::factory()->withAutomatedSalesBoard(self::DEFAULT_MONTH)->create(['status' => 'active'])->id,
        ]);

        SalesDiscountPolicy::factory()->forConstruction($construction)
            ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

        DerivationFixture::unit($construction, $unitPrefix.'01');
        DerivationFixture::unit($construction, $unitPrefix.'02');

        return $construction;
    }

    /**
     * Um empreendimento cuja fonte impede a geração: unidade em estoque sem
     * valor vigente na data da posição.
     */
    public static function blockedConstruction(string $unitPrefix = '2'): Construction
    {
        $construction = Construction::factory()->create([
            'emission_id' => Emission::factory()->withAutomatedSalesBoard(self::DEFAULT_MONTH)->create(['status' => 'active'])->id,
        ]);

        SalesDiscountPolicy::factory()->forConstruction($construction)
            ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

        DerivationFixture::unit($construction, $unitPrefix.'01');

        ConstructionUnit::factory()->create([
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => $unitPrefix.'02',
            'base_value' => null,
            'base_value_reference_date' => null,
        ]);

        return $construction;
    }

    public static function run(
        string $asOf = self::AFTER_DUE_DATE,
        bool $dryRun = false,
        SalesBoardAutomationRunTrigger $trigger = SalesBoardAutomationRunTrigger::Scheduled,
    ): SalesBoardAutomationRun {
        return app(SalesBoardAutomationService::class)->run(
            trigger: $trigger,
            asOf: CarbonImmutable::parse($asOf)->startOfDay(),
            dryRun: $dryRun,
        );
    }
}
