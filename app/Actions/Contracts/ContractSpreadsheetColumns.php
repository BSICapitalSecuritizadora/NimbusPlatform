<?php

namespace App\Actions\Contracts;

use Illuminate\Support\Str;

/**
 * Column contract shared by the template, the analysis and the import.
 */
class ContractSpreadsheetColumns
{
    public const EMISSION = 'Emissão';

    public const CONSTRUCTION = 'Empreendimento';

    public const BLOCK = 'Bloco';

    public const UNIT = 'Unidade';

    public const DOCUMENT = 'CPF/CNPJ';

    public const CODE = 'Contrato';

    public const SALE_DATE = 'Data Venda';

    public const SALE_VALUE = 'Valor Venda';

    public const STATUS = 'Status';

    public const CANCELLATION_DATE = 'Data Distrato';

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
        'cpfcnpj' => self::DOCUMENT,
        'cpf' => self::DOCUMENT,
        'cnpj' => self::DOCUMENT,
        'documento' => self::DOCUMENT,
        'contrato' => self::CODE,
        'codigo' => self::CODE,
        'codigodocontrato' => self::CODE,
        'numerodocontrato' => self::CODE,
        'datavenda' => self::SALE_DATE,
        'datadavenda' => self::SALE_DATE,
        'valorvenda' => self::SALE_VALUE,
        'valordavenda' => self::SALE_VALUE,
        'status' => self::STATUS,
        'situacao' => self::STATUS,
        'datadistrato' => self::CANCELLATION_DATE,
        'datadodistrato' => self::CANCELLATION_DATE,
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            self::EMISSION,
            self::CONSTRUCTION,
            self::BLOCK,
            self::UNIT,
            self::DOCUMENT,
            self::CODE,
            self::SALE_DATE,
            self::SALE_VALUE,
            self::STATUS,
            self::CANCELLATION_DATE,
        ];
    }

    /**
     * The distrato date column may be absent: a file with active contracts only
     * has nothing to put in it. Every other column has to be there.
     *
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return array_values(array_diff(self::headers(), [self::CANCELLATION_DATE]));
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
