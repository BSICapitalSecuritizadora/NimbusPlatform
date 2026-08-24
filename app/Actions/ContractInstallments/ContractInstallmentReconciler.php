<?php

namespace App\Actions\ContractInstallments;

use App\Enums\ChangeSeverity;
use App\Models\ContractInstallment;
use App\Support\Reconciliation\FieldChange;
use App\Support\Reconciliation\RecordComparison;
use App\Support\Reconciliation\ValueComparator;

/**
 * Holds a spreadsheet row against the installment it matched and says what, if
 * anything, changed.
 *
 * The classification encodes decisions taken with the operation, not guesses:
 *
 * - A receipt arriving where there was none is the ordinary movement of a month.
 * - A receipt changing value, or a due date or expected value moving, is a
 *   retificação of the financial schedule: allowed, but never silent.
 * - A payment already recorded against an empty cell is *not* an erasure. The
 *   source writes an unpaid installment as an empty cell or a zero, so a
 *   reverted payment and an installment that was never paid arrive identical.
 *   Clearing on that basis would let one incomplete export wipe real receipts,
 *   so the stored payment stays and the row reads as unchanged. Reverting a
 *   receipt is done by editing the installment, where it is audited on its own.
 * - The same reasoning covers a cancelamento already on the record: the column
 *   exists in the file and the source never fills it, so an empty cell carries
 *   no information about it.
 */
class ContractInstallmentReconciler
{
    /**
     * @param  array<string, mixed>  $row  a row already parsed and validated by
     *                                     {@see AnalyzeContractInstallmentSpreadsheet}
     */
    public function compare(ContractInstallment $installment, array $row): RecordComparison
    {
        return RecordComparison::of(array_filter([
            $this->dueDate($installment, $row),
            $this->expectedValue($installment, $row),
            $this->paymentDate($installment, $row),
            $this->paidValue($installment, $row),
            $this->cancellationDate($installment, $row),
        ]));
    }

    /**
     * Moving a due date rewrites the schedule the contract is measured against,
     * which is why it is never applied without being seen.
     *
     * @param  array<string, mixed>  $row
     */
    private function dueDate(ContractInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->due_date, $row['due_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'due_date',
            label: 'Vencimento',
            current: ValueComparator::formatDate($installment->due_date),
            new: ValueComparator::formatDate($row['due_date']),
            severity: ChangeSeverity::Critical,
            value: $row['due_date'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function expectedValue(ContractInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::moneyEquals($installment->expected_value, $row['expected_value'])) {
            return null;
        }

        return new FieldChange(
            field: 'expected_value',
            label: 'Valor previsto',
            current: ValueComparator::formatMoney($installment->expected_value),
            new: ValueComparator::formatMoney($row['expected_value']),
            severity: ChangeSeverity::Critical,
            value: $row['expected_value'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paymentDate(ContractInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->payment_date, $row['payment_date'])) {
            return null;
        }

        // Nothing in the file where a payment is recorded: no information, not
        // an instruction to erase one.
        if (blank($row['payment_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'payment_date',
            label: 'Data do pagamento',
            current: ValueComparator::formatDate($installment->payment_date),
            new: ValueComparator::formatDate($row['payment_date']),
            // A first receipt is routine; moving one already recorded is not.
            severity: blank($installment->payment_date) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['payment_date'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paidValue(ContractInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::moneyEquals($installment->paid_value, $row['paid_value'])) {
            return null;
        }

        if (blank($row['paid_value'])) {
            return null;
        }

        return new FieldChange(
            field: 'paid_value',
            label: 'Valor pago',
            current: ValueComparator::formatMoney($installment->paid_value),
            new: ValueComparator::formatMoney($row['paid_value']),
            severity: blank($installment->paid_value) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['paid_value'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cancellationDate(ContractInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->cancellation_date, $row['cancellation_date'])) {
            return null;
        }

        if (blank($row['cancellation_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'cancellation_date',
            label: 'Data de cancelamento',
            current: ValueComparator::formatDate($installment->cancellation_date),
            new: ValueComparator::formatDate($row['cancellation_date']),
            severity: blank($installment->cancellation_date) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['cancellation_date'],
        );
    }
}
