<?php

namespace App\Actions\Clients;

use App\Enums\ClientPersonType;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists the rows approved by {@see AnalyzeClientSpreadsheet}.
 *
 * Nothing is written unless the whole spreadsheet is importable, and the rows go
 * in chunks inside a single transaction: either every client is created or none
 * is. Existing clients -- active or soft deleted -- are never overwritten,
 * because a row matching one of them blocks the import upfront.
 */
class ImportClientsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{clients: int, individuals: int, companies: int}
     */
    public function handle(ClientSpreadsheetAnalysis $analysis): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $validRows = $analysis->validRows();

        DB::transaction(function () use ($validRows): void {
            $validRows
                ->chunk(self::CHUNK_SIZE)
                ->each(function ($chunk): void {
                    foreach ($chunk as $row) {
                        // Created through the model so casts, normalization and
                        // the activity log behave exactly as in the form.
                        Client::query()->create([
                            'person_type' => $row['person_type'],
                            'name' => $row['name'],
                            'document' => $row['normalized_document'],
                            'email' => $row['email'],
                            'phone' => $row['phone'],
                        ]);
                    }
                });
        });

        return [
            'clients' => $validRows->count(),
            'individuals' => $validRows->where('person_type', ClientPersonType::Individual)->count(),
            'companies' => $validRows->where('person_type', ClientPersonType::Company)->count(),
        ];
    }
}
