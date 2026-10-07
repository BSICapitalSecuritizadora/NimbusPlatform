<?php

namespace App\Services\Guarantees;

use App\Domain\PuCalculator\DTOs\PuReading;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use Carbon\Carbon;

/**
 * Saldo devedor da emissão numa competência.
 *
 * Fonte única do número (§16 do escopo): último PU dentro do mês multiplicado
 * pela quantidade integralizada acumulada até o fim do mês. O PU vem de
 * `EmissionPuReader`: a curva oficial homologada quando existe, o Histórico de
 * PU importado quando não.
 *
 * O booleano de retorno distingue "saldo zero porque nada foi integralizado"
 * de "não há PU no mês": o primeiro é um saldo legítimo, o segundo é dado
 * faltando e precisa aparecer como pendência.
 *
 * O saldo não mostra a data do PU, então o PU tem de poder responder pelo mês
 * ({@see PuReading::standsForRequestedDate()}). O da própria data de fim de mês,
 * sempre. Um PU da curva oficial carregado de dia anterior, só com a curva em
 * dia -- é o caso do mês em curso, cujo PU de fim de mês ainda não existe. Com a
 * curva atrasada, sem índice exigido, com a extensão falhando ou à espera de
 * reprocessamento, o saldo fica indisponível em vez de usar um PU mais velho do
 * que devia como se fosse o do mês. {@see self::reading()} expõe a leitura, com
 * a posição e a situação da curva, para quem precisar explicar a pendência.
 */
class OutstandingBalanceResolver
{
    public function __construct(
        private readonly EmissionPuReader $puReader,
    ) {}

    /**
     * @return array{0: float, 1: bool} valor e se a fonte tinha dado
     */
    public function resolve(Emission $emission, string $referenceMonth): array
    {
        $emission->loadMissing(['integralizationHistories']);

        $referenceStart = Carbon::parse($referenceMonth)->startOfMonth();
        $referenceEndString = $referenceStart->copy()->endOfMonth()->toDateString();

        $integralizedQuantity = round(
            (float) $emission->integralizationHistories
                ->filter(function (IntegralizationHistory $integralizationHistory) use ($referenceEndString): bool {
                    $historyDate = $integralizationHistory->date?->toDateString();

                    return filled($historyDate) && $historyDate <= $referenceEndString;
                })
                ->sum('quantity'),
            4,
        );

        if ($integralizedQuantity <= 0) {
            return [0.0, true];
        }

        $reading = $this->reading($emission, $referenceMonth);

        if ($reading === null || ! $reading->standsForRequestedDate()) {
            return [0.0, false];
        }

        return [
            round((float) $reading->unitValue * $integralizedQuantity, 2),
            true,
        ];
    }

    /**
     * O último PU dentro do mês, com a data a que ele pertence, o fim do mês como
     * data pedida e a situação da curva oficial -- inclusive quando o saldo o
     * recusa por não poder responder pelo mês.
     */
    public function reading(Emission $emission, string $referenceMonth): ?PuReading
    {
        $referenceStart = Carbon::parse($referenceMonth)->startOfMonth();

        return $this->puReader->readingWithin(
            $emission,
            $referenceStart->copy(),
            $referenceStart->copy()->endOfMonth()->startOfDay(),
        );
    }

    /**
     * Saldo devedor como valor puro, ou `null` quando a competência não tem PU.
     *
     * É esta a forma que o motor de garantias consome: sem PU no mês não existe
     * saldo devedor conhecido, e devolver zero faria a cobertura parecer
     * infinita em vez de indisponível.
     */
    public function resolveOrNull(Emission $emission, string $referenceMonth): ?float
    {
        [$value, $hasData] = $this->resolve($emission, $referenceMonth);

        return $hasData ? $value : null;
    }
}
