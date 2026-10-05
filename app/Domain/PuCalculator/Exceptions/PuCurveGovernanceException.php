<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use InvalidArgumentException;

/**
 * Lançada quando uma ação de governança da curva (validar, homologar, invalidar,
 * concluir a geração) não pode agir sobre a versão pedida: a versão não existe,
 * é ambígua, ou o status dela -- relido sob trava, dentro da transação -- não
 * permite a transição.
 *
 * Estende `InvalidArgumentException` porque é o contrato que as telas e os
 * comandos já tratam como recusa de negócio.
 */
class PuCurveGovernanceException extends InvalidArgumentException {}
