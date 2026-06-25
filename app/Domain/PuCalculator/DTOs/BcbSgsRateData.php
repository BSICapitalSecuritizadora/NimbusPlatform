<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

/**
 * Um ponto de série temporal retornado pela API SGS do Banco Central.
 *
 * `value` é mantido como STRING (precisão decimal, sem float). Para o CDI (série 4389) é a taxa anual
 * base 252 (% a.a.); para o IPCA (série 433) é a variação mensal (%), que será transformada em
 * número-índice pelo serviço de sincronização — nunca persistida como se fosse número-índice.
 */
final readonly class BcbSgsRateData
{
    public function __construct(
        public CarbonImmutable $referenceDate,
        public string $value,
        public int $seriesCode,
    ) {}
}
