<?php

namespace App\Actions\Contracts;

use App\Enums\ChangeSeverity;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Support\Reconciliation\FieldChange;
use App\Support\Reconciliation\RecordComparison;
use App\Support\Reconciliation\ValueComparator;

/**
 * Holds a spreadsheet row against the contract it matched and says what, if
 * anything, changed.
 *
 * The classification follows what the domain can express safely:
 *
 * - A status moving forward (ativo becoming quitado or distratado) with its
 *   distrato date is the ordinary movement of a month.
 * - Sale value and sale date moving are retificações of the commercial terms:
 *   allowed, but confirmed deliberately.
 * - A status moving *back* -- distratado returning to ativo, a distrato date
 *   being emptied -- is a retificação too, never a routine update.
 * - Buyer and unit are refused outright. Selling the same unit to someone else
 *   is a resale, and a resale is a distrato plus a new contract; rewriting the
 *   buyer on an existing one would erase who actually bought it. Moving a live
 *   contract to another unit would also fight the database, which holds a unit
 *   for at most one live contract.
 */
class ContractReconciler
{
    /**
     * @param  array<string, mixed>  $row  a row already parsed and validated by
     *                                     {@see AnalyzeContractSpreadsheet}
     */
    public function compare(Contract $contract, array $row): RecordComparison
    {
        return RecordComparison::of(array_filter([
            $this->client($contract, $row),
            $this->constructionUnit($contract, $row),
            $this->saleDate($contract, $row),
            $this->saleValue($contract, $row),
            $this->status($contract, $row),
            $this->cancellationDate($contract, $row),
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function client(Contract $contract, array $row): ?FieldChange
    {
        if ((int) $contract->client_id === (int) $row['client_id']) {
            return null;
        }

        return new FieldChange(
            field: 'client_id',
            label: 'Cliente',
            current: $contract->client?->name,
            new: $row['client_label'],
            severity: ChangeSeverity::Blocked,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function constructionUnit(Contract $contract, array $row): ?FieldChange
    {
        if ((int) $contract->construction_unit_id === (int) $row['construction_unit_id']) {
            return null;
        }

        return new FieldChange(
            field: 'construction_unit_id',
            label: 'Unidade',
            current: $contract->constructionUnit?->display_name,
            new: $row['unit_label'],
            severity: ChangeSeverity::Blocked,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function saleDate(Contract $contract, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($contract->sale_date, $row['sale_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'sale_date',
            label: 'Data da venda',
            current: ValueComparator::formatDate($contract->sale_date),
            new: ValueComparator::formatDate($row['sale_date']),
            severity: ChangeSeverity::Critical,
            value: $row['sale_date'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function saleValue(Contract $contract, array $row): ?FieldChange
    {
        if (ValueComparator::moneyEquals($contract->sale_value, $row['sale_value'])) {
            return null;
        }

        return new FieldChange(
            field: 'sale_value',
            label: 'Valor da venda',
            current: ValueComparator::formatMoney($contract->sale_value),
            new: ValueComparator::formatMoney($row['sale_value']),
            severity: ChangeSeverity::Critical,
            value: $row['sale_value'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function status(Contract $contract, array $row): ?FieldChange
    {
        $incoming = $row['contract_status'];

        if (! ($incoming instanceof ContractStatus) || ($contract->status === $incoming)) {
            return null;
        }

        return new FieldChange(
            field: 'status',
            label: 'Status',
            current: $contract->status?->label(),
            new: $incoming->label(),
            severity: $this->statusSeverity($contract->status, $incoming),
            value: $incoming->value,
        );
    }

    /**
     * A contract leaving the live state is the movement the monthly file exists
     * to report. Coming back from a distrato is a correction of something
     * already recorded, and reads as critical.
     */
    private function statusSeverity(?ContractStatus $current, ContractStatus $incoming): ChangeSeverity
    {
        if (($current !== null) && ! $current->occupiesUnit() && $incoming->occupiesUnit()) {
            return ChangeSeverity::Critical;
        }

        return ChangeSeverity::Normal;
    }

    /**
     * The distrato date follows the status. It is only ever cleared by the model
     * itself, when the status no longer calls for one, so an empty cell here
     * never erases a date on its own.
     *
     * @param  array<string, mixed>  $row
     */
    private function cancellationDate(Contract $contract, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($contract->cancellation_date, $row['cancellation_date'])) {
            return null;
        }

        if (blank($row['cancellation_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'cancellation_date',
            label: 'Data do distrato',
            current: ValueComparator::formatDate($contract->cancellation_date),
            new: ValueComparator::formatDate($row['cancellation_date']),
            severity: blank($contract->cancellation_date) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['cancellation_date'],
        );
    }
}
