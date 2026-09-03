<?php

namespace App\Enums;

/**
 * Se uma venda respeitou a política comercial vigente quando foi realizada.
 *
 * `Undetermined` existe para que a ausência de dado nunca vire aprovação: sem
 * valor de referência ou sem política na data da venda não há o que comparar, e
 * responder "conforme" seria afirmar uma conformidade que ninguém verificou.
 */
enum SalesPriceConformityStatus: string
{
    case Conform = 'conforme';

    case NonConform = 'nao_conforme';

    case Undetermined = 'indeterminado';

    public function label(): string
    {
        return match ($this) {
            self::Conform => 'Conforme',
            self::NonConform => 'Não conforme',
            self::Undetermined => 'Indeterminado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Conform => 'success',
            self::NonConform => 'danger',
            self::Undetermined => 'gray',
        };
    }
}
