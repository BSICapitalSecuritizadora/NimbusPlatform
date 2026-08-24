<?php

namespace App\Actions\Clients;

use App\Support\TemporarySpreadsheetFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the standard spreadsheet used to import clients.
 *
 * The first sheet carries only the headers, so nothing can be imported by
 * accident. The demonstration rows live on a separate "Exemplo" sheet, which the
 * importer never reads, and use documents with valid check digits so the example
 * never teaches an invalid CPF/CNPJ.
 */
class ClientSpreadsheetTemplate
{
    public const DOWNLOAD_NAME = 'Modelo - Clientes.xlsx';

    public const DATA_SHEET = 'Clientes';

    public const EXAMPLE_SHEET = 'Exemplo';

    /**
     * @var list<array<int, string>>
     */
    private const EXAMPLE_ROWS = [
        ['PF', 'João da Silva', '529.982.247-25', 'joao@email.com', '21999999999'],
        ['PJ', 'Empresa Exemplo Ltda', '11.222.333/0001-81', 'contato@empresa.com', '2133333333'],
    ];

    public function build(): string
    {
        return TemporarySpreadsheetFile::write('clients-template-', function (SimpleExcelWriter $writer): void {
            $writer->nameCurrentSheet(self::DATA_SHEET)
                ->addHeader(ClientSpreadsheetColumns::headers());

            $writer->addNewSheetAndMakeItCurrent(self::EXAMPLE_SHEET)
                ->addHeader(ClientSpreadsheetColumns::headers());

            foreach (self::EXAMPLE_ROWS as $exampleRow) {
                $writer->addRow(array_combine(ClientSpreadsheetColumns::headers(), $exampleRow));
            }
        });
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }
}
