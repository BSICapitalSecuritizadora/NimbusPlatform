<?php

namespace App\Actions\ConstructionUnits;

use App\Support\TemporarySpreadsheetFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the standard spreadsheet used to import construction units.
 *
 * The first sheet carries only the headers, so nothing can be imported by
 * accident. The demonstration rows live on a separate "Exemplo" sheet, which the
 * importer never reads.
 */
class ConstructionUnitSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Unidades dos Empreendimentos.xlsx';

    public const DATA_SHEET = 'Unidades';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string>>
     */
    private const EXAMPLE_ROWS = [
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '102'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '103'],
        ['CRI Conviva', 'Conviva Camboinhas', '02', '201'],
    ];

    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function build(): string
    {
        return TemporarySpreadsheetFile::write('construction-units-template-', function (SimpleExcelWriter $writer): void {
            $writer->nameCurrentSheet(self::DATA_SHEET)
                ->addHeader(ConstructionUnitSpreadsheetColumns::headers());

            $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
                ->addHeader(ConstructionUnitSpreadsheetColumns::headers());

            foreach (self::EXAMPLE_ROWS as $exampleRow) {
                $writer->addRow(array_combine(ConstructionUnitSpreadsheetColumns::headers(), $exampleRow));
            }
        });
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
