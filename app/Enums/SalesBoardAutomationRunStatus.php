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
 */
enum SalesBoardAutomationRunStatus: string
{
    case Running = 'executando';

    case Completed = 'concluido';

    case CompletedWithBlockers = 'concluido_com_bloqueios';

    case CompletedWithFailures = 'concluido_com_falhas';

    case Failed = 'falhou';

    /**
     * A execução chegou ao fim sem erro técnico do orquestrador.
     */
    public function isTechnicallyHealthy(): bool
    {
        return $this === self::Completed || $this === self::CompletedWithBlockers;
    }

    /**
     * O desfecho de uma execução que processou os alvos até o fim.
     */
    public static function fromCounters(int $failed, int $blocked): self
    {
        return match (true) {
            $failed > 0 => self::CompletedWithFailures,
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
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Completed => 'success',
            self::CompletedWithBlockers => 'warning',
            self::CompletedWithFailures, self::Failed => 'danger',
        };
    }
}
