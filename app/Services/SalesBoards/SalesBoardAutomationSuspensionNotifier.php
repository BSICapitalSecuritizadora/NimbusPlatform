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
 * A suspensão por mudança de escopo é uma decisão correta do provider -- um
 * empreendimento novo não entra sozinho numa Emissão homologada --, mas até aqui
 * ela só produzia um `Log::warning`, e a execução seguia "concluída". Um
 * empreendimento acrescentado à Emissão parava a automação da Emissão inteira
 * sem que ninguém fosse avisado.
 *
 * O aviso sai uma vez por situação, e não uma vez por dia: a janela de
 * deduplicação é o escopo atual somado à homologação vigente. Enquanto nada
 * mudar, o aviso não se repete; se alguém mexer no escopo de novo, a situação é
 * outra e o aviso sai outra vez.
 */
class SalesBoardAutomationSuspensionNotifier
{
    public function __construct(
        private readonly SalesBoardAutomationRecipientResolver $recipients,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
        private readonly SalesBoardRolloutAssessmentService $assessment,
    ) {}

    /**
     * @return int quantas Emissões estão suspensas agora
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

        $suspended = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->whereNotNull('sales_board_automation_start_reference_month')
            ->when($coveredEmissionIds !== [], fn ($query) => $query->whereNotIn('id', $coveredEmissionIds))
            ->orderBy('id')
            ->get();

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
}
