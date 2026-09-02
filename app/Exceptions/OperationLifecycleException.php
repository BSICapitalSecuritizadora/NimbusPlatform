<?php

namespace App\Exceptions;

use App\Enums\OperationStatus;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusa de uma transição de estado da operação, ou de um trabalho novo que o
 * estado atual não comporta.
 *
 * É exceção de domínio, não de validação de formulário: chega tanto pela ação
 * explícita de lifecycle quanto por um payload manipulado que tentou criar
 * medição, responsabilidade ou delegação numa operação que não as aceita. Por
 * isso não é reportada -- é recusa esperada, não falha.
 */
class OperationLifecycleException extends RuntimeException implements ShouldntReport
{
    public static function invalidTransition(OperationStatus $from, OperationStatus $to): self
    {
        if ($from === $to) {
            return new self(sprintf('A operação já está em "%s".', $to->label()));
        }

        return new self(sprintf(
            'Uma operação em "%s" não pode passar para "%s".',
            $from->label(),
            $to->label(),
        ));
    }

    public static function openMeasurements(OperationStatus $to, int $count): self
    {
        return new self(sprintf(
            'A operação possui %d %s em andamento e não pode ser marcada como "%s". '
                .'Conclua ou recuse %s antes de encerrar a operação.',
            $count,
            $count === 1 ? 'medição' : 'medições',
            $to->label(),
            $count === 1 ? 'a medição' : 'as medições',
        ));
    }

    public static function reasonRequired(): self
    {
        return new self('Informe o motivo desta alteração de situação da operação.');
    }

    public static function reopenNotAllowed(): self
    {
        return new self('Reabrir uma operação encerrada é uma ação administrativa restrita a administradores.');
    }

    public static function newMeasurementsNotAllowed(OperationStatus $status): self
    {
        return new self(sprintf(
            'Uma operação em "%s" não recebe novas medições. Apenas operações em "%s" aceitam medição.',
            $status->label(),
            OperationStatus::Active->label(),
        ));
    }

    public static function responsibilityChangesNotAllowed(OperationStatus $status): self
    {
        return new self(sprintf(
            'Os responsáveis de uma operação em "%s" não podem ser alterados. '
                .'Reabra a operação para retomar a configuração.',
            $status->label(),
        ));
    }

    public static function newDelegationsNotAllowed(OperationStatus $status): self
    {
        return new self(sprintf(
            'Uma operação em "%s" não recebe novas delegações de responsabilidade. '
                .'As delegações já registradas permanecem no histórico.',
            $status->label(),
        ));
    }

    public static function measurementHistory(): self
    {
        return new self('Uma operação com medições registradas não pode ser excluída. Cancele a operação para encerrá-la.');
    }
}
