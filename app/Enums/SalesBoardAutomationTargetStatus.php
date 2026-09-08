<?php

namespace App\Enums;

/**
 * O estado atual da automação para uma competência de um empreendimento.
 *
 * Quatro estados, e a distinção que mais importa é `Blocked` contra `Failed`.
 * Bloqueado é o domínio funcionando: a derivação rodou e disse que falta dado.
 * Falho é o inesperado -- conexão caída, erro de programação. Misturá-los faria
 * a métrica de saúde da automação acusar incidente técnico toda vez que um
 * empreendimento estivesse com cadastro incompleto, que é a situação normal
 * antes do fechamento.
 *
 * `AlreadyExists` não é estado: é *como* o alvo foi satisfeito, e vive em
 * {@see SalesBoardAutomationSatisfiedVia}.
 */
enum SalesBoardAutomationTargetStatus: string
{
    case Pending = 'pendente';

    case Blocked = 'bloqueado';

    case Failed = 'falhou';

    case Satisfied = 'satisfeito';

    /**
     * O alvo ainda pede alguma coisa da automação.
     */
    public function isOpen(): bool
    {
        return $this !== self::Satisfied;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Blocked => 'Bloqueado pela fonte',
            self::Failed => 'Falha técnica',
            self::Satisfied => 'Satisfeito',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Blocked => 'warning',
            self::Failed => 'danger',
            self::Satisfied => 'success',
        };
    }
}
