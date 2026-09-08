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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Os avisos sobre o que está parado esperando uma pessoa.
 *
 * Todos os limiares nascem `null`, e `null` desliga o aviso. O projeto não tem
 * SLA definido para o Quadro de Vendas, e escolher "três dias" aqui seria
 * inventar requisito de negócio dentro de um serviço -- onde ninguém procuraria
 * por ele quando quisesse mudar.
 *
 * A contagem é em dias civis corridos. Dias úteis exigiriam calendário, e o
 * calendário corporativo existe para prazos que alguém definiu como úteis;
 * aplicá-lo por conta própria seria a mesma invenção com mais passos.
 *
 * Nada aqui altera revisão, decide pendência ou aprova coisa alguma. O motor lê
 * estado e emite aviso; é a definição inteira do que ele faz.
 */
class SalesBoardAutomationReminderService
{
    public function __construct(
        private readonly SalesBoardAutomationRecipientResolver $recipients,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
    ) {}

    public function run(CarbonImmutable $now): void
    {
        $window = BusinessTime::dateString($now);

        $this->remindBlockedTargets($now, $window);
        $this->escalateFailedTargets($window);
        $this->announceCyclesReadyForBuilder($now, $window);
        $this->remindBuilderReviews($now, $window);
        $this->remindManagementReviews($now, $window);
    }

    /**
     * Alvos bloqueados há tempo demais: o dado que falta não apareceu sozinho.
     */
    private function remindBlockedTargets(CarbonImmutable $now, string $window): void
    {
        $threshold = $this->days('blocked_after_days');

        if ($threshold === null) {
            return;
        }

        SalesBoardAutomationTarget::query()
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
                );
            });
    }

    /**
     * Falhas técnicas que já se repetiram: deixou de ser transitório.
     */
    private function escalateFailedTargets(string $window): void
    {
        $threshold = $this->count('failed_after_attempts');

        if ($threshold === null) {
            return;
        }

        SalesBoardAutomationTarget::query()
            ->where('status', SalesBoardAutomationTargetStatus::Failed)
            ->where('attempt_count', '>=', $threshold)
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
    private function announceCyclesReadyForBuilder(CarbonImmutable $now, string $window): void
    {
        $threshold = $this->days('ready_for_builder_after_days');

        if ($threshold === null) {
            return;
        }

        SalesBoardCycle::query()
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
                );
            });
    }

    /**
     * Validações abertas e paradas com a construtora.
     */
    private function remindBuilderReviews(CarbonImmutable $now, string $window): void
    {
        $reminder = $this->days('builder_review_after_days');
        $escalation = $this->days('builder_review_escalation_after_days');

        if ($reminder === null && $escalation === null) {
            return;
        }

        SalesBoardBuilderReview::query()
            ->where('status', SalesBoardBuilderReviewStatus::Draft)
            ->with('cycle.construction')
            ->lazyById(100, 'id', 'id')
            ->each(function (SalesBoardBuilderReview $review) use ($now, $window, $reminder, $escalation): void {
                $openedAt = $review->opened_at;

                if ($openedAt === null) {
                    return;
                }

                $elapsed = $openedAt->diffInDays($now);

                /**
                 * Escalação primeiro: passado o prazo maior, o aviso brando não
                 * acrescenta nada e só duplicaria a mensagem.
                 */
                $type = match (true) {
                    $escalation !== null && $elapsed >= $escalation => SalesBoardAutomationAlertType::BuilderEscalation,
                    $reminder !== null && $elapsed >= $reminder => SalesBoardAutomationAlertType::BuilderReminder,
                    default => null,
                };

                if ($type === null) {
                    return;
                }

                $this->alerts->dispatch(
                    $type,
                    $this->recipients->forBuilderReminder($review),
                    [
                        'sales_board_builder_review_id' => (int) $review->getKey(),
                        'sales_board_cycle_id' => (int) $review->sales_board_cycle_id,
                    ],
                    $window,
                    (string) ($review->cycle?->construction?->development_name ?? '—'),
                    $review->cycle?->referenceMonthLabel() ?? '—',
                    sprintf('Validação aberta há %d dia(s).', (int) $elapsed),
                );
            });
    }

    /**
     * Competências entregues à Gestão e ainda sem decisão.
     */
    private function remindManagementReviews(CarbonImmutable $now, string $window): void
    {
        $reminder = $this->days('management_review_after_days');
        $escalation = $this->days('management_review_escalation_after_days');

        if ($reminder === null && $escalation === null) {
            return;
        }

        SalesBoardCycle::query()
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
                );
            });
    }

    private function days(string $key): ?int
    {
        $value = Config::get('sales_board.automation.reminders.'.$key);

        return blank($value) ? null : max(0, (int) $value);
    }

    private function count(string $key): ?int
    {
        $value = Config::get('sales_board.automation.reminders.'.$key);

        return blank($value) ? null : max(1, (int) $value);
    }
}
