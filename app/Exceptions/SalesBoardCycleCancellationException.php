<?php

namespace App\Exceptions;

use App\Enums\SalesBoardCycleStatus;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do cancelamento de competência.
 *
 * Situações previsíveis -- a competência já foi publicada, já foi cancelada,
 * faltou o motivo --, e não defeitos: viram mensagem para quem está na tela.
 */
class SalesBoardCycleCancellationException extends RuntimeException implements ShouldntReport
{
    public static function actorRequired(): self
    {
        return new self('O cancelamento da competência exige um usuário identificado.');
    }

    public static function reasonRequired(int $minimumLength): self
    {
        return new self(sprintf(
            'Informe o motivo do cancelamento, com pelo menos %d caracteres. Ele fica registrado na competência e na auditoria.',
            $minimumLength,
        ));
    }

    public static function notCancellable(SalesBoardCycleStatus $status): self
    {
        return new self(match ($status) {
            SalesBoardCycleStatus::Approved => 'A competência já foi aprovada e publicada: o quadro publicado é imutável e ela não pode ser cancelada.',
            SalesBoardCycleStatus::Cancelled => 'A competência já está cancelada.',
            default => sprintf('A competência está em "%s" e não pode ser cancelada.', $status->label()),
        });
    }
}
