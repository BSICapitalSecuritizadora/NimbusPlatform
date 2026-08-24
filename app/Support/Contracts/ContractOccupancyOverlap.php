<?php

namespace App\Support\Contracts;

/**
 * Two contracts of one unit that would hold it at the same time, and the sentence
 * that says so.
 *
 * The sentence lives here rather than at each call site so the import preview and
 * the contract form accuse the same thing in the same words: they are enforcing
 * one rule, and an operator who meets it in both places should recognise it.
 */
final class ContractOccupancyOverlap
{
    public function __construct(
        /** The contract whose period starts first. */
        public readonly ContractOccupancyPeriod $earlier,
        /** The contract that starts while the earlier one is still running. */
        public readonly ContractOccupancyPeriod $later,
        public readonly int $days,
    ) {}

    /**
     * Every line of the spreadsheet involved. A period without one came from the
     * database and is not a row anybody can correct.
     *
     * @return list<int>
     */
    public function lines(): array
    {
        return array_values(array_filter(
            [$this->earlier->line, $this->later->line],
            static fn (?int $line): bool => $line !== null,
        ));
    }

    /**
     * Names both contracts, both buyers and both dates, because which of the two
     * dates is wrong is not something the system can know -- only the operator
     * holding the paperwork can.
     */
    public function describe(string $unitLabel): string
    {
        $end = $this->earlier->endsOnForDisplay();

        $previous = $end === null
            ? sprintf('%s ainda ocupa a unidade', $this->earlier->label())
            : sprintf('%s só foi distratado em %s', $this->earlier->label(), $end);

        return sprintf(
            'Sobreposição de %d dia(s) na unidade %s: %s, mas %s já foi vendido em %s. Revise as datas antes de continuar.',
            $this->days,
            $unitLabel,
            $previous,
            $this->later->label(),
            $this->later->startsOnForDisplay(),
        );
    }
}
