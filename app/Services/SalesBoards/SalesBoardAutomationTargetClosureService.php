<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Support\Dates\InclusiveDateBound;
use App\Support\SalesBoards\SalesBoardAutomationPerimeter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Encerra os alvos que saíram do perímetro da automação.
 *
 * Sem isto, um alvo bloqueado de uma Emissão devolvida ao legado -- ou suspensa
 * por mudança de escopo -- ficava aberto para sempre: nunca mais era tentado
 * (a descoberta não o via), nunca era satisfeito, continuava contando em
 * "Pendentes de ação" e, com o lembrete ligado, avisava todo dia sobre uma
 * competência que a automação não atende mais. E a tela não tinha como fechá-lo.
 *
 * Encerrar não apaga nada e não mexe em ciclo nenhum: muda o estado do alvo
 * para `Closed`, com quando, por quê e -- quando houver -- por quem. As
 * tentativas continuam inteiras. Se a competência voltar ao perímetro, a
 * descoberta reabre o alvo.
 *
 * A escrita é um `UPDATE` condicional ao estado aberto, e não leitura seguida de
 * gravação: um alvo que outra instância esteja gerando agora está travado por
 * ela, e o `UPDATE` espera o commit e reavalia a condição -- um alvo que acabou
 * de ser satisfeito não é encerrado por cima.
 */
class SalesBoardAutomationTargetClosureService
{
    /**
     * Encerra os alvos abertos dos empreendimentos de uma Emissão.
     *
     * Chamado de dentro da transação de quem decidiu -- o retorno ao legado --,
     * para que modo da Emissão e estado dos alvos mudem juntos ou não mudem.
     */
    public function closeForEmission(
        Emission $emission,
        SalesBoardAutomationClosureReason $reason,
        string $message,
        ?User $actor,
    ): int {
        $constructionIds = Construction::query()
            ->where('emission_id', $emission->getKey())
            ->pluck('id')
            ->all();

        if ($constructionIds === []) {
            return 0;
        }

        $closed = $this->close(
            SalesBoardAutomationTarget::query()->whereIn('construction_id', $constructionIds),
            $reason,
            $message,
            $actor,
        );

        if ($closed > 0) {
            Log::info('Sales board automation targets closed', [
                'event' => 'sales_board_automation_targets_closed',
                'emission_id' => (int) $emission->getKey(),
                'reason' => $reason->value,
                'closed' => $closed,
                'actor_user_id' => $actor?->getKey(),
            ]);
        }

        return $closed;
    }

    /**
     * Encerra o alvo da competência que a Gestão cancelou.
     *
     * Ao contrário dos outros encerramentos, alcança também o alvo satisfeito:
     * o normal é o ciclo já existir, e é justamente ele que está sendo
     * cancelado. Chamado de dentro da transação do cancelamento.
     *
     * A descoberta não desfaz este encerramento. Quem o desfaz é a reabertura
     * da competência pela Gestão ({@see self::reopenForReopenedCycle()}).
     */
    public function closeForCancelledCycle(SalesBoardCycle $cycle, string $message, User $actor): int
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();

        return SalesBoardAutomationTarget::query()
            ->where('construction_id', $cycle->construction_id)
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)])
            ->where(function (Builder $query): void {
                $query->where('status', '!=', SalesBoardAutomationTargetStatus::Closed->value)
                    ->orWhereNull('closure_reason')
                    ->orWhere('closure_reason', '!=', SalesBoardAutomationClosureReason::CompetenceCancelled->value);
            })
            ->update([
                'status' => SalesBoardAutomationTargetStatus::Closed->value,
                'next_attempt_at' => null,
                'closed_at' => CarbonImmutable::now(),
                'closure_reason' => SalesBoardAutomationClosureReason::CompetenceCancelled->value,
                'closure_message' => mb_substr($message, 0, 2000),
                'closed_by_user_id' => $actor->getKey(),
            ]);
    }

    /**
     * Devolve à situação de satisfeito o alvo encerrado pelo cancelamento de
     * uma competência que a Gestão reabriu.
     *
     * A competência voltou a ter ciclo em andamento -- o mesmo ciclo --, e a
     * automação volta a responder por ela como respondia antes do cancelamento:
     * satisfeita por esse ciclo. `satisfied_via` mantém o que já estava gravado
     * e, no alvo que nunca chegou a ser satisfeito (o cancelamento veio antes
     * do dia 13), passa a "já existente", que é como o ciclo chegou a ele.
     *
     * As colunas de encerramento ficam como estão: são o registro do último
     * encerramento, do mesmo jeito que a reabertura pela descoberta as
     * preserva. Só alvos encerrados por cancelamento são tocados -- um alvo
     * encerrado por retorno ao legado ou por escopo continua encerrado, porque
     * a reabertura não muda o perímetro.
     *
     * Chamado de dentro da transação da reabertura.
     */
    public function reopenForReopenedCycle(SalesBoardCycle $cycle): int
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();

        return SalesBoardAutomationTarget::query()
            ->where('construction_id', $cycle->construction_id)
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)])
            ->where('status', SalesBoardAutomationTargetStatus::Closed->value)
            ->where('closure_reason', SalesBoardAutomationClosureReason::CompetenceCancelled->value)
            ->update([
                'status' => SalesBoardAutomationTargetStatus::Satisfied->value,
                'satisfied_via' => DB::raw(sprintf(
                    "COALESCE(satisfied_via, '%s')",
                    SalesBoardAutomationSatisfiedVia::Existing->value,
                )),
                'sales_board_cycle_id' => $cycle->getKey(),
                'next_attempt_at' => null,
                'last_outcome_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * Encerra os alvos abertos que não pertencem mais ao perímetro.
     *
     * O motivo é apurado por empreendimento, na Emissão de hoje: a suspensão por
     * escopo pede nova homologação, a Emissão fora do modo automatizado pede
     * outra coisa, a Emissão liquidada não pede nada, e uma mensagem genérica
     * esconderia qual das três.
     *
     * @return array<string, int> quantos alvos foram encerrados, por motivo
     */
    public function closeOutsidePerimeter(SalesBoardAutomationPerimeter $perimeter): array
    {
        $outside = $perimeter->exclude(
            SalesBoardAutomationTarget::query()->whereIn('status', SalesBoardAutomationTargetStatus::openCases())
        )->get(['id', 'construction_id', 'reference_month']);

        if ($outside->isEmpty()) {
            return [];
        }

        $constructions = Construction::query()
            ->whereKey($outside->pluck('construction_id')->unique()->all())
            ->with('emission')
            ->get()
            ->keyBy('id');

        $groups = [];

        foreach ($outside as $target) {
            [$reason, $message] = $this->reasonFor(
                $constructions->get($target->construction_id),
                CarbonImmutable::parse($target->reference_month->toDateString()),
            );

            $groups[$reason->value.'|'.$message]['reason'] = $reason;
            $groups[$reason->value.'|'.$message]['message'] = $message;
            $groups[$reason->value.'|'.$message]['ids'][] = (int) $target->getKey();
        }

        $closed = [];

        foreach ($groups as $group) {
            $count = $this->close(
                SalesBoardAutomationTarget::query()->whereKey($group['ids']),
                $group['reason'],
                $group['message'],
                null,
            );

            $closed[$group['reason']->value] = ($closed[$group['reason']->value] ?? 0) + $count;
        }

        if (array_sum($closed) > 0) {
            Log::info('Sales board automation targets closed', [
                'event' => 'sales_board_automation_targets_closed',
                'reason' => 'outside_perimeter',
                'closed' => $closed,
            ]);
        }

        return $closed;
    }

    /**
     * Por que o empreendimento saiu do perímetro nesta competência.
     *
     * @return array{0: SalesBoardAutomationClosureReason, 1: string}
     */
    private function reasonFor(?Construction $construction, CarbonImmutable $referenceMonth): array
    {
        $emission = $construction?->emission;

        return match (true) {
            ! $emission instanceof Emission => [
                SalesBoardAutomationClosureReason::OutsidePerimeter,
                'O empreendimento não pertence mais a uma Emissão automatizada.',
            ],
            ! $emission->usesAutomatedSalesBoard() => [
                SalesBoardAutomationClosureReason::OutsidePerimeter,
                'A Emissão do empreendimento não está no modo automatizado: a competência deixou de ser gerada pela automação.',
            ],
            $emission->isLiquidated() => [
                SalesBoardAutomationClosureReason::EmissionLiquidated,
                'A Emissão foi liquidada: a automação deixou de gerar competências para ela. '
                    .'Se a liquidação for desfeita, a competência volta a ser tentada.',
            ],
            ! $emission->automationCovers($referenceMonth) => [
                SalesBoardAutomationClosureReason::OutsidePerimeter,
                'A competência é anterior ao início da automação da Emissão.',
            ],
            default => [
                SalesBoardAutomationClosureReason::ScopeSuspended,
                'A automação da Emissão está suspensa: os empreendimentos atuais não são os homologados. '
                    .'Para retomá-la, volte a Emissão ao modo legado e abra uma nova homologação.',
            ],
        };
    }

    /**
     * @param  Builder<SalesBoardAutomationTarget>  $query
     */
    private function close(
        Builder $query,
        SalesBoardAutomationClosureReason $reason,
        string $message,
        ?User $actor,
    ): int {
        return $query
            ->whereIn('status', SalesBoardAutomationTargetStatus::openCases())
            ->update([
                'status' => SalesBoardAutomationTargetStatus::Closed->value,
                'next_attempt_at' => null,
                'closed_at' => CarbonImmutable::now(),
                'closure_reason' => $reason->value,
                'closure_message' => $message,
                'closed_by_user_id' => $actor?->getKey(),
            ]);
    }
}
