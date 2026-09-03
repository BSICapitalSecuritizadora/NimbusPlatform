<?php

namespace App\Actions\ConstructionUnitValues;

use App\Support\TemporarySpreadsheetFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the standard spreadsheet used to reprice units in batch.
 *
 * Same shape as the other templates: the data sheet carries only the headers,
 * so nothing is imported by accident, and the demonstration rows live on a
 * sheet the importer never reads.
 */
class UnitValueSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Atualização de Valores das Unidades.xlsx';

    public const DATA_SHEET = 'Valores';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string>>
     */
    private const EXAMPLE_ROWS = [
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101', '1.000.000,00', '01/07/2026', 'Reajuste de tabela'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '102', '1.050.000,00', '01/07/2026', 'Reajuste de tabela'],
        ['CRI Conviva', 'Conviva Camboinhas', '02', '201', '1.300.000,00', '01/07/2026', ''],
    ];

    public function build(): string
    {
        return TemporarySpreadsheetFile::write('unit-values-template-', function (SimpleExcelWriter $writer): void {
            $writer->nameCurrentSheet(self::DATA_SHEET)
                ->addHeader(UnitValueSpreadsheetColumns::headers());

            $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
                ->addHeader(UnitValueSpreadsheetColumns::headers());

            foreach (self::EXAMPLE_ROWS as $exampleRow) {
                $writer->addRow(array_combine(UnitValueSpreadsheetColumns::headers(), $exampleRow));
            }
        });
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
