<?php

namespace App\Enums;

/**
 * Onde está a retificação de uma competência publicada.
 *
 * "Em retificação" não é status do ciclo: é a existência de uma retificação
 * aberta. O ciclo volta a "Gerado" e segue o fluxo de sempre, e esta linha diz
 * como o pedido terminou -- publicado pela aprovação da Gestão ou desistido,
 * com a posição publicada de antes valendo de novo.
 *
 * Os valores cabem em `varchar(20)`, a largura da coluna `status`, e
 * `aberta` é o literal da coluna gerada que garante uma aberta por ciclo: mudar
 * o valor é migração de dado.
 */
enum SalesBoardRectificationStatus: string
{
    case Open = 'aberta';

    case Published = 'publicada';

    case Abandoned = 'desistida';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em andamento',
            self::Published => 'Publicada',
            self::Abandoned => 'Desistida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Published => 'success',
            self::Abandoned => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
