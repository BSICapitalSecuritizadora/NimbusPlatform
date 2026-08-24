<?php

namespace App\Support\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Support\Reconciliation\ValueComparator;
use Carbon\Carbon;

/**
 * The stretch of time one contract holds one unit: `[sale_date, cancellation_date)`.
 *
 * Half open on purpose. A distrato and the sale that follows it on the very same
 * day are two contracts meeting, not two contracts overlapping -- the unit
 * changes hands that day and is held by exactly one buyer at every instant.
 *
 * `endsOn` is null while the contract still holds the unit, which is the same
 * question {@see ContractStatus::occupiesUnit()} answers and is never decided
 * here. A distratado contract always has a date, because the domain requires one
 * -- and it can never be a future date, so a period that is closed is a period
 * that has already closed. See {@see Contract::cancellationDateHasTakenEffect()}.
 *
 * `isSubject` marks the periods the operation under validation creates or moves.
 * Only those are held against the rest, which is what keeps a reconciliation
 * from re-litigating two historical contracts nobody is touching.
 */
final class ContractOccupancyPeriod
{
    /**
     * Stands in for "no end": later than any date the system can hold, so an
     * open period compares as running forever without a special case at every
     * comparison.
     */
    private const OPEN_ENDED = '9999-12-31';

    public function __construct(
        public readonly string $code,
        public readonly ?string $clientName,
        public readonly string $startsOn,
        public readonly ?string $endsOn,
        public readonly bool $isSubject,
        /** Line of the spreadsheet this period came from, when it came from one. */
        public readonly ?int $line = null,
    ) {}

    /**
     * A contract as it stands on record.
     */
    public static function fromContract(Contract $contract, bool $isSubject = false): self
    {
        return new self(
            code: (string) $contract->code,
            clientName: $contract->client?->name,
            startsOn: (string) ValueComparator::date($contract->sale_date),
            endsOn: $contract->occupiesUnit() ? null : ValueComparator::date($contract->cancellation_date),
            isSubject: $isSubject,
        );
    }

    /**
     * A contract as some pending change would leave it -- a spreadsheet row, or
     * the form state being validated.
     */
    public static function fromValues(
        ?string $code,
        ?string $clientName,
        mixed $saleDate,
        mixed $cancellationDate,
        ?ContractStatus $status,
        bool $isSubject = true,
        ?int $line = null,
    ): ?self {
        $startsOn = ValueComparator::date($saleDate);

        if (($startsOn === null) || ($status === null)) {
            return null;
        }

        $endsOn = $status->occupiesUnit() ? null : ValueComparator::date($cancellationDate);

        /**
         * A closed period with no date cannot be placed on a timeline. It only
         * happens on a row the analysis already refused, and a refused row moves
         * nothing -- so there is nothing to validate.
         */
        if (! $status->occupiesUnit() && ($endsOn === null)) {
            return null;
        }

        return new self(
            code: (string) $code,
            clientName: $clientName,
            startsOn: $startsOn,
            endsOn: $endsOn,
            isSubject: $isSubject,
            line: $line,
        );
    }

    /**
     * Whether the two contracts would hold the unit at the same time.
     */
    public function overlaps(self $other): bool
    {
        return ($this->startsOn < $other->endsOrOpen())
            && ($other->startsOn < $this->endsOrOpen());
    }

    /**
     * How long the two would share the unit. Presentation only -- derived when
     * the message is written and never stored.
     */
    public function overlapDays(self $other): int
    {
        $from = max($this->startsOn, $other->startsOn);
        $to = min($this->endsOrOpen(), $other->endsOrOpen());

        if ($to === self::OPEN_ENDED) {
            return 0;
        }

        return (int) Carbon::parse($from)->diffInDays(Carbon::parse($to));
    }

    public function startsBefore(self $other): bool
    {
        return [$this->startsOn, $this->code] < [$other->startsOn, $other->code];
    }

    public function label(): string
    {
        return $this->clientName === null
            ? $this->code
            : sprintf('%s (%s)', $this->code, $this->clientName);
    }

    public function startsOnForDisplay(): string
    {
        return (string) ValueComparator::formatDate($this->startsOn);
    }

    public function endsOnForDisplay(): ?string
    {
        return $this->endsOn === null ? null : ValueComparator::formatDate($this->endsOn);
    }

    private function endsOrOpen(): string
    {
        return $this->endsOn ?? self::OPEN_ENDED;
    }
}
