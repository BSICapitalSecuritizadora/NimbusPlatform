<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardAutomationLinks;
use App\Support\SalesBoards\SalesBoardAutomationPerimeter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Os avisos sobre o que está parado esperando uma pessoa.
 *
 * Os limiares vêm do config, onde cada um tem padrão -- o SLA do Quadro,
 * decidido no pacote de conclusão -- sobrescrevível por variável de ambiente
 * ({@see SalesBoardAutomationConfig::DEFAULT_REMINDERS}). Aqui nada é escolhido:
 * o serviço lê o valor já interpretado, e `null` continua desligando o aviso
 * (é o que `off` e um valor ilegível produzem).
 *
 * A contagem é em dias civis corridos. Dias úteis exigiriam calendário, e o
 * calendário corporativo existe para prazos que alguém definiu como úteis;
 * aplicá-lo por conta própria seria inventar regra.
 *
 * Nada aqui altera revisão, decide pendência ou aprova coisa alguma. O motor lê
 * estado e emite aviso; é a definição inteira do que ele faz.
 *
 * Todo lembrete é recortado pelo perímetro atual da automação: só alvos e
 * ciclos de empreendimentos que a automação atende hoje, a partir da competência
 * em que ela passou a atendê-los. Sem o recorte, um alvo de Emissão devolvida ao
 * legado avisava todo dia para sempre, e um ciclo manual de Emissão legada
 * entrava no lembrete da Gestão -- sem destinatário, com um warning por hora.
 */
class SalesBoardAutomationReminderService
{
    public function __construct(
        private readonly SalesBoardAutomationRecipientResolver $recipients,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
    ) {}

    public function run(CarbonImmutable $now, ?SalesBoardAutomationPerimeter $perimeter = null): void
    {
        $window = BusinessTime::dateString($now);
        $perimeter ??= SalesBoardAutomationPerimeter::current();

        /**
         * Perímetro vazio não tem o que lembrar -- e nenhuma consulta abaixo
         * precisaria rodar para descobrir isso.
         */
        if ($perimeter->isEmpty()) {
            return;
        }

        $this->remindBlockedTargets($now, $window, $perimeter);
        $this->escalateFailedTargets($window, $perimeter);
        $this->announceCyclesReadyForBuilder($now, $window, $perimeter);
        $this->remindBuilderReviews($now, $window, $perimeter);
        $this->remindManagementReviews($now, $window, $perimeter);
    }

    /**
     * Alvos bloqueados há tempo demais: o dado que falta não apareceu sozinho.
     */
    private function remindBlockedTargets(CarbonImmutable $now, string $window, SalesBoardAutomationPerimeter $perimeter): void
    {
        $threshold = $this->days('blocked_after_days');

        if ($threshold === null) {
            return;
        }

        $perimeter->constrain(SalesBoardAutomationTarget::query())
            ->where('status', SalesBoardAutomationTargetStatus::Blocked)
            ->whereNotNull('first_attempt_at')
            ->where('first_attempt_at', '<=', $now->subDays($threshold))
            ->with('construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardAutomationTarget $target) use ($window): void {
                $this->alerts->dispatch(
                    SalesBoardAutomationAlertType::GenerationBlocked,
                    $this->recipients->forGenerationBlocked($target),
                    ['sales_board_automation_target_id' => (int) $target->getKey()],
                    $window,
                    (string) ($target->construction?->development_name ?? '—'),
                    $target->referenceMonthLabel(),
                    $target->last_blocker_message,
                    SalesBoardAutomationLinks::automationScreen(),
                );
            });
    }

    /**
     * Falhas técnicas que já se repetiram: deixou de ser transitório.
     *
     * Conta as falhas técnicas **consecutivas**, não as tentativas: um alvo
     * bloqueado por cinco dias não pode chegar à primeira falha técnica já
     * "repetida".
     */
    private function escalateFailedTargets(string $window, SalesBoardAutomationPerimeter $perimeter): void
    {
        $threshold = $this->count('failed_after_attempts');

        if ($threshold === null) {
            return;
        }

        $perimeter->constrain(SalesBoardAutomationTarget::query())
            ->where('status', SalesBoardAutomationTargetStatus::Failed)
            ->where('consecutive_failure_count', '>=', $threshold)
            ->with('construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardAutomationTarget $target) use ($window): void {
                $this->alerts->dispatch(
                    SalesBoardAutomationAlertType::GenerationFailed,
                    $this->recipients->forGenerationFailed($target),
                    ['sales_board_automation_target_id' => (int) $target->getKey()],
                    $window,
                    (string) ($target->construction?->development_name ?? '—'),
                    $target->referenceMonthLabel(),
                    $target->last_error_message,
                    SalesBoardAutomationLinks::automationScreen(),
                );
            });
    }

    /**
     * Competências apuradas e paradas: ninguém as enviou à construtora.
     *
     * É o aviso que existe justamente porque a abertura automática nasce
     * desligada -- gerar a posição é seguro, entregá-la a um terceiro depende de
     * um canal que ainda não existe.
     */
    private function announceCyclesReadyForBuilder(CarbonImmutable $now, string $window, SalesBoardAutomationPerimeter $perimeter): void
    {
        $threshold = $this->days('ready_for_builder_after_days');

        if ($threshold === null) {
            return;
        }

        $perimeter->constrain(SalesBoardCycle::query())
            ->where('status', SalesBoardCycleStatus::Generated)
            ->where('updated_at', '<=', $now->subDays($threshold))
            ->whereIn('id', SalesBoardAutomationTarget::query()
                ->whereNotNull('sales_board_cycle_id')
                ->select('sales_board_cycle_id'))
            ->with('construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardCycle $cycle) use ($window): void {
                $this->alerts->dispatch(
                    SalesBoardAutomationAlertType::ReadyForBuilder,
                    $this->recipients->forBuilderHandoff($cycle),
                    ['sales_board_cycle_id' => (int) $cycle->getKey()],
                    $window,
                    (string) ($cycle->construction?->development_name ?? '—'),
                    $cycle->referenceMonthLabel(),
                    'A posição está apurada e ainda não foi enviada à construtora.',
                    SalesBoardAutomationLinks::cycle($cycle->getKey()),
                );
            });
    }

    /**
     * Validações abertas e paradas com a construtora.
     *
     * Lembrete e escalação vão para pessoas diferentes: o lembrete ao
     * responsável operacional, que conduz a construtora; a escalação à Gestão.
     * Por isso, passado o prazo maior, o operacional continua recebendo o
     * lembrete -- a escalação não o substitui, ela acrescenta quem decide.
     */
    private function remindBuilderReviews(CarbonImmutable $now, string $window, SalesBoardAutomationPerimeter $perimeter): void
    {
        $reminder = $this->days('builder_review_after_days');
        $escalation = $this->days('builder_review_escalation_after_days');

        if ($reminder === null && $escalation === null) {
            return;
        }

        SalesBoardBuilderReview::query()
            ->where('status', SalesBoardBuilderReviewStatus::Draft)
            ->whereHas('cycle', fn (Builder $query): Builder => $perimeter->constrain($query))
            ->with('cycle.construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardBuilderReview $review) use ($now, $window, $reminder, $escalation): void {
                $openedAt = $review->opened_at;

                if ($openedAt === null) {
                    return;
                }

                $elapsed = $openedAt->diffInDays($now);
                $anchors = [
                    'sales_board_builder_review_id' => (int) $review->getKey(),
                    'sales_board_cycle_id' => (int) $review->sales_board_cycle_id,
                ];
                $constructionName = (string) ($review->cycle?->construction?->development_name ?? '—');
                $referenceMonth = $review->cycle?->referenceMonthLabel() ?? '—';
                $detail = sprintf('Validação aberta há %d dia(s).', (int) $elapsed);
                $url = SalesBoardAutomationLinks::cycle($review->sales_board_cycle_id);

                if ($reminder !== null && $elapsed >= $reminder) {
                    $this->alerts->dispatch(
                        SalesBoardAutomationAlertType::BuilderReminder,
                        $this->recipients->forBuilderReminder($review),
                        $anchors,
                        $window,
                        $constructionName,
                        $referenceMonth,
                        $detail,
                        $url,
                    );
                }

                if ($escalation !== null && $elapsed >= $escalation) {
                    $this->alerts->dispatch(
                        SalesBoardAutomationAlertType::BuilderEscalation,
                        $this->recipients->forBuilderEscalation($review),
                        $anchors,
                        $window,
                        $constructionName,
                        $referenceMonth,
                        $detail,
                        $url,
                    );
                }
            });
    }

    /**
     * Competências entregues à Gestão e ainda sem decisão.
     */
    private function remindManagementReviews(CarbonImmutable $now, string $window, SalesBoardAutomationPerimeter $perimeter): void
    {
        $reminder = $this->days('management_review_after_days');
        $escalation = $this->days('management_review_escalation_after_days');

        if ($reminder === null && $escalation === null) {
            return;
        }

        $perimeter->constrain(SalesBoardCycle::query())
            ->where('status', SalesBoardCycleStatus::ManagementReview)
            ->with('construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardCycle $cycle) use ($now, $window, $reminder, $escalation): void {
                $elapsed = CarbonImmutable::parse($cycle->updated_at)->diffInDays($now);

                $type = match (true) {
                    $escalation !== null && $elapsed >= $escalation => SalesBoardAutomationAlertType::ManagementEscalation,
                    $reminder !== null && $elapsed >= $reminder => SalesBoardAutomationAlertType::ManagementReminder,
                    default => null,
                };

                if ($type === null) {
                    return;
                }

                $this->alerts->dispatch(
                    $type,
                    $this->recipients->forManagementReminder($cycle),
                    ['sales_board_cycle_id' => (int) $cycle->getKey()],
                    $window,
                    (string) ($cycle->construction?->development_name ?? '—'),
                    $cycle->referenceMonthLabel(),
                    sprintf('Em análise da Gestão há %d dia(s).', (int) $elapsed),
                    SalesBoardAutomationLinks::cycle($cycle->getKey()),
                );
            });
    }

    /**
     * Limiar ilegível é limiar ausente: `(int) 'abc'` seria `0`, e zero dias
     * aqui quer dizer "avisar agora" -- o oposto de desligado.
     */
    private function days(string $key): ?int
    {
        return SalesBoardAutomationConfig::reminderThreshold($key);
    }

    private function count(string $key): ?int
    {
        $value = SalesBoardAutomationConfig::reminderThreshold($key);

        return $value === null ? null : max(1, $value);
    }
}
