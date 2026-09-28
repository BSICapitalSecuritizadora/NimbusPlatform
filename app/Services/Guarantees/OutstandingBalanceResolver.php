<?php

namespace App\Services\Guarantees;

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
        $monthStartString = $referenceStart->toDateString();

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

        $reading = $this->puReader->readingWithin(
            $emission,
            Carbon::parse($monthStartString),
            Carbon::parse($referenceEndString),
        );

        if ($reading === null) {
            return [0.0, false];
        }

        return [
            round((float) $reading->unitValue * $integralizedQuantity, 2),
            true,
        ];
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
