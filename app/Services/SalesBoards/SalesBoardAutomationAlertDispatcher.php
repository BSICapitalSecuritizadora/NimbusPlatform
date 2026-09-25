<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite um aviso no máximo uma vez por condição, destinatário, janela e canal.
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
 * O livro-razão tem uma linha por canal (e-mail, sino do painel), e a chave
 * inclui o canal. Cada canal vai para a fila como um job próprio
 * ({@see SalesBoardAutomationNotification}), e cada um falha sozinho: o e-mail
 * recusado pelo SMTP devolve só a linha do e-mail, e o sino que já recebeu o
 * aviso não o recebe de novo na execução seguinte.
 *
 * Falha de envio remove a linha e conta separado. É at-least-once assumido:
 * exactly-once com canal externo não existe, e fingir que existe apenas
 * transformaria um aviso perdido em aviso perdido *silencioso*. Um SMTP fora do
 * ar não segura o tick do scheduler, e a falha de entrega no worker também
 * remove a linha do canal, para a execução seguinte tentar de novo.
 *
 * Os contadores contam avisos (condição × destinatário), não jobs: "enviado"
 * quer dizer **enfileirado** em pelo menos um canal -- a entrega acontece depois,
 * no worker, e a falha dela aparece na fila de jobs falhos e no aviso que volta
 * a ser devido. "Deduplicado" é o aviso cujos canais já tinham saído todos.
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
     * @param  string|null  $url  a tela em que a ação acontece, se houver
     */
    public function dispatch(
        SalesBoardAutomationAlertType $type,
        array $recipients,
        array $anchors,
        string $window,
        string $constructionName,
        string $referenceMonth,
        ?string $detail = null,
        ?string $url = null,
    ): void {
        if ($recipients === []) {
            /**
             * Havia o que avisar e não havia a quem. Isso não derruba a
             * execução -- a geração da competência é independente do aviso --
             * mas não pode passar calado, que é como um alerta desaparece sem
             * ninguém notar.
             *
             * O registro sai uma vez por condição e janela, como o próprio
             * aviso: o scheduler roda de hora em hora, e vinte e quatro linhas
             * iguais por dia afogam justamente o que deviam mostrar. A contagem
             * da execução continua subindo a cada vez -- ela é o fato da
             * execução; o log é o alarme.
             */
            $this->counters['without_recipient']++;

            $logKey = 'sales-board-automation:no-recipient:'.$this->dedupeKey($type, $anchors, $window, 0);

            if (Cache::add($logKey, true, now()->addHours(36))) {
                Log::warning('Sales board automation alert has no resolvable recipient', [
                    'event' => 'sales_board_automation_recipient_resolution_empty',
                    'alert_type' => $type->value,
                    'reference_month' => $referenceMonth,
                    'window' => $window,
                ] + array_filter($anchors, fn ($value): bool => $value !== null));
            }

            return;
        }

        foreach ($recipients as $recipient) {
            $this->dispatchTo($type, $recipient, $anchors, $window, $constructionName, $referenceMonth, $detail, $url);
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
        ?string $url,
    ): void {
        $queued = 0;
        $failed = 0;

        foreach (SalesBoardAutomationNotification::CHANNELS as $channel) {
            $dedupeKey = $this->dedupeKey($type, $anchors, $window, (int) $recipient->getKey(), $channel);

            if (! $this->claim($type, $dedupeKey, $channel, $anchors, $recipient)) {
                continue;
            }

            try {
                $recipient->notify(
                    (new SalesBoardAutomationNotification($type, $constructionName, $referenceMonth, $detail, $url, $dedupeKey, $channel))
                        ->afterCommit()
                );
            } catch (Throwable $exception) {
                /**
                 * O registro do canal é retirado para que a próxima execução
                 * tente de novo. Manter a linha faria o canal ser considerado
                 * entregue para sempre, e ninguém receberia nada por ele. O que
                 * chega aqui é a falha de enfileirar; a falha de entrega faz o
                 * mesmo em {@see SalesBoardAutomationNotification::failed()}.
                 */
                SalesBoardAutomationAlert::query()->where('dedupe_key', $dedupeKey)->delete();

                report($exception);
                $failed++;

                continue;
            }

            $queued++;
        }

        if ($queued > 0) {
            $this->counters['sent']++;
        }

        if ($failed > 0) {
            $this->counters['failed']++;
        }

        if ($queued === 0 && $failed === 0) {
            $this->counters['deduped']++;
        }
    }

    /**
     * Reserva o canal no livro-razão: 1 para quem inseriu (e deve enviar), 0
     * para quem colidiu (o canal já saiu, ou outra instância o está enviando).
     *
     * @param  array<string, int|string|null>  $anchors
     */
    private function claim(
        SalesBoardAutomationAlertType $type,
        string $dedupeKey,
        string $channel,
        array $anchors,
        User $recipient,
    ): bool {
        $now = CarbonImmutable::now();

        return DB::table('sales_board_automation_alerts')->insertOrIgnore([
            'alert_type' => $type->value,
            'dedupe_key' => $dedupeKey,
            'sales_board_automation_run_id' => $anchors['sales_board_automation_run_id'] ?? null,
            'emission_id' => $anchors['emission_id'] ?? null,
            'sales_board_automation_target_id' => $anchors['sales_board_automation_target_id'] ?? null,
            'sales_board_cycle_id' => $anchors['sales_board_cycle_id'] ?? null,
            'sales_board_builder_review_id' => $anchors['sales_board_builder_review_id'] ?? null,
            'sales_board_management_review_id' => $anchors['sales_board_management_review_id'] ?? null,
            'recipient_user_id' => $recipient->getKey(),
            'channel' => $channel,
            'sent_at' => $now,
            'created_at' => $now,
        ]) === 1;
    }

    /**
     * A chave determinística da condição.
     *
     * Entra tudo o que identifica "este aviso, sobre esta entidade, para esta
     * pessoa, nesta janela, por este canal" -- e nada que mude sozinho com o
     * relógio. Sem canal, é a chave da condição para o destinatário, usada no
     * registro de destinatário ausente.
     *
     * @param  array<string, int|string|null>  $anchors
     */
    private function dedupeKey(
        SalesBoardAutomationAlertType $type,
        array $anchors,
        string $window,
        int $recipientId,
        ?string $channel = null,
    ): string {
        ksort($anchors);

        $parts = [
            $type->value,
            $window,
            $recipientId,
            json_encode($anchors),
        ];

        if ($channel !== null) {
            $parts[] = $channel;
        }

        return hash('sha256', implode('|', $parts));
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
