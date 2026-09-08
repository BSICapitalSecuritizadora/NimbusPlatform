<?php

namespace App\Enums;

/**
 * Em que ponto está a homologação de uma Emissão.
 *
 * Quatro estados, e nenhum deles é "ativada". Aprovar e ativar são atos
 * diferentes, com pré-condições diferentes e momentos diferentes: entre um e
 * outro a fonte pode mudar, um quadro manual pode aparecer, um empreendimento
 * pode entrar na Emissão. Fundir os dois num estado só faria a aprovação
 * carregar uma promessa que ela não pode cumprir.
 *
 * `Superseded` é a saída involuntária: a homologação foi aprovada, e depois os
 * fatos que ela revisou deixaram de ser os atuais.
 */
enum SalesBoardRolloutHomologationStatus: string
{
    case Draft = 'em_homologacao';

    case Approved = 'aprovada';

    case Rejected = 'rejeitada';

    case Superseded = 'substituida';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * A homologação ainda pode sustentar uma ativação?
     */
    public function canActivate(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em homologação',
            self::Approved => 'Homologação aprovada',
            self::Rejected => 'Homologação rejeitada',
            self::Superseded => 'Substituída por mudança na fonte',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }
}
