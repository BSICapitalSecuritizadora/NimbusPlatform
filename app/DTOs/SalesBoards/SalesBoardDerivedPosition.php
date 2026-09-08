<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardUnitClassification;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * A composição completa do Quadro de Vendas de um empreendimento numa data,
 * derivada linha a linha.
 *
 * Os totais nunca são digitados nem calculados por fora: são a agregação das
 * {@see SalesBoardDerivedLine}, o que garante que cada número possa ser aberto
 * até a unidade e o contrato que o formaram.
 *
 * Um valor de balde é `null` quando **alguma** linha daquele balde não tem valor
 * conhecido. Somar só as que têm produziria um número menor que a realidade com
 * cara de número completo -- e é exatamente esse tipo de meia verdade que a
 * Fase 0 existiu para acabar.
 */
readonly class SalesBoardDerivedPosition extends BaseDTO
{
    /**
     * @param  list<SalesBoardDerivedLine>  $lines
     * @param  list<SalesBoardIssue>  $issues
     */
    public function __construct(
        public int $constructionId,
        public ?string $constructionName,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public int $unitsTotal,
        public int $stockUnits,
        public ?int $stockValueCents,
        public int $financedUnits,
        public ?int $financedValueCents,
        public int $settledUnits,
        public ?int $settledValueCents,
        public int $exchangedUnits,
        public ?int $exchangedValueCents,
        public int $undeterminedUnits,
        public array $lines,
        public SalesBoardMovements $movements,
        public array $issues,
    ) {}

    /**
     * @param  list<SalesBoardDerivedLine>  $lines
     * @param  list<SalesBoardIssue>  $issues
     */
    public static function fromLines(
        int $constructionId,
        ?string $constructionName,
        CarbonImmutable $referenceMonth,
        CarbonImmutable $positionDate,
        array $lines,
        SalesBoardMovements $movements,
        array $issues,
    ): self {
        $bucket = static fn (SalesBoardUnitClassification $classification): array => self::aggregate($lines, $classification);

        [$stockUnits, $stockValue] = $bucket(SalesBoardUnitClassification::Stock);
        [$financedUnits, $financedValue] = $bucket(SalesBoardUnitClassification::Financed);
        [$settledUnits, $settledValue] = $bucket(SalesBoardUnitClassification::Settled);
        [$exchangedUnits, $exchangedValue] = $bucket(SalesBoardUnitClassification::Exchanged);
        [$undeterminedUnits] = $bucket(SalesBoardUnitClassification::Undetermined);

        return new self(
            constructionId: $constructionId,
            constructionName: $constructionName,
            referenceMonth: $referenceMonth,
            positionDate: $positionDate,
            unitsTotal: count($lines),
            stockUnits: $stockUnits,
            stockValueCents: $stockValue,
            financedUnits: $financedUnits,
            financedValueCents: $financedValue,
            settledUnits: $settledUnits,
            settledValueCents: $settledValue,
            exchangedUnits: $exchangedUnits,
            exchangedValueCents: $exchangedValue,
            undeterminedUnits: $undeterminedUnits,
            lines: $lines,
            movements: $movements,
            issues: $issues,
        );
    }

    /**
     * Contagem e valor de um balde.
     *
     * O valor é `null` assim que uma linha do balde não souber o próprio valor.
     *
     * @param  list<SalesBoardDerivedLine>  $lines
     * @return array{0: int, 1: int|null}
     */
    private static function aggregate(array $lines, SalesBoardUnitClassification $classification): array
    {
        $matching = array_filter(
            $lines,
            fn (SalesBoardDerivedLine $line): bool => $line->classification === $classification,
        );

        if ($classification === SalesBoardUnitClassification::Undetermined) {
            return [count($matching), null];
        }

        $total = 0;

        foreach ($matching as $line) {
            $value = $line->bucketValueCents();

            if ($value === null) {
                return [count($matching), null];
            }

            $total += $value;
        }

        return [count($matching), $total];
    }

    /**
     * A posição pode ser publicada como completa.
     *
     * Exige que todas as unidades tenham caído num balde e que todos os valores
     * sejam conhecidos. Números parciais continuam visíveis -- o que não é
     * permitido é apresentá-los como fechados.
     */
    public function isComplete(): bool
    {
        return ($this->undeterminedUnits === 0)
            && ($this->stockValueCents !== null)
            && ($this->financedValueCents !== null)
            && ($this->settledValueCents !== null)
            && ($this->exchangedValueCents !== null)
            && ! $this->hasBlockingIssue();
    }

    public function hasBlockingIssue(): bool
    {
        return collect($this->issues)->contains(fn (SalesBoardIssue $issue): bool => $issue->isBlocker());
    }

    /**
     * @return list<SalesBoardIssue>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, fn (SalesBoardIssue $issue): bool => $issue->isBlocker()));
    }

    /**
     * @return list<SalesBoardIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, fn (SalesBoardIssue $issue): bool => ! $issue->isBlocker()));
    }

    /**
     * Os quatro baldes mais os indeterminados fecham o inventário. É a
     * invariante que prova que nenhuma unidade foi contada duas vezes nem
     * esquecida.
     */
    public function bucketsBalance(): bool
    {
        return ($this->stockUnits
            + $this->financedUnits
            + $this->settledUnits
            + $this->exchangedUnits
            + $this->undeterminedUnits) === $this->unitsTotal;
    }

    /**
     * @return list<SalesBoardDerivedLine>
     */
    public function linesOf(SalesBoardUnitClassification $classification): array
    {
        return array_values(array_filter(
            $this->lines,
            fn (SalesBoardDerivedLine $line): bool => $line->classification === $classification,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withLines = false): array
    {
        $money = static fn (?int $cents): ?string => $cents === null ? null : IntegerMoney::format($cents);

        $summary = [
            'construction_id' => $this->constructionId,
            'construction' => $this->constructionName,
            'reference_month' => $this->referenceMonth->format('m/Y'),
            'position_date' => $this->positionDate->toDateString(),
            'units_total' => $this->unitsTotal,
            'stock_units' => $this->stockUnits,
            'stock_value' => $money($this->stockValueCents),
            'financed_units' => $this->financedUnits,
            'financed_value' => $money($this->financedValueCents),
            'settled_units' => $this->settledUnits,
            'settled_value' => $money($this->settledValueCents),
            'exchanged_units' => $this->exchangedUnits,
            'exchanged_value' => $money($this->exchangedValueCents),
            'undetermined_units' => $this->undeterminedUnits,
            'is_complete' => $this->isComplete(),
            'movements' => $this->movements->toArray(),
            'issues' => array_map(fn (SalesBoardIssue $issue): array => $issue->toArray(), $this->issues),
        ];

        if ($withLines) {
            $summary['lines'] = array_map(fn (SalesBoardDerivedLine $line): array => $line->toArray(), $this->lines);
        }

        return $summary;
    }
}
