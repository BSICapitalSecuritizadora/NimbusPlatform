<?php

namespace App\Actions\ConstructionUnitValues;

use Illuminate\Support\Str;

/**
 * Column contract of the batch value update, shared by the template, the
 * analysis and the import.
 *
 * Deliberately not the same file as the unit registration spreadsheet. Creating
 * a unit and repricing it are different acts with different consequences -- one
 * writes cadastro, the other writes financial history -- and a single file that
 * did both would let a typo in the unit column silently create a unit instead of
 * repricing one.
 */
class UnitValueSpreadsheetColumns
{
    public const EMISSION = 'Emissão';

    public const CONSTRUCTION = 'Empreendimento';

    public const BLOCK = 'Bloco';

    public const UNIT = 'Unidade';

    public const VALUE = 'Valor Atualizado';

    public const EFFECTIVE_FROM = 'Vigência';

    public const REASON = 'Motivo';

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
        'valoratualizado' => self::VALUE,
        'valor' => self::VALUE,
        'novovalor' => self::VALUE,
        'value' => self::VALUE,
        'vigencia' => self::EFFECTIVE_FROM,
        'datadevigencia' => self::EFFECTIVE_FROM,
        'effectivefrom' => self::EFFECTIVE_FROM,
        'motivo' => self::REASON,
        'justificativa' => self::REASON,
        'reason' => self::REASON,
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [...self::requiredHeaders(), self::REASON];
    }

    /**
     * The reason is optional per row: a whole batch usually shares one, and the
     * wizard collects it once.
     *
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return [self::EMISSION, self::CONSTRUCTION, self::BLOCK, self::UNIT, self::VALUE, self::EFFECTIVE_FROM];
    }

    /**
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
