<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use App\Domain\PuCalculator\DTOs\IndexRateRecordOutcome;
use App\Domain\PuCalculator\Services\IndexRateObservationRecorder;
use InvalidArgumentException;

/**
 * Uma carga de observações de índice que precisa ser tudo ou nada encontrou
 * observação que o {@see IndexRateObservationRecorder}
 * não gravou: valor diferente numa data já registrada (correção, que tem caminho
 * próprio), data ocupada por projeção, valor recusado pelo domínio ou valor fora
 * do padrão à espera de confirmação.
 *
 * Lançada dentro da transação da carga, desfaz o que já tinha sido gravado. As
 * recusas viajam junto, para quem chamou dizer o que encontrou.
 *
 * Estende `InvalidArgumentException` porque é o contrato que as telas e os
 * comandos já tratam como recusa de negócio.
 */
class IndexRateObservationRefusedException extends InvalidArgumentException
{
    /**
     * @param  list<IndexRateRecordOutcome>  $outcomes
     */
    public function __construct(string $message, public readonly array $outcomes)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<IndexRateRecordOutcome>  $outcomes
     */
    public static function fromOutcomes(string $context, array $outcomes): self
    {
        $details = array_map(
            static fn (IndexRateRecordOutcome $outcome): string => sprintf(
                '%s (%s): %s',
                $outcome->date->toDateString(),
                $outcome->status,
                $outcome->reason ?? 'sem detalhe',
            ),
            array_slice($outcomes, 0, 5),
        );

        return new self(
            sprintf(
                "%s: %d observação(ões) não gravada(s); nada foi gravado.\n%s%s",
                $context,
                count($outcomes),
                implode("\n", $details),
                count($outcomes) > 5 ? sprintf("\n... e mais %d.", count($outcomes) - 5) : '',
            ),
            $outcomes,
        );
    }
}
