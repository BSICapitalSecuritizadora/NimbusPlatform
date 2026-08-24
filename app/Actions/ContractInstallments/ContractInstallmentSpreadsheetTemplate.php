<?php

namespace App\Actions\ContractInstallments;

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
 * in the platform.
 */
class ContractInstallmentSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Parcelas dos Contratos.xlsx';

    public const DATA_SHEET = 'Parcelas';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string>>
     */
    private const EXAMPLE_ROWS = [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', '10000.00', '10/01/2026', '10000.00', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '002', '10/02/2026', '10000.00', '', '', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', 'ENTRADA', '05/12/2025', '50000.00', '05/12/2025', '50000.00', ''],
    ];

    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function build(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contract-installments-template-').'.xlsx';

        $writer = SimpleExcelWriter::create($path)
            ->nameCurrentSheet(self::DATA_SHEET)
            ->addHeader(ContractInstallmentSpreadsheetColumns::headers());

        $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
            ->addHeader(ContractInstallmentSpreadsheetColumns::headers());

        foreach (self::EXAMPLE_ROWS as $exampleRow) {
            $writer->addRow(array_combine(ContractInstallmentSpreadsheetColumns::headers(), $exampleRow));
        }

        $writer->close();

        return $path;
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
