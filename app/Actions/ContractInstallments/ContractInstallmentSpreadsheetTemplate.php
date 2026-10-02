<?php

namespace App\Actions\ContractInstallments;

use App\Support\SpreadsheetTemplates\GeneratedSpreadsheetTemplate;
use App\Support\TemporarySpreadsheetFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the standard spreadsheet used to import installments.
 *
 * The first sheet carries only the headers, so nothing can be imported by
 * accident. The demonstration rows live on a separate "Exemplo" sheet, which the
 * importer never reads.
 *
 * An installment that was not received leaves the payment columns empty -- the
 * example shows a blank cell, never the word NULL, matching every other import
 * in the platform. The optional "Desconto" column carries the discount given on
 * the receipt: the first example is an installment of 10.000,00 received with
 * 9.500,00 and 500,00 of discount, which settles it; the ENTRADA is received in
 * full, with the discount cell empty.
 *
 * The amounts of the example are numeric cells, not text. A value typed as text
 * with three digits after one separator ("553,919", "1.500") reads two ways --
 * the import picks one and warns -- and a template is copied far more often
 * than it is read: it has to show the form that never doubts.
 */
class ContractInstallmentSpreadsheetTemplate implements GeneratedSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Parcelas dos Contratos.xlsx';

    public const DATA_SHEET = 'Parcelas';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string|float>>
     */
    private const EXAMPLE_ROWS = [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '10/01/2026', 9500.00, '', 500.00],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '002', '10/02/2026', 10000.00, '', '', '', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', 'ENTRADA', '05/12/2025', 50000.00, '05/12/2025', 50000.00, '', ''],
    ];

    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function build(): string
    {
        return TemporarySpreadsheetFile::write('contract-installments-template-', function (SimpleExcelWriter $writer): void {
            $writer->nameCurrentSheet(self::DATA_SHEET)
                ->addHeader(ContractInstallmentSpreadsheetColumns::headers());

            $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
                ->addHeader(ContractInstallmentSpreadsheetColumns::headers());

            foreach (self::EXAMPLE_ROWS as $exampleRow) {
                $writer->addRow(array_combine(ContractInstallmentSpreadsheetColumns::headers(), $exampleRow));
            }
        });
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
