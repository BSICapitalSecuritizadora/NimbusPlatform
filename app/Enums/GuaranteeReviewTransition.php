<?php

namespace App\Enums;

/**
 * Decisões possíveis sobre uma garantia detectada.
 *
 * `Complement` é a novidade em relação ao par confirmar/rejeitar original:
 * antes, uma candidata que correspondia a uma garantia já cadastrada só podia
 * virar registro novo ou ser descartada, e as duas saídas perdiam informação.
 */
enum GuaranteeReviewTransition: string
{
    case Approve = 'approve';
    case Complement = 'complement';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Criar garantia',
            self::Complement => 'Complementar garantia existente',
            self::Reject => 'Rejeitar',
        };
    }

    public function permission(): AccessPermission
    {
        return match ($this) {
            self::Approve => AccessPermission::GuaranteesApproveSuggestion,
            self::Complement => AccessPermission::GuaranteesComplementGuarantee,
            self::Reject => AccessPermission::GuaranteesRejectSuggestion,
        };
    }
}
