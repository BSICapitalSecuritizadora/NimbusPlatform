<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\Emission;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;

/**
 * Quais empreendimentos a automação pode processar, segundo o rollout.
 *
 * É a fonte de elegibilidade da aplicação: o binding de produção, lido pela
 * descoberta, pelo perímetro dos lembretes e pelo encerramento de alvos fora do
 * perímetro. O motor não sabe de onde vem a resposta -- ele só pergunta ao
 * contrato {@see SalesBoardAutomationEligibilityProvider} --, e é isso que deixa
 * os testes do motor trocarem a fonte sem tocar em descoberta, retry ou avisos.
 *
 * Cinco travas em série, e cada uma existe por um motivo diferente:
 *
 * 1. **o interruptor global** (`automation.enabled`) continua valendo, para
 *    incidente e deploy: desligado, o agendador não descobre, não tenta e não
 *    lembra nada. Ele **não** desliga o fluxo humano -- "Congelar competência"
 *    e a condução dos ciclos seguem disponíveis. O freio de uma Emissão é
 *    "Retornar ao modo legado";
 * 2. **o modo da Emissão** é a primeira trava de rollout. Legado devolve zero,
 *    mesmo que sobre uma competência inicial antiga por inconsistência;
 * 3. **a competência inicial** é obrigatória. Nulo nunca vira "desde sempre" --
 *    sem ela, ligar uma Emissão com anos de histórico dispararia dezenas de
 *    apurações que ninguém pediu;
 * 4. **o escopo homologado** precisa bater com os empreendimentos de hoje. Um
 *    empreendimento novo não entra sozinho numa Emissão já automatizada: o
 *    rollout é por Emissão inteira, e ninguém homologou aquele;
 * 5. **a Emissão liquidada** sai do perímetro: a operação foi encerrada e não
 *    há competência mensal a automatizar. É pelo perímetro que tudo para junto
 *    -- a descoberta não cria alvo novo, os abertos são encerrados com motivo
 *    próprio e os lembretes deixam de vê-la --, e o status é reversível: se a
 *    liquidação for desfeita, a descoberta reabre os alvos.
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
        if (! SalesBoardAutomationConfig::enabled()) {
            return [];
        }

        $emissions = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->whereNotNull('sales_board_automation_start_reference_month')
            ->where('status', '!=', Emission::STATUS_LIQUIDATED)
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
     * Só responde; não avisa nem registra. Esta pergunta é feita pela execução
     * horária e também por telas (a aba de pendências, o recorte dos lembretes),
     * e um `warning` aqui sairia a cada renderização. Quem avisa a suspensão --
     * aos responsáveis da Gestão e ao log, uma vez por situação -- é o
     * {@see SalesBoardAutomationSuspensionNotifier}, dentro da execução.
     *
     * @param  list<int>  $currentConstructionIds
     */
    private function scopeIsIntact(Emission $emission, array $currentConstructionIds): bool
    {
        $homologation = $emission->activeSalesBoardHomologation;

        if ($homologation === null || $currentConstructionIds === []) {
            return false;
        }

        return $this->assessment->scopeHash($currentConstructionIds) === (string) $homologation->construction_scope_hash;
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
