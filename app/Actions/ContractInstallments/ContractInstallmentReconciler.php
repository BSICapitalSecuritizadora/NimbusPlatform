<?php

namespace App\Actions\ContractInstallments;

use App\Enums\ChangeSeverity;
use App\Models\ContractInstallment;
use App\Support\Money\IntegerMoney;
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
 *   so the stored payment stays. It is not silent either: the row reads as an
 *   informative divergence, because a receipt reverted at the source (a cheque
 *   returned, a payment undone) would otherwise keep the contract settled with
 *   nobody the wiser. Reverting a receipt is done by editing the installment,
 *   where it is audited on its own.
 * - The same reasoning covers a cancelamento already on the record: the column
 *   exists in the file and the source never fills it, so an empty cell carries
 *   no information about it.
 * - The discount given on the receipt follows the receipt: a first discount is
 *   routine, changing one already recorded is a retificação, and a discount on
 *   record against an empty cell is kept and shown as an informative
 *   divergence -- removing it is done by editing the installment. A file
 *   without the optional column says nothing about it, and it is not compared.
 *
 * The installment on record arrives either as the model or as the raw row the
 * streamed conference reads ({@see StoredInstallment}). Both go through the
 * very same rules: the model is reduced to the same shape first.
 */
class ContractInstallmentReconciler
{
    /**
     * @param  array<string, mixed>  $row  a row already parsed and validated by
     *                                     {@see AnalyzeContractInstallmentSpreadsheet}
     */
    public function compare(ContractInstallment|StoredInstallment $installment, array $row): RecordComparison
    {
        $stored = $installment instanceof StoredInstallment
            ? $installment
            : StoredInstallment::fromModel($installment);

        return RecordComparison::of(array_filter([
            $this->dueDate($stored, $row),
            $this->expectedValue($stored, $row),
            $this->paymentDate($stored, $row),
            $this->paidValue($stored, $row),
            $this->discountValue($stored, $row),
            $this->cancellationDate($stored, $row),
        ]));
    }

    /**
     * Moving a due date rewrites the schedule the contract is measured against,
     * which is why it is never applied without being seen.
     *
     * @param  array<string, mixed>  $row
     */
    private function dueDate(StoredInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->dueDate, $row['due_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'due_date',
            label: 'Vencimento',
            current: ValueComparator::formatDate($installment->dueDate),
            new: ValueComparator::formatDate($row['due_date']),
            severity: ChangeSeverity::Critical,
            value: $row['due_date'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function expectedValue(StoredInstallment $installment, array $row): ?FieldChange
    {
        if ($installment->expectedCents === self::rowCents($row, 'expected')) {
            return null;
        }

        return new FieldChange(
            field: 'expected_value',
            label: 'Valor previsto',
            current: self::formatCents($installment->expectedCents),
            new: ValueComparator::formatMoney($row['expected_value']),
            severity: ChangeSeverity::Critical,
            value: $row['expected_value'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paymentDate(StoredInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->paymentDate, $row['payment_date'])) {
            return null;
        }

        // Nothing in the file where a payment is recorded: not an instruction to
        // erase it, but a difference worth showing.
        if (blank($row['payment_date'])) {
            return $this->missingReceipt($installment);
        }

        return new FieldChange(
            field: 'payment_date',
            label: 'Data do pagamento',
            current: ValueComparator::formatDate($installment->paymentDate),
            new: ValueComparator::formatDate($row['payment_date']),
            // A first receipt is routine; moving one already recorded is not.
            severity: blank($installment->paymentDate) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['payment_date'],
        );
    }

    /**
     * The receipt on record that the file no longer carries, reported once for
     * the pair -- date and value travel together, in the file and on the record.
     */
    private function missingReceipt(StoredInstallment $installment): FieldChange
    {
        $recorded = implode(' · ', array_filter([
            ValueComparator::formatDate($installment->paymentDate),
            self::formatCents($installment->paidCents),
        ]));

        return new FieldChange(
            field: 'payment_date',
            label: 'Recebimento',
            current: $recorded,
            new: 'não consta na planilha (mantido; para estornar, edite a parcela)',
            severity: ChangeSeverity::Informative,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paidValue(StoredInstallment $installment, array $row): ?FieldChange
    {
        $incoming = self::rowCents($row, 'paid');

        if ($installment->paidCents === $incoming) {
            return null;
        }

        if ($incoming === null) {
            return null;
        }

        return new FieldChange(
            field: 'paid_value',
            label: 'Valor pago',
            current: self::formatCents($installment->paidCents),
            new: ValueComparator::formatMoney($row['paid_value']),
            severity: ($installment->paidCents === null) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['paid_value'],
        );
    }

    /**
     * The discount registered with the receipt, under the same rules as the
     * receipt itself.
     *
     * @param  array<string, mixed>  $row
     */
    private function discountValue(StoredInstallment $installment, array $row): ?FieldChange
    {
        if (! ($row['discount_in_file'] ?? false)) {
            return null;
        }

        $incoming = self::rowCents($row, 'discount');

        if ($installment->discountCents === $incoming) {
            return null;
        }

        if ($incoming === null) {
            return new FieldChange(
                field: 'discount_value',
                label: 'Desconto',
                current: self::formatCents($installment->discountCents),
                new: 'não consta na planilha (mantido; para retirar, edite a parcela)',
                severity: ChangeSeverity::Informative,
            );
        }

        return new FieldChange(
            field: 'discount_value',
            label: 'Desconto',
            current: self::formatCents($installment->discountCents),
            new: ValueComparator::formatMoney($row['discount_value']),
            // A first discount is routine; changing one already recorded is not.
            severity: ($installment->discountCents === null) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['discount_value'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cancellationDate(StoredInstallment $installment, array $row): ?FieldChange
    {
        if (ValueComparator::dateEquals($installment->cancellationDate, $row['cancellation_date'])) {
            return null;
        }

        if (blank($row['cancellation_date'])) {
            return null;
        }

        return new FieldChange(
            field: 'cancellation_date',
            label: 'Data de cancelamento',
            current: ValueComparator::formatDate($installment->cancellationDate),
            new: ValueComparator::formatDate($row['cancellation_date']),
            severity: blank($installment->cancellationDate) ? ChangeSeverity::Normal : ChangeSeverity::Critical,
            value: $row['cancellation_date'],
        );
    }

    /**
     * The amount of the row in cents: the exact reading when the analysis kept
     * it, the decimal value otherwise.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowCents(array $row, string $prefix): ?int
    {
        if (array_key_exists($prefix.'_cents', $row)) {
            return $row[$prefix.'_cents'];
        }

        return ValueComparator::cents($row[$prefix.'_value'] ?? null);
    }

    private static function formatCents(?int $cents): ?string
    {
        return $cents === null ? null : ValueComparator::formatMoney(IntegerMoney::decimalString($cents));
    }
}
