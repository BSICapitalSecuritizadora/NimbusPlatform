<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Support\Contracts\ContractOccupancyTimeline;
use App\Support\Reconciliation\ValueComparator;

/**
 * What a whole spreadsheet does to one unit: who holds it now, who the file
 * releases, and who would hold it once the import is confirmed.
 *
 * Built in memory by {@see ContractBatchProjection} and thrown away with the
 * analysis. Nothing here is ever persisted -- it exists so the batch can answer
 * "does this file leave the unit with exactly one occupant?" without writing a
 * provisional row to find out.
 *
 * An occupant whose `line` is null came from the database and was not mentioned
 * by any importable row of the file, so it stays exactly as it is. That is the
 * difference between a unit the file frees and a unit the file simply says
 * nothing about.
 *
 * @phpstan-type Occupant array{code: string, status: ContractStatus, line: int|null, contract_id: int|null, client: string|null, sale_date: string|null}
 * @phpstan-type Release array{code: string, status: ContractStatus, line: int, contract_id: int, client: string|null, cancellation_date: string|null}
 */
final class ProjectedUnitOccupancy
{
    /**
     * @param  list<Occupant>  $occupants  who holds the unit after the import
     * @param  list<Release>  $releases  contracts the file takes off the unit
     * @param  list<int>  $databaseOccupantIds  who held it when the file was analyzed
     */
    public function __construct(
        public readonly int $unitId,
        public readonly string $unitLabel,
        public readonly array $occupants,
        public readonly array $releases,
        public readonly array $databaseOccupantIds,
    ) {}

    /**
     * More than one contract would hold the unit. Impossible in the domain and
     * refused by the unique index, so the whole batch stops here.
     */
    public function isOverOccupied(): bool
    {
        return count($this->occupants) > 1;
    }

    /**
     * The file both frees the unit and sells it again: a resale, expressed the
     * way the domain expresses it -- a distrato plus a new contract.
     */
    public function isResale(): bool
    {
        return ! $this->isOverOccupied()
            && ($this->releases !== [])
            && ($this->occupantFromFile() !== null);
    }

    /**
     * Whether the row on this line is one of the projected occupants. Only those
     * rows are refused when the unit ends up over-occupied: a row that merely
     * frees the unit is not what makes the state impossible.
     */
    public function occupiesFromLine(int $line): bool
    {
        foreach ($this->occupants as $occupant) {
            if ($occupant['line'] === $line) {
                return true;
            }
        }

        return false;
    }

    public function releasesFromLine(int $line): bool
    {
        foreach ($this->releases as $release) {
            if ($release['line'] === $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the unit cannot end up the way the file describes.
     *
     * One untouched contract on record plus one row of the file is the ordinary
     * "you forgot the distrato" case, and keeps the message that names what to
     * do about it. Anything else is a clash between rows, and names the codes
     * that would fight over the unit.
     */
    public function conflictMessage(): string
    {
        $onRecord = $this->occupantsOnRecord();

        if ((count($onRecord) === 1) && (count($this->occupants) === 2)) {
            return sprintf(
                'Esta unidade já possui um contrato %s (%s). Registre o distrato antes de importar um novo contrato.',
                mb_strtolower($onRecord[0]['status']->label()),
                $onRecord[0]['code'],
            );
        }

        return sprintf(
            'A unidade %s ficaria vinculada a mais de um contrato ocupante após esta importação: %s.',
            $this->unitLabel,
            self::joinCodes(array_map(
                static fn (array $occupant): string => $occupant['code'],
                $this->occupants,
            )),
        );
    }

    /**
     * What to show next to the new contract of a resale, so the conference
     * screen says why it stopped being a conflict.
     *
     * Nothing is said here about the dates lining up. Whether the two contracts
     * would hold the unit at the same time is settled before this is ever
     * reached, by {@see ContractOccupancyTimeline}, and
     * a resale that got here is one whose history is coherent.
     */
    public function resaleNoteFor(int $line): ?string
    {
        if (! $this->isResale() || ! $this->occupiesFromLine($line)) {
            return null;
        }

        $release = $this->releases[0];

        return sprintf(
            'Revenda: o contrato %s (%s) é distratado nesta mesma planilha%s.',
            $release['code'],
            $release['client'] ?? 'cliente não identificado',
            blank($release['cancellation_date'])
                ? ''
                : ' em '.ValueComparator::formatDate($release['cancellation_date']),
        );
    }

    /**
     * The occupant that came from the file, if any.
     *
     * @return Occupant|null
     */
    public function occupantFromFile(): ?array
    {
        foreach ($this->occupants as $occupant) {
            if ($occupant['line'] !== null) {
                return $occupant;
            }
        }

        return null;
    }

    /**
     * Occupants the file never mentioned, still holding the unit exactly as the
     * database has them.
     *
     * @return list<Occupant>
     */
    public function occupantsOnRecord(): array
    {
        return array_values(array_filter(
            $this->occupants,
            static fn (array $occupant): bool => $occupant['line'] === null,
        ));
    }

    /**
     * @param  list<string>  $codes
     */
    private static function joinCodes(array $codes): string
    {
        if (count($codes) < 2) {
            return implode('', $codes);
        }

        $last = array_pop($codes);

        return implode(', ', $codes).' e '.$last;
    }
}
