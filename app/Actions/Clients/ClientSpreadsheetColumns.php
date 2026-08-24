<?php

namespace App\Actions\Clients;

use Illuminate\Support\Str;

/**
 * Column contract shared by the template, the analysis and the import.
 */
class ClientSpreadsheetColumns
{
    public const TYPE = 'Tipo';

    public const NAME = 'Nome / Razão Social';

    public const DOCUMENT = 'CPF/CNPJ';

    public const EMAIL = 'E-mail';

    public const PHONE = 'Telefone';

    /**
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'tipo' => self::TYPE,
        'tipodepessoa' => self::TYPE,
        'tipopessoa' => self::TYPE,
        'nomerazaosocial' => self::NAME,
        'nome' => self::NAME,
        'razaosocial' => self::NAME,
        'cliente' => self::NAME,
        'cpfcnpj' => self::DOCUMENT,
        'documento' => self::DOCUMENT,
        'cpf' => self::DOCUMENT,
        'cnpj' => self::DOCUMENT,
        'email' => self::EMAIL,
        'telefone' => self::PHONE,
        'celular' => self::PHONE,
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [self::TYPE, self::NAME, self::DOCUMENT, self::EMAIL, self::PHONE];
    }

    /**
     * Columns that must be present for the file to be readable at all.
     *
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return [self::TYPE, self::NAME, self::DOCUMENT];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
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
