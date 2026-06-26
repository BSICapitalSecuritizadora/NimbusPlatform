<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

/**
 * Falha ao consultar um BLOCO (intervalo) específico da série SGS.
 *
 * A janela total é dividida em blocos contíguos consultados em sequência. Uma falha em um bloco é
 * registrada aqui (sem derrubar os demais) — só vira {@see \App\Domain\PuCalculator\Exceptions\BcbSgsException}
 * quando TODOS os blocos falham.
 */
final readonly class BcbSgsBlockFailure
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $message,
    ) {}

    public function describe(): string
    {
        return sprintf('%s a %s: %s', $this->from->toDateString(), $this->to->toDateString(), $this->message);
    }
}
