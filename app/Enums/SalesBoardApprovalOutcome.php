<?php

namespace App\Enums;

use App\Exceptions\SalesBoardManagementReviewException;

/**
 * O que a tentativa de aprovar e publicar produziu.
 *
 * `AlreadyApproved` existe para que um clique duplo ou um retry não vire erro de
 * banco. A segunda chamada encontra a análise já aprovada, devolve a publicação
 * que existe e não cria nada -- que é o resultado correto, não uma falha.
 *
 * As recusas não estão aqui: elas são {@see SalesBoardManagementReviewException}
 * porque cada uma tem uma mensagem própria dizendo o que fazer, e um enum de
 * códigos obrigaria a tela a reconstruir esse texto.
 */
enum SalesBoardApprovalOutcome: string
{
    case Approved = 'aprovado';

    case AlreadyApproved = 'ja_aprovado';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Posição aprovada e publicada',
            self::AlreadyApproved => 'Esta competência já havia sido aprovada',
        };
    }
}
