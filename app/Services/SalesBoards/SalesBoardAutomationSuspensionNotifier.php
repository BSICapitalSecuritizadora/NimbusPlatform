<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\Emission;
use App\Support\SalesBoards\SalesBoardAutomationLinks;
use App\Support\SalesBoards\SalesBoardAutomationPerimeter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Avisa quando uma Emissão automatizada parou de ser atendida.
 *
 * Uma Emissão automatizada fica fora do perímetro por dois motivos, e cada um
 * tem aviso próprio:
 *
 * - **suspensão por mudança de escopo**: decisão correta do provider -- um
 *   empreendimento novo não entra sozinho numa Emissão homologada --, que até
 *   aqui só produzia um `Log::warning`, com a execução "concluída". Um
 *   empreendimento acrescentado à Emissão parava a automação da Emissão
 *   inteira sem que ninguém fosse avisado;
 * - **Emissão liquidada**: a operação foi encerrada e a automação parou de
 *   propósito. Chamá-la de "suspensa" seria um falso alarme -- não há escopo a
 *   corrigir --, e o que a Gestão precisa saber é que a automação acabou e que
 *   o fim do rollout se registra com "Retornar ao modo legado".
 *
 * Os dois avisos saem uma vez por situação, e não uma vez por dia. A janela da
 * suspensão é o escopo atual somado à homologação vigente: enquanto nada mudar,
 * o aviso não se repete; se alguém mexer no escopo de novo, a situação é outra
 * e o aviso sai outra vez. A da liquidação é a homologação vigente: uma vez por
 * ativação.
 */
class SalesBoardAutomationSuspensionNotifier
{
    public function __construct(
        private readonly SalesBoardAutomationRecipientResolver $recipients,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
        private readonly SalesBoardRolloutAssessmentService $assessment,
    ) {}

    /**
     * @return int quantas Emissões estão suspensas por mudança de escopo agora
     */
    public function notify(SalesBoardAutomationPerimeter $perimeter): int
    {
        $coveredEmissionIds = $perimeter->isEmpty()
            ? []
            : Construction::query()
                ->whereKey($perimeter->constructionIds())
                ->distinct()
                ->pluck('emission_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

        [$liquidated, $suspended] = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->whereNotNull('sales_board_automation_start_reference_month')
            ->when($coveredEmissionIds !== [], fn ($query) => $query->whereNotIn('id', $coveredEmissionIds))
            ->orderBy('id')
            ->get()
            ->partition(fn (Emission $emission): bool => $emission->isLiquidated());

        foreach ($liquidated as $emission) {
            $this->announceLiquidation($emission);
        }

        foreach ($suspended as $emission) {
            $constructionIds = Construction::query()
                ->where('emission_id', $emission->getKey())
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $window = sprintf(
                'escopo-%s-%d',
                $this->assessment->scopeHash($constructionIds),
                (int) $emission->sales_board_active_homologation_id,
            );

            if (Cache::add('sales-board-automation:scope-suspended:'.$emission->getKey().':'.$window, true, now()->addDays(30))) {
                Log::warning('Sales board automation suspended: emission scope differs from the homologation', [
                    'event' => 'sales_board_rollout_scope_changed',
                    'emission_id' => (int) $emission->getKey(),
                    'homologation_id' => $emission->sales_board_active_homologation_id,
                    'current_construction_count' => count($constructionIds),
                ]);
            }

            $this->alerts->dispatch(
                SalesBoardAutomationAlertType::ScopeSuspended,
                $this->recipients->forScopeSuspended($emission),
                ['emission_id' => (int) $emission->getKey()],
                $window,
                'Emissão '.$emission->name,
                '',
                'A automação desta Emissão está suspensa: os empreendimentos atuais não são os homologados, e nenhuma competência '
                    .'nova é gerada até que a Emissão volte ao modo legado e uma nova homologação seja aprovada e ativada.',
                SalesBoardAutomationLinks::rollout($emission->getKey()),
            );
        }

        return $suspended->count();
    }

    /**
     * A automação desta Emissão acabou porque ela foi liquidada.
     *
     * Uma vez por ativação: a janela é a homologação vigente. O registro no log
     * segue a mesma regra, com um marcador de 30 dias no cache, para a execução
     * horária não repetir a mesma linha o dia inteiro.
     */
    private function announceLiquidation(Emission $emission): void
    {
        $window = sprintf('liquidada-%d', (int) $emission->sales_board_active_homologation_id);

        if (Cache::add('sales-board-automation:liquidated:'.$emission->getKey().':'.$window, true, now()->addDays(30))) {
            Log::info('Sales board automation stopped: the emission was liquidated', [
                'event' => 'sales_board_automation_stopped_liquidated',
                'emission_id' => (int) $emission->getKey(),
                'homologation_id' => $emission->sales_board_active_homologation_id,
            ]);
        }

        $this->alerts->dispatch(
            SalesBoardAutomationAlertType::EmissionLiquidated,
            $this->recipients->forEmissionLiquidated($emission),
            ['emission_id' => (int) $emission->getKey()],
            $window,
            'Emissão '.$emission->name,
            '',
            'A Emissão foi liquidada: a automação deixou de gerar competências e de enviar lembretes para ela, e as '
                .'competências pendentes foram encerradas. Ciclos já gerados continuam em “Ciclos do Quadro” para serem '
                .'concluídos ou cancelados; para registrar o fim do rollout, use “Retornar ao modo legado”.',
            SalesBoardAutomationLinks::rollout($emission->getKey()),
        );
    }
}
