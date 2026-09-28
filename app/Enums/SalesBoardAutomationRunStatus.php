<?php

namespace App\Enums;

/**
 * Como a execução terminou -- em termos técnicos, não operacionais.
 *
 * A distinção existe para o monitoramento não gritar pelo motivo errado. Uma
 * execução que encontrou dois empreendimentos com cadastro incompleto fez
 * exatamente o que devia: ela é `CompletedWithBlockers`, e o que precisa de
 * ação é o cadastro, não o scheduler. `CompletedWithFailures` é o alarme de
 * verdade, e `Failed` é a execução que não conseguiu nem descobrir os alvos.
 *
 * `Interrupted` é a execução que nunca chegou ao fim -- o processo morreu no meio
 * (falta de memória, deploy, reinício do contêiner). Ninguém consegue gravar o
 * próprio fim quando morre, então quem grava é a execução seguinte, ao encontrar
 * a linha ainda "executando" muito tempo depois do início.
 */
enum SalesBoardAutomationRunStatus: string
{
    case Running = 'executando';

    case Completed = 'concluido';

    case CompletedWithBlockers = 'concluido_com_bloqueios';

    case CompletedWithFailures = 'concluido_com_falhas';

    case Failed = 'falhou';

    case Interrupted = 'interrompido';

    /**
     * A execução chegou ao fim sem erro técnico do orquestrador.
     */
    public function isTechnicallyHealthy(): bool
    {
        return $this === self::Completed || $this === self::CompletedWithBlockers;
    }

    /**
     * O desfecho de uma execução que processou os alvos até o fim.
     *
     * Aviso que não conseguiu sair (`$alertsFailed`) também é falha técnica: um
     * SMTP fora do ar não é bloqueio de cadastro, e uma execução em que nenhum
     * aviso saiu não pode parecer saudável.
     */
    public static function fromCounters(int $failed, int $blocked, int $alertsFailed = 0): self
    {
        return match (true) {
            $failed > 0, $alertsFailed > 0 => self::CompletedWithFailures,
            $blocked > 0 => self::CompletedWithBlockers,
            default => self::Completed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Executando',
            self::Completed => 'Concluída',
            self::CompletedWithBlockers => 'Concluída com bloqueios',
            self::CompletedWithFailures => 'Concluída com falhas',
            self::Failed => 'Falhou',
            self::Interrupted => 'Interrompida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Completed => 'success',
            self::CompletedWithBlockers => 'warning',
            self::CompletedWithFailures, self::Failed, self::Interrupted => 'danger',
        };
    }
}
