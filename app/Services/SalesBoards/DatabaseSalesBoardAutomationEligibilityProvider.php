<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Quais empreendimentos a automação pode processar, segundo o rollout.
 *
 * Substitui o provider de configuração da Fase F, que era declaradamente
 * temporário. É a única peça que a Fase G precisou trocar para a automação
 * passar a enxergar Emissões reais: descoberta, retry, alertas e
 * observabilidade continuam exatamente como estavam.
 *
 * Quatro travas em série, e cada uma existe por um motivo diferente:
 *
 * 1. **o interruptor global** (`automation.enabled`) continua valendo, para
 *    incidente e deploy -- uma Emissão automatizada com o motor desligado não
 *    processa nada;
 * 2. **o modo da Emissão** é a primeira trava de rollout. Legado devolve zero,
 *    mesmo que sobre uma competência inicial antiga por inconsistência;
 * 3. **a competência inicial** é obrigatória. Nulo nunca vira "desde sempre" --
 *    sem ela, ligar uma Emissão com anos de histórico dispararia dezenas de
 *    apurações que ninguém pediu;
 * 4. **o escopo homologado** precisa bater com os empreendimentos de hoje. Um
 *    empreendimento novo não entra sozinho numa Emissão já automatizada: o
 *    rollout é por Emissão inteira, e ninguém homologou aquele.
 *
 * O escopo divergente suspende a Emissão **inteira**, e não apenas o
 * empreendimento novo. Continuar automatizando os antigos seria rollout parcial
 * -- exatamente o que a fase proíbe -- e produziria uma Emissão em que metade
 * das competências sai pelo motor e metade não sai de lugar nenhum.
 */
class DatabaseSalesBoardAutomationEligibilityProvider implements SalesBoardAutomationEligibilityProvider
{
    public function __construct(
        private readonly SalesBoardRolloutAssessmentService $assessment,
    ) {}

    public function eligibleTargets(): array
    {
        if (! Config::get('sales_board.automation.enabled', false)) {
            return [];
        }

        $emissions = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->whereNotNull('sales_board_automation_start_reference_month')
            ->with('activeSalesBoardHomologation')
            ->get();

        if ($emissions->isEmpty()) {
            return [];
        }

        /**
         * Os empreendimentos de todas as Emissões numa consulta só. Uma consulta
         * por Emissão transformaria a descoberta horária em dezenas de idas ao
         * banco antes de qualquer apuração.
         */
        $constructions = Construction::query()
            ->whereIn('emission_id', $emissions->modelKeys())
            ->orderBy('id')
            ->get(['id', 'emission_id'])
            ->groupBy(fn (Construction $construction): int => (int) $construction->emission_id);

        $targets = [];

        foreach ($emissions as $emission) {
            $current = $constructions->get((int) $emission->getKey(), collect())
                ->map(fn (Construction $construction): int => (int) $construction->getKey())
                ->values()
                ->all();

            if (! $this->scopeIsIntact($emission, $current)) {
                continue;
            }

            $start = CarbonImmutable::parse(
                $emission->sales_board_automation_start_reference_month->toDateString()
            )->startOfMonth();

            foreach ($current as $constructionId) {
                $targets[] = new SalesBoardAutomationEligibleTarget(
                    constructionId: $constructionId,
                    startReferenceMonth: $start,
                    autoOpenBuilderReview: (bool) $emission->sales_board_auto_open_builder_review,
                );
            }
        }

        return $targets;
    }

    /**
     * O conjunto de empreendimentos ainda é o que foi homologado?
     *
     * O aviso é `warning` e sai uma vez por execução da descoberta -- o
     * scheduler roda de hora em hora, e registrar o mesmo desvio a cada hora
     * afogaria o log. A tela de rollout mostra a situação de forma permanente.
     *
     * @param  list<int>  $currentConstructionIds
     */
    private function scopeIsIntact(Emission $emission, array $currentConstructionIds): bool
    {
        $homologation = $emission->activeSalesBoardHomologation;

        if ($homologation === null) {
            Log::warning('Sales board rollout has no active homologation', [
                'event' => 'sales_board_rollout_without_homologation',
                'emission_id' => (int) $emission->getKey(),
            ]);

            return false;
        }

        if ($currentConstructionIds === []) {
            return false;
        }

        $currentHash = $this->assessment->scopeHash($currentConstructionIds);

        if ($currentHash === (string) $homologation->construction_scope_hash) {
            return true;
        }

        Log::warning('Sales board rollout scope changed since homologation', [
            'event' => 'sales_board_rollout_scope_changed',
            'emission_id' => (int) $emission->getKey(),
            'homologation_id' => (int) $homologation->getKey(),
            'current_construction_count' => count($currentConstructionIds),
        ]);

        return false;
    }

    /**
     * A Emissão está suspensa por alteração de escopo?
     *
     * A tela pergunta isso para explicar por que uma Emissão automatizada parou
     * de produzir competências. Sem essa resposta, a única pista seria uma lista
     * de alvos que não cresce.
     */
    public function hasScopeDrift(Emission $emission): bool
    {
        if (! $emission->usesAutomatedSalesBoard()) {
            return false;
        }

        $homologation = $emission->activeSalesBoardHomologation;

        if ($homologation === null) {
            return true;
        }

        $current = $this->assessment->constructionsOf($emission)->keys()->all();

        return $this->assessment->scopeHash($current) !== (string) $homologation->construction_scope_hash;
    }
}
