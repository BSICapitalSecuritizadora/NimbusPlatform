<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use RuntimeException;
use Throwable;

/**
 * Falha ao consultar a API SGS do Banco Central (timeout, erro HTTP, resposta inválida).
 * A engine de cálculo nunca chama o BCB em tempo de cálculo — esta exceção só ocorre no fluxo de
 * sincronização e jamais quebra a geração da curva (que usa index_rates persistido).
 *
 * A categoria (Fase 6) separa a fonte fora do ar da configuração recusada e da resposta fora do
 * formato: o monitor não trata as três do mesmo jeito.
 */
class BcbSgsException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?PuOperationalFailureCategory $category = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
