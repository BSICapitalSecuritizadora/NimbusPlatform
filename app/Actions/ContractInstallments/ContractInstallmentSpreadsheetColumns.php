<?php

namespace App\Actions\ContractInstallments;

use Illuminate\Support\Str;

/**
 * Column contract shared by the template, the analysis and the import.
 */
class ContractInstallmentSpreadsheetColumns
{
    public const EMISSION = 'Emissão';

    public const CONSTRUCTION = 'Empreendimento';

    public const CONTRACT = 'Contrato';

    public const NUMBER = 'Número';

    public const DUE_DATE = 'Vencimento';

    public const EXPECTED_VALUE = 'Valor Previsto';

    public const PAYMENT_DATE = 'Data Pagamento';

    public const PAID_VALUE = 'Valor Pago';

    public const CANCELLATION_DATE = 'Data Cancelamento';

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
        'contrato' => self::CONTRACT,
        'codigo' => self::CONTRACT,
        'codigodocontrato' => self::CONTRACT,
        'numerodocontrato' => self::CONTRACT,
        'numero' => self::NUMBER,
        'numeroparcela' => self::NUMBER,
        'numerodaparcela' => self::NUMBER,
        'parcela' => self::NUMBER,
        'no' => self::NUMBER,
        'n' => self::NUMBER,
        'vencimento' => self::DUE_DATE,
        'datavencimento' => self::DUE_DATE,
        'datadevencimento' => self::DUE_DATE,
        'valorprevisto' => self::EXPECTED_VALUE,
        'valor' => self::EXPECTED_VALUE,
        'valorparcela' => self::EXPECTED_VALUE,
        'valordaparcela' => self::EXPECTED_VALUE,
        'datapagamento' => self::PAYMENT_DATE,
        'datadopagamento' => self::PAYMENT_DATE,
        'pagamento' => self::PAYMENT_DATE,
        'valorpago' => self::PAID_VALUE,
        'pago' => self::PAID_VALUE,
        'datacancelamento' => self::CANCELLATION_DATE,
        'datadocancelamento' => self::CANCELLATION_DATE,
        'cancelamento' => self::CANCELLATION_DATE,
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            self::EMISSION,
            self::CONSTRUCTION,
            self::CONTRACT,
            self::NUMBER,
            self::DUE_DATE,
            self::EXPECTED_VALUE,
            self::PAYMENT_DATE,
            self::PAID_VALUE,
            self::CANCELLATION_DATE,
        ];
    }

    /**
     * The payment and cancellation columns may be absent: a file with nothing
     * but a future schedule has nothing to put in them. Every other column has
     * to be there.
     *
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return array_values(array_diff(self::headers(), [
            self::PAYMENT_DATE,
            self::PAID_VALUE,
            self::CANCELLATION_DATE,
        ]));
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
