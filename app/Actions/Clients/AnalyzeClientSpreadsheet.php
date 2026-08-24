<?php

namespace App\Actions\Clients;

use App\Enums\ClientPersonType;
use App\Models\Client;
use App\Rules\ClientDocument;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads an import spreadsheet and classifies every row, without writing
 * anything. The result feeds the preview and, once confirmed, the import.
 *
 * Document validation goes through the very same {@see ClientDocument} rule the
 * form uses, so neither flow can accept what the other rejects.
 *
 * Reported line numbers count the header plus the data rows returned by the
 * reader. Fully blank rows are dropped by the reader itself, so a file with
 * blank rows in the middle reports the lines below them shifted up.
 */
class AnalyzeClientSpreadsheet
{
    public const STATUS_VALID = 'valido';

    public const STATUS_EMPTY = 'vazia';

    public const STATUS_ERROR = 'erro';

    public const STATUS_ALREADY_REGISTERED = 'ja_cadastrado';

    public const STATUS_SOFT_DELETED = 'cadastro_excluido';

    public const STATUS_DUPLICATED_IN_FILE = 'duplicado_na_planilha';

    private const CHUNK_SIZE = 500;

    public function handle(string $path): ClientSpreadsheetAnalysis
    {
        $firstRow = SimpleExcelReader::create($path)->getRows()->first();

        if ($firstRow === null) {
            return ClientSpreadsheetAnalysis::emptyFile();
        }

        $resolvedHeaders = ClientSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = ClientSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return ClientSpreadsheetAnalysis::invalidHeaders($missingHeaders);
        }

        $analyzedRows = [];
        $seenDocuments = [];
        $lineNumber = 1;

        SimpleExcelReader::create($path)
            ->getRows()
            ->chunk(self::CHUNK_SIZE)
            ->each(function ($chunk) use (&$analyzedRows, &$seenDocuments, &$lineNumber, $resolvedHeaders): void {
                foreach ($chunk as $row) {
                    $lineNumber++;
                    $analyzedRows[] = $this->analyzeRow($row, $resolvedHeaders, $lineNumber, $seenDocuments);
                }
            });

        return new ClientSpreadsheetAnalysis($analyzedRows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     * @param  array<string, int>  $seenDocuments
     * @return array<string, mixed>
     */
    private function analyzeRow(array $row, array $resolvedHeaders, int $lineNumber, array &$seenDocuments): array
    {
        $type = $this->cell($row, $resolvedHeaders, ClientSpreadsheetColumns::TYPE);
        $name = $this->cell($row, $resolvedHeaders, ClientSpreadsheetColumns::NAME);
        $document = $this->cell($row, $resolvedHeaders, ClientSpreadsheetColumns::DOCUMENT);
        $email = $this->cell($row, $resolvedHeaders, ClientSpreadsheetColumns::EMAIL);
        $phone = $this->cell($row, $resolvedHeaders, ClientSpreadsheetColumns::PHONE);

        $base = [
            'line' => $lineNumber,
            'type' => $type,
            'name' => $name,
            'document' => Client::formatDocument($document),
            'email' => $email,
            'phone' => $phone,
            'person_type' => null,
            'normalized_document' => null,
            'existing_client_id' => null,
        ];

        if (blank($type) && blank($name) && blank($document) && blank($email) && blank($phone)) {
            return [...$base, 'status' => self::STATUS_EMPTY, 'message' => 'Linha vazia (ignorada).'];
        }

        $missingFields = array_values(array_filter([
            blank($type) ? ClientSpreadsheetColumns::TYPE : null,
            blank($name) ? ClientSpreadsheetColumns::NAME : null,
            blank($document) ? ClientSpreadsheetColumns::DOCUMENT : null,
        ]));

        if ($missingFields !== []) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'Campos obrigatórios não preenchidos: '.implode(', ', $missingFields).'.'];
        }

        $personType = ClientPersonType::tryFromLabel($type);

        if ($personType === null) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'Tipo de pessoa inválido. Utilize PF ou PJ.'];
        }

        $base['person_type'] = $personType;
        $base['document'] = Client::formatDocument($document);

        if (filled($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'E-mail inválido.'];
        }

        $normalizedDocument = Client::normalizeDocument($document);
        $base['normalized_document'] = $normalizedDocument;

        // The very same rule the form applies. Format and duplicate are checked
        // separately so the preview can tell a malformed document apart from a
        // well-formed one that already belongs to somebody.
        $rule = new ClientDocument($personType);

        $formatFailure = $rule->checkFormat($document);

        if ($formatFailure !== null) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => $formatFailure];
        }

        $existingClient = Client::findByDocument($normalizedDocument);

        if ($existingClient !== null) {
            // The id travels with the row so the preview can send the user
            // straight to the existing client -- the soft deleted one has to be
            // restored, never registered again.
            return [
                ...$base,
                'existing_client_id' => $existingClient->getKey(),
                'status' => $existingClient->trashed() ? self::STATUS_SOFT_DELETED : self::STATUS_ALREADY_REGISTERED,
                'message' => $rule->duplicateMessage($normalizedDocument),
            ];
        }

        if (isset($seenDocuments[$normalizedDocument])) {
            return [
                ...$base,
                'status' => self::STATUS_DUPLICATED_IN_FILE,
                'message' => "Documento repetido na planilha (linha {$seenDocuments[$normalizedDocument]}).",
            ];
        }

        $seenDocuments[$normalizedDocument] = $lineNumber;

        return [...$base, 'status' => self::STATUS_VALID, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function cell(array $row, array $resolvedHeaders, string $column): ?string
    {
        if (! isset($resolvedHeaders[$column])) {
            return null;
        }

        $value = $row[$resolvedHeaders[$column]] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
