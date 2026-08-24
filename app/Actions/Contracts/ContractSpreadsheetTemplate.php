<?php

namespace App\Actions\Contracts;

use App\Support\TemporarySpreadsheetFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the standard spreadsheet used to import contracts.
 *
 * The first sheet carries only the headers, so nothing can be imported by
 * accident. The demonstration rows live on a separate "Exemplo" sheet, which the
 * importer never reads.
 */
class ContractSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Contratos.xlsx';

    public const DATA_SHEET = 'Contratos';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string>>
     */
    private const EXAMPLE_ROWS = [
        ['CRI Conviva', 'Conviva Camboinhas', '01', '305', '12345678900', 'CVC-00123', '10/03/2024', '850000.00', 'Ativo', ''],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '402', '98765432100', 'CVC-00124', '05/02/2024', '700000.00', 'Distratado', '15/06/2025'],
    ];

    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function build(): string
    {
        return TemporarySpreadsheetFile::write('contracts-template-', function (SimpleExcelWriter $writer): void {
            $writer->nameCurrentSheet(self::DATA_SHEET)
                ->addHeader(ContractSpreadsheetColumns::headers());

            $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
                ->addHeader(ContractSpreadsheetColumns::headers());

            foreach (self::EXAMPLE_ROWS as $exampleRow) {
                $writer->addRow(array_combine(ContractSpreadsheetColumns::headers(), $exampleRow));
            }
        });
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
