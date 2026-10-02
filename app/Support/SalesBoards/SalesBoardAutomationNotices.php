<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardSource;
use App\Models\Emission;

/**
 * O que as telas da automação precisam dizer, mesmo sem nada ter falhado.
 *
 * Duas decisões do pacote de conclusão do Quadro moram aqui como texto de tela,
 * porque quem ativa e acompanha a automação precisa vê-las em vez de procurá-las
 * num arquivo de configuração:
 *
 * - **o SLA dos lembretes** tem padrão, sobrescrevível por ambiente. A tela
 *   mostra a política vigente item a item ({@see self::reminderPolicy()}), com
 *   "desligado" onde for o caso -- é assim que um valor ilegível, que desliga o
 *   lembrete, aparece para alguém. Com os sete desligados, as telas avisam que
 *   ninguém será lembrado de competência parada;
 * - **a Emissão liquidada** sai do perímetro da automação: nenhuma competência
 *   nova é gerada e os lembretes param. A tela diz isso, para a lista de alvos
 *   que parou de crescer não parecer defeito.
 */
final class SalesBoardAutomationNotices
{
    /**
     * Os avisos do cabeçalho do rollout de uma Emissão.
     *
     * @return list<string>
     */
    public static function forRollout(Emission $emission): array
    {
        $notices = [];

        if (SalesBoardAutomationConfig::allRemindersOff()) {
            $notices[] = self::remindersOffNotice();
        }

        if ($emission->usesAutomatedSalesBoard() && $emission->isLiquidated()) {
            $notices[] = 'Esta Emissão está liquidada: a automação parou de gerar competências e de enviar lembretes para ela. '
                .'Os ciclos já gerados seguem em “Ciclos do Quadro”.';
        }

        return $notices;
    }

    /**
     * Os avisos da tela "Automação do Quadro".
     *
     * @return list<string>
     */
    public static function forAutomationScreen(): array
    {
        $notices = [];

        if (SalesBoardAutomationConfig::allRemindersOff()) {
            $notices[] = self::remindersOffNotice();
        }

        $liquidated = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->where('status', Emission::STATUS_LIQUIDATED)
            ->count();

        if ($liquidated > 0) {
            $notices[] = sprintf(
                '%d Emissão(ões) liquidada(s) no modo automatizado: a automação parou de gerar competências para ela(s) e encerrou as pendentes.',
                $liquidated,
            );
        }

        return $notices;
    }

    /**
     * A política de avisos em vigor, numa frase.
     *
     * Lida do config já interpretado, item a item: um limiar desligado -- por
     * `off` ou por valor ilegível -- aparece como "desligado", e zero como "no
     * mesmo dia". A frase existe porque a política tem padrão e pode ser
     * sobrescrita no ambiente: sem ela, ninguém na tela saberia o que de fato
     * está valendo.
     */
    public static function reminderPolicy(): string
    {
        $setting = fn (string $key): ?int => SalesBoardAutomationConfig::reminderThreshold($key);

        $failed = $setting('failed_after_attempts');

        $items = [
            self::singleItem('bloqueio pela fonte', $setting('blocked_after_days')),
            $failed === null
                ? 'falha técnica: desligado'
                : sprintf('falha técnica a partir de %s', self::failures(max(1, $failed))),
            self::singleItem('posição pronta para a construtora', $setting('ready_for_builder_after_days')),
            self::pairItem(
                'validação da construtora',
                $setting('builder_review_after_days'),
                $setting('builder_review_escalation_after_days'),
            ),
            self::pairItem(
                'análise da Gestão',
                $setting('management_review_after_days'),
                $setting('management_review_escalation_after_days'),
            ),
        ];

        return sprintf(
            'Avisos: %s (dias corridos). Execução interrompida, suspensão por escopo e automação encerrada por liquidação são avisadas sempre.',
            implode('; ', $items),
        );
    }

    private static function remindersOffNotice(): string
    {
        return 'Os lembretes de prazo da automação estão todos desligados: os responsáveis só recebem aviso de execução '
            .'interrompida, de suspensão por mudança de escopo e de automação encerrada por liquidação, nunca de competência parada.';
    }

    private static function singleItem(string $label, ?int $days): string
    {
        return $days === null
            ? $label.': desligado'
            : $label.' '.self::days($days);
    }

    /**
     * Lembrete e escalação do mesmo prazo. A unidade da escalação fica
     * implícita quando o lembrete já a disse ("com 5 dias e escalação com 10").
     */
    private static function pairItem(string $label, ?int $reminder, ?int $escalation): string
    {
        $reminderText = $reminder === null ? 'lembrete desligado' : 'lembrete '.self::days($reminder);

        $escalationText = match (true) {
            $escalation === null => 'escalação desligada',
            $escalation > 0 && ($reminder ?? 0) > 0 => sprintf('escalação com %d', $escalation),
            default => 'escalação '.self::days($escalation),
        };

        return sprintf('%s — %s e %s', $label, $reminderText, $escalationText);
    }

    private static function days(int $days): string
    {
        return match (true) {
            $days === 0 => 'no mesmo dia',
            $days === 1 => 'com 1 dia',
            default => sprintf('com %d dias', $days),
        };
    }

    private static function failures(int $failures): string
    {
        return $failures === 1 ? '1 falha' : sprintf('%d falhas seguidas', $failures);
    }
}
