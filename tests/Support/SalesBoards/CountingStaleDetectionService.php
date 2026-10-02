<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;

/**
 * A verificação de fonte, contando quantas vezes é apurada.
 *
 * Cada apuração do portão é uma derivação da obra inteira. Os testes de custo
 * por requisição ligam esta classe no container e conferem quantas a tela da
 * Análise paga para renderizar, abrir o modal e aprovar -- o número que o memo
 * por requisição existe para manter baixo.
 */
final class CountingStaleDetectionService extends SalesBoardStaleDetectionService
{
    public static int $assessments = 0;

    public static function reset(): void
    {
        self::$assessments = 0;
    }

    public function assessWithoutPersisting(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): SalesBoardStaleAssessment
    {
        self::$assessments++;

        return parent::assessWithoutPersisting($cycle, $baseline);
    }
}
