<?php

namespace App\Services\Funds;

use App\Models\ContaAzulImportedBill;

/**
 * Deterministic classifier for external bill changes (Phase 3B).
 *
 * A changed fingerprint on reimport only says *something* moved. This policy
 * decides whether the move touches attribution-relevant evidence — amount,
 * supplier, due date, category, cost center, payment state — or harmless
 * metadata such as descriptions and external timestamps. Relevant changes
 * on an attributed bill raise renewed review; harmless ones never do.
 *
 * Pure functions only: no database, no configuration, deterministic output.
 * The comparison runs in the normalized domain (the same values staging
 * persists), so formatting churn in the raw payload cannot flag a bill.
 */
final class ContaAzulExternalChangePolicy
{
    /**
     * Normalized fields whose change may invalidate an attribution
     * decision, with human labels for the review queue.
     *
     * @var array<string, string>
     */
    public const RELEVANT_FIELDS = [
        'total_amount' => 'Valor nominal',
        'paid_amount' => 'Valor pago',
        'unpaid_amount' => 'Valor em aberto',
        'due_date' => 'Vencimento',
        'payment_date' => 'Data de pagamento',
        'competence_date' => 'Competência',
        'external_status' => 'Status externo',
        'external_status_translated' => 'Status externo traduzido',
        'mapped_category' => 'Categoria mapeada',
        'categories' => 'Categorias Conta Azul',
        'cost_centers' => 'Centros de custo',
        'supplier_name' => 'Fornecedor',
        'supplier_external_id' => 'Identificador do fornecedor',
    ];

    /**
     * Compare persisted content against freshly normalized content.
     *
     * @param  array<string, mixed>  $normalized  Fresh normalized values in the ingestion domain.
     * @return array{relevant: list<string>, harmless_only: bool}
     */
    public static function diff(ContaAzulImportedBill $bill, array $normalized): array
    {
        $relevant = [];

        foreach (self::RELEVANT_FIELDS as $field => $label) {
            if (! self::sameValue($field, $bill->getAttribute($field), $normalized[$field] ?? null)) {
                $relevant[] = $label;
            }
        }

        return [
            'relevant' => $relevant,
            'harmless_only' => $relevant === [],
        ];
    }

    private static function sameValue(string $field, mixed $current, mixed $fresh): bool
    {
        if (in_array($field, ['categories', 'cost_centers'], true)) {
            return self::canonicalJson($current) === self::canonicalJson($fresh);
        }

        if (in_array($field, ['total_amount', 'paid_amount', 'unpaid_amount'], true)) {
            return self::canonicalMoney($current) === self::canonicalMoney($fresh);
        }

        if (in_array($field, ['due_date', 'payment_date', 'competence_date'], true)) {
            return self::canonicalDate($current) === self::canonicalDate($fresh);
        }

        $left = $current === null ? null : trim((string) $current);
        $right = $fresh === null ? null : trim((string) $fresh);

        if ($left === '') {
            $left = null;
        }

        if ($right === '') {
            $right = null;
        }

        return $left === $right;
    }

    private static function canonicalMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function canonicalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) === 1) {
            return $matches[1];
        }

        return trim((string) $value);
    }

    private static function canonicalJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (! is_array($decoded)) {
                return trim($value) === '' ? null : trim($value);
            }

            $value = $decoded;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
