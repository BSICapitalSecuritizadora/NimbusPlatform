<?php

namespace App\Enums;

/**
 * O estado atual da automação para uma competência de um empreendimento.
 *
 * Cinco estados, e a distinção que mais importa é `Blocked` contra `Failed`.
 * Bloqueado é o domínio funcionando: a derivação rodou e disse que falta dado.
 * Falho é o inesperado -- conexão caída, erro de programação. Misturá-los faria
 * a métrica de saúde da automação acusar incidente técnico toda vez que um
 * empreendimento estivesse com cadastro incompleto, que é a situação normal
 * antes do fechamento.
 *
 * `AlreadyExists` não é estado: é *como* o alvo foi satisfeito, e vive em
 * {@see SalesBoardAutomationSatisfiedVia}.
 *
 * `Closed` é o outro fim possível: a competência saiu do perímetro da automação
 * -- a Emissão voltou ao legado, ou o escopo homologado mudou -- e a automação
 * deixou de responder por ela. Não é sucesso nem falha, e por isso não é
 * tentado, não é lembrado e não conta como pendente. Se a competência voltar ao
 * perímetro (nova ativação cobrindo o mesmo mês), a descoberta reabre o alvo.
 */
enum SalesBoardAutomationTargetStatus: string
{
    case Pending = 'pendente';

    case Blocked = 'bloqueado';

    case Failed = 'falhou';

    case Satisfied = 'satisfeito';

    case Closed = 'encerrado';

    /**
     * O alvo ainda pede alguma coisa da automação.
     */
    public function isOpen(): bool
    {
        return $this !== self::Satisfied && $this !== self::Closed;
    }

    /**
     * Os estados que ainda pedem alguma coisa da automação.
     *
     * @return list<self>
     */
    public static function openCases(): array
    {
        return [self::Pending, self::Blocked, self::Failed];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Blocked => 'Bloqueado pela fonte',
            self::Failed => 'Falha técnica',
            self::Satisfied => 'Satisfeito',
            self::Closed => 'Encerrado (fora da automação)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Blocked => 'warning',
            self::Failed => 'danger',
            self::Satisfied => 'success',
            self::Closed => 'gray',
        };
    }
}
