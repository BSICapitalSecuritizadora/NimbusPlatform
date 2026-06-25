<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Falha ao consultar a API SGS do Banco Central (timeout, erro HTTP, resposta inválida).
 * A engine de cálculo nunca chama o BCB em tempo de cálculo — esta exceção só ocorre no fluxo de
 * sincronização e jamais quebra a geração da curva (que usa index_rates persistido).
 */
class BcbSgsException extends RuntimeException {}
