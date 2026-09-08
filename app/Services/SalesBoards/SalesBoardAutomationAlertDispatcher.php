<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite um aviso no máximo uma vez por condição, destinatário e janela.
 *
 * O banco é quem decide quem envia: `insertOrIgnore` na chave de deduplicação
 * devolve 1 para quem inseriu e 0 para quem colidiu. Duas instâncias avaliando a
 * mesma condição no mesmo segundo produzem um aviso, não dois -- e a garantia
 * não depende de lock de scheduler.
 *
 * A chave é determinística e **não** inclui o instante: o scheduler roda de hora
 * em hora, e uma chave que muda a cada segundo deduplicaria exatamente nada.
 * O que entra é a janela relevante -- o dia civil de negócio -- junto da
 * entidade, do tipo e do destinatário.
 *
 * Falha de envio remove a linha e conta separado. É at-least-once assumido:
 * exactly-once com canal externo não existe, e fingir que existe apenas
 * transformaria um aviso perdido em aviso perdido *silencioso*.
 */
class SalesBoardAutomationAlertDispatcher
{
    /**
     * @var array{sent: int, deduped: int, without_recipient: int, failed: int}
     */
    private array $counters = [
        'sent' => 0,
        'deduped' => 0,
        'without_recipient' => 0,
        'failed' => 0,
    ];

    /**
     * @param  list<User>  $recipients
     * @param  array<string, int|string|null>  $anchors  colunas de âncora da tabela
     */
    public function dispatch(
        SalesBoardAutomationAlertType $type,
        array $recipients,
        array $anchors,
        string $window,
        string $constructionName,
        string $referenceMonth,
        ?string $detail = null,
    ): void {
        if ($recipients === []) {
            /**
             * Havia o que avisar e não havia a quem. Isso não derruba a
             * execução -- a geração da competência é independente do aviso --
             * mas não pode passar calado, que é como um alerta desaparece sem
             * ninguém notar.
             */
            $this->counters['without_recipient']++;

            Log::warning('Sales board automation alert has no resolvable recipient', [
                'event' => 'sales_board_automation_recipient_resolution_empty',
                'alert_type' => $type->value,
                'reference_month' => $referenceMonth,
            ] + array_filter($anchors, fn ($value): bool => $value !== null));

            return;
        }

        foreach ($recipients as $recipient) {
            $this->dispatchTo($type, $recipient, $anchors, $window, $constructionName, $referenceMonth, $detail);
        }
    }

    /**
     * @param  array<string, int|string|null>  $anchors
     */
    private function dispatchTo(
        SalesBoardAutomationAlertType $type,
        User $recipient,
        array $anchors,
        string $window,
        string $constructionName,
        string $referenceMonth,
        ?string $detail,
    ): void {
        $dedupeKey = $this->dedupeKey($type, $anchors, $window, (int) $recipient->getKey());
        $now = CarbonImmutable::now();

        $inserted = DB::table('sales_board_automation_alerts')->insertOrIgnore([
            'alert_type' => $type->value,
            'dedupe_key' => $dedupeKey,
            'sales_board_automation_target_id' => $anchors['sales_board_automation_target_id'] ?? null,
            'sales_board_cycle_id' => $anchors['sales_board_cycle_id'] ?? null,
            'sales_board_builder_review_id' => $anchors['sales_board_builder_review_id'] ?? null,
            'sales_board_management_review_id' => $anchors['sales_board_management_review_id'] ?? null,
            'recipient_user_id' => $recipient->getKey(),
            'sent_at' => $now,
            'created_at' => $now,
        ]) === 1;

        if (! $inserted) {
            $this->counters['deduped']++;

            return;
        }

        try {
            $recipient->notify(
                (new SalesBoardAutomationNotification($type, $constructionName, $referenceMonth, $detail))
                    ->afterCommit()
            );
        } catch (Throwable $exception) {
            /**
             * O registro é retirado para que a próxima execução tente de novo.
             * Manter a linha faria o aviso ser considerado enviado para sempre,
             * e ninguém receberia nada.
             */
            SalesBoardAutomationAlert::query()->where('dedupe_key', $dedupeKey)->delete();

            report($exception);
            $this->counters['failed']++;

            return;
        }

        $this->counters['sent']++;
    }

    /**
     * A chave determinística da condição.
     *
     * Entra tudo o que identifica "este aviso, sobre esta entidade, para esta
     * pessoa, nesta janela" -- e nada que mude sozinho com o relógio.
     *
     * @param  array<string, int|string|null>  $anchors
     */
    private function dedupeKey(
        SalesBoardAutomationAlertType $type,
        array $anchors,
        string $window,
        int $recipientId,
    ): string {
        ksort($anchors);

        return hash('sha256', implode('|', [
            $type->value,
            $window,
            $recipientId,
            json_encode($anchors),
        ]));
    }

    /**
     * @return array{sent: int, deduped: int, without_recipient: int, failed: int}
     */
    public function counters(): array
    {
        return $this->counters;
    }

    public function reset(): void
    {
        $this->counters = ['sent' => 0, 'deduped' => 0, 'without_recipient' => 0, 'failed' => 0];
    }
}
