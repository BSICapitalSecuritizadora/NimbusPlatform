<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\DTOs\SalesBoards\ConstructionSalesPosition;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * A forma canônica de uma posição dentro da homologação.
 *
 * Existe para que a posição legada e a derivada -- que vêm de dois motores
 * diferentes, com dois vocabulários diferentes -- possam ser comparadas e
 * persistidas na mesma estrutura. Sem ela, a comparação teria de traduzir
 * `settled` para `paid` em cada ponto que a fizesse, e o dia em que a tradução
 * divergisse entre dois pontos ninguém saberia qual estava certa.
 *
 * Tudo em centavos inteiros. Nenhum dado de comprador entra aqui.
 */
final class RolloutPosition
{
    /**
     * Os quatro baldes, no vocabulário do quadro publicado.
     *
     * @var list<string>
     */
    public const BUCKETS = ['stock', 'financed', 'paid', 'exchanged'];

    /**
     * A posição derivada pelo motor da Fase B.
     *
     * `settled` da derivação é `paid` no quadro -- a mesma tradução que a
     * publicação da Fase E faz, e pela mesma razão: os dois nomes descrevem o
     * mesmo balde.
     *
     * @return array<string, mixed>
     */
    public static function fromDerived(SalesBoardDerivedPosition $position): array
    {
        return self::canonical(
            referenceMonth: $position->referenceMonth,
            buckets: [
                'stock' => [$position->stockUnits, $position->stockValueCents],
                'financed' => [$position->financedUnits, $position->financedValueCents],
                'paid' => [$position->settledUnits, $position->settledValueCents],
                'exchanged' => [$position->exchangedUnits, $position->exchangedValueCents],
            ],
            totalUnits: $position->unitsTotal,
        );
    }

    /**
     * A posição legada, como o leitor da Fase 0 a enxerga.
     *
     * Passa pelo `SalesBoardPositionReader` de propósito: é ele que aplica o
     * carry-forward, e portanto é ele que sabe o que Garantias e Relatório
     * realmente veem naquela competência. Ler `sales_boards` direto compararia
     * contra uma posição que ninguém consome.
     *
     * @return array<string, mixed>|null
     */
    public static function fromLegacy(ConstructionSalesPosition $position): ?array
    {
        if (! $position->isResolved()) {
            return null;
        }

        /**
         * O leitor devolve os valores como `float`. A conversão passa pelo
         * literal decimal antes de virar centavos -- somar ou arredondar em
         * ponto flutuante aqui produziria o centavo de diferença que faria uma
         * posição idêntica parecer divergente.
         */
        $cents = static fn (float $value): ?int => IntegerMoney::cents(sprintf('%.2F', $value));

        return self::canonical(
            referenceMonth: $position->referenceMonthUsed ?? $position->positionDate,
            buckets: [
                'stock' => [$position->stockUnits, $cents($position->stockValue)],
                'financed' => [$position->financedUnits, $cents($position->financedValue)],
                'paid' => [$position->paidUnits, $cents($position->paidValue)],
                'exchanged' => [$position->exchangedUnits, $cents($position->exchangedValue)],
            ],
            totalUnits: $position->totalUnits,
        );
    }

    /**
     * @param  array<string, array{0: int, 1: int|null}>  $buckets
     * @return array<string, mixed>
     */
    private static function canonical(CarbonImmutable $referenceMonth, array $buckets, int $totalUnits): array
    {
        $canonical = [
            'version' => SalesBoardRolloutHomologationConstruction::POSITION_SCHEMA_VERSION,
            'reference_month' => $referenceMonth->format('Y-m'),
            'total_units' => $totalUnits,
            'buckets' => [],
        ];

        foreach (self::BUCKETS as $bucket) {
            [$units, $valueCents] = $buckets[$bucket];

            $canonical['buckets'][$bucket] = [
                'units' => (int) $units,
                'value_cents' => $valueCents,
            ];
        }

        return $canonical;
    }

    /**
     * O que muda entre a posição legada e a derivada.
     *
     * `null` num balde legado significa "não foi possível saber", e um delta
     * calculado contra `null` seria inventado -- por isso o balde é marcado como
     * incomparável em vez de virar zero.
     *
     * @param  array<string, mixed>|null  $legacy
     * @param  array<string, mixed>  $derived
     * @return array<string, mixed>
     */
    public static function delta(?array $legacy, array $derived): array
    {
        if ($legacy === null) {
            return ['has_difference' => false, 'comparable' => false, 'buckets' => []];
        }

        $delta = ['has_difference' => false, 'comparable' => true, 'buckets' => []];

        foreach (self::BUCKETS as $bucket) {
            $legacyUnits = (int) ($legacy['buckets'][$bucket]['units'] ?? 0);
            $derivedUnits = (int) ($derived['buckets'][$bucket]['units'] ?? 0);

            $legacyCents = $legacy['buckets'][$bucket]['value_cents'] ?? null;
            $derivedCents = $derived['buckets'][$bucket]['value_cents'] ?? null;

            $comparableValue = $legacyCents !== null && $derivedCents !== null;

            $unitsDelta = $derivedUnits - $legacyUnits;
            $valueDelta = $comparableValue ? ((int) $derivedCents - (int) $legacyCents) : 0;

            $delta['buckets'][$bucket] = [
                'units' => $unitsDelta,
                'value_cents' => $valueDelta,
                'comparable_value' => $comparableValue,
            ];

            if ($unitsDelta !== 0 || $valueDelta !== 0 || ! $comparableValue) {
                $delta['has_difference'] = true;
            }
        }

        $totalDelta = (int) ($derived['total_units'] ?? 0) - (int) ($legacy['total_units'] ?? 0);
        $delta['total_units'] = $totalDelta;

        if ($totalDelta !== 0) {
            $delta['has_difference'] = true;
        }

        return $delta;
    }
}
