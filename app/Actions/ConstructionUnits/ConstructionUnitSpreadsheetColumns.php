<?php

namespace App\Actions\ConstructionUnits;

use Illuminate\Support\Str;

/**
 * Column contract shared by the template, the analysis and the import.
 */
class ConstructionUnitSpreadsheetColumns
{
    public const EMISSION = 'Emissão';

    public const CONSTRUCTION = 'Empreendimento';

    public const BLOCK = 'Bloco';

    public const UNIT = 'Unidade';

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
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
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
        return array_values(array_diff(self::headers(), array_keys($resolvedHeaders)));
    }

    private static function normalize(string $header): string
    {
        return Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();
    }
}
