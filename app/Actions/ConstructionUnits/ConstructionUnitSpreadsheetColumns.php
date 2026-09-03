<?php

namespace App\Actions\ConstructionUnits;

use Illuminate\Support\Str;

/**
 * Column contract shared by the template, the analysis and the import.
 *
 * The base value pair was added after the first spreadsheets were already in
 * circulation, so it is optional: a file with only the four original columns
 * keeps importing exactly as before. What is not optional is informing both
 * halves of the pair -- a value with no reference date cannot be placed in time,
 * and a date with no value places nothing.
 */
class ConstructionUnitSpreadsheetColumns
{
    public const EMISSION = 'Emissão';

    public const CONSTRUCTION = 'Empreendimento';

    public const BLOCK = 'Bloco';

    public const UNIT = 'Unidade';

    public const BASE_VALUE = 'Valor Base';

    public const BASE_VALUE_REFERENCE_DATE = 'Data de Referência do Valor Base';

    /**
     * Accepted header spellings, normalized (lowercase, no accents, no spaces).
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'emissao' => self::EMISSION,
        'emission' => self::EMISSION,
        'operacao' => self::EMISSION,
        'empreendimento' => self::CONSTRUCTION,
        'obra' => self::CONSTRUCTION,
        'construction' => self::CONSTRUCTION,
        'bloco' => self::BLOCK,
        'block' => self::BLOCK,
        'torre' => self::BLOCK,
        'unidade' => self::UNIT,
        'unit' => self::UNIT,
        'apartamento' => self::UNIT,
        'valorbase' => self::BASE_VALUE,
        'valordebase' => self::BASE_VALUE,
        'basevalue' => self::BASE_VALUE,
        'datadereferenciadovalorbase' => self::BASE_VALUE_REFERENCE_DATE,
        'datadereferencia' => self::BASE_VALUE_REFERENCE_DATE,
        'referenciadovalorbase' => self::BASE_VALUE_REFERENCE_DATE,
        'basevaluereferencedate' => self::BASE_VALUE_REFERENCE_DATE,
    ];

    /**
     * Columns the template writes: the required ones plus the optional pair.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return [...self::requiredHeaders(), self::BASE_VALUE, self::BASE_VALUE_REFERENCE_DATE];
    }

    /**
     * Columns a file must carry to be read at all.
     *
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return [self::EMISSION, self::CONSTRUCTION, self::BLOCK, self::UNIT];
    }

    /**
     * Maps the spreadsheet header row onto the canonical column names.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string> canonical column => original header
     */
    public static function resolve(array $row): array
    {
        $resolved = [];

        foreach (array_keys($row) as $header) {
            $canonical = self::HEADER_ALIASES[self::normalize((string) $header)] ?? null;

            if (($canonical !== null) && ! isset($resolved[$canonical])) {
                $resolved[$canonical] = (string) $header;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $resolvedHeaders
     * @return list<string>
     */
    public static function missingHeaders(array $resolvedHeaders): array
    {
        return array_values(array_diff(self::requiredHeaders(), array_keys($resolvedHeaders)));
    }

    private static function normalize(string $header): string
    {
        return Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();
    }
}
