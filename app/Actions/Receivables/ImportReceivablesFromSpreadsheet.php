<?php

namespace App\Actions\Receivables;

use App\Models\Emission;
use App\Models\Receivable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportReceivablesFromSpreadsheet
{
    protected const FIELD_MAP = [
        'mes_ano_referencia' => 'reference_month',
        'id_da_carteira' => 'portfolio_id',
        'numero_de_contratos_ativos' => 'active_contracts_count',
        'esperado_a_receber_no_mes_de_juros' => 'expected_interest_amount',
        'esperado_de_receber_no_mes_de_amortizacao' => 'expected_amortization_amount',
        'recebido_no_mes_de_parcelas_do_mes_juros' => 'received_installment_interest_amount',
        'recebido_no_mes_de_parcelas_do_mes_amortizacao' => 'received_installment_amortization_amount',
        'recebido_no_mes_de_antecipacao_juros' => 'received_prepayment_interest_amount',
        'recebido_no_mes_de_antecipacao_amortizacao' => 'received_prepayment_amortization_amount',
        'recebido_no_mes_de_inadimplencia_juros' => 'received_default_interest_amount',
        'recebido_no_mes_de_inadimplencia_amortizacao' => 'received_default_amortization_amount',
        'recebido_no_mes_de_juros_e_mora' => 'received_interest_and_penalty_amount',
        'saldo_devedor_da_carteria_adimplente_pre_evento_do_mes' => 'performing_balance_pre_event_amount',
        'saldo_devedor_da_carteria_inadimplente_pre_evento_do_mes' => 'non_performing_balance_pre_event_amount',
        'saldo_devedor_da_carteria_adimplente_pos_evento_do_mes' => 'performing_balance_post_event_amount',
        'saldo_devedor_da_carteria_inadimplente_pos_evento_do_mes' => 'non_performing_balance_post_event_amount',
        'saldo_inadimplencia_mes' => 'monthly_default_balance_amount',
        'saldo_inadimplencia_geral' => 'total_default_balance_amount',
        'creditos_vinculados_em_dia' => 'linked_credits_current_amount',
        'vencidos_e_nao_pagos_ate_30_dias' => 'overdue_up_to_30_days_amount',
        'vencidos_e_nao_pagos_de_31_a_60_dias' => 'overdue_31_to_60_days_amount',
        'vencidos_e_nao_pagos_ds_61_a_90_dias' => 'overdue_61_to_90_days_amount',
        'vencidos_e_nao_pagos_de_91_a_120_dias' => 'overdue_91_to_120_days_amount',
        'vencidos_e_nao_pagos_de_121_a_150_dias' => 'overdue_121_to_150_days_amount',
        'vencidos_e_nao_pagos_de_151_a_180_dias' => 'overdue_151_to_180_days_amount',
        'vencidos_e_nao_pagos_de_181_a_360_dias' => 'overdue_181_to_360_days_amount',
        'vencidos_e_nao_pagos_acima_de_360_dias' => 'overdue_over_360_days_amount',
        'pagos_antecipadamente_ate_30_dias' => 'prepaid_up_to_30_days_amount',
        'pagos_antecipadamente_de_31_a_60_dias' => 'prepaid_31_to_60_days_amount',
        'pagos_antecipadamente_ds_61_a_90_dias' => 'prepaid_61_to_90_days_amount',
        'pagos_antecipadamente_de_91_a_120_dias' => 'prepaid_91_to_120_days_amount',
        'pagos_antecipadamente_de_121_a_150_dias' => 'prepaid_121_to_150_days_amount',
        'pagos_antecipadamente_de_151_a_180_dias' => 'prepaid_151_to_180_days_amount',
        'pagos_antecipadamente_de_181_a_360_dias' => 'prepaid_181_to_360_days_amount',
        'pagos_antecipadamente_acima_de_360_dias' => 'prepaid_over_360_days_amount',
        'creditos_vinculados_ate_30_dias' => 'linked_credits_up_to_30_days_amount',
        'creditos_vinculados_de_31_a_60_dias' => 'linked_credits_31_to_60_days_amount',
        'creditos_vinculados_ds_61_a_90_dias' => 'linked_credits_61_to_90_days_amount',
        'creditos_vinculados_de_91_a_120_dias' => 'linked_credits_91_to_120_days_amount',
        'creditos_vinculados_de_121_a_150_dias' => 'linked_credits_121_to_150_days_amount',
        'creditos_vinculados_de_151_a_180_dias' => 'linked_credits_151_to_180_days_amount',
        'creditos_vinculados_de_181_a_360_dias' => 'linked_credits_181_to_360_days_amount',
        'creditos_vinculados_acima_de_360_dias' => 'linked_credits_over_360_days_amount',
        'valor_das_garantias_incorporadas_ao_pl_do_cri' => 'guarantees_value_amount',
        'valor_total_de_pre_pagamento_no_mes' => 'total_prepayment_amount',
        'de_concentracao_dos_5_maiores_devedores' => 'top_five_debtors_concentration_ratio',
        'saldo_devedor_total' => 'total_outstanding_balance_amount',
        'ltv' => 'portfolio_ltv_ratio',
        'ltv_venda' => 'sale_ltv_ratio',
        'duration_carteira_anos' => 'portfolio_duration_years',
        'duration_carteira_meses' => 'portfolio_duration_months',
        'taxa_media_da_carteira' => 'average_rate_details',
    ];

    protected const FIELD_LABELS = [
        'mes_ano_referencia' => 'Mes ano referencia',
        'id_da_carteira' => 'ID da Carteira',
        'numero_de_contratos_ativos' => 'Numero de contratos ativos',
        'esperado_a_receber_no_mes_de_juros' => 'Esperado a receber no Mes de Juros',
        'esperado_de_receber_no_mes_de_amortizacao' => 'Esperado de Receber no Mes de Amortizacao',
        'recebido_no_mes_de_parcelas_do_mes_juros' => 'Recebido no Mes de parcelas do Mes - Juros',
        'recebido_no_mes_de_parcelas_do_mes_amortizacao' => 'Recebido no Mes de parcelas do Mes - Amortizacao',
        'recebido_no_mes_de_antecipacao_juros' => 'Recebido no Mes de Antecipacao - Juros',
        'recebido_no_mes_de_antecipacao_amortizacao' => 'Recebido no Mes de Antecipacao - Amortizacao',
        'recebido_no_mes_de_inadimplencia_juros' => 'Recebido no mes de Inadimplencia - Juros',
        'recebido_no_mes_de_inadimplencia_amortizacao' => 'Recebido no mes de Inadimplencia - Amortizacao',
        'recebido_no_mes_de_juros_e_mora' => 'Recebido no Mes de Juros e Mora',
        'saldo_devedor_da_carteria_adimplente_pre_evento_do_mes' => 'Saldo devedor da carteria Adimplente pre evento do mes',
        'saldo_devedor_da_carteria_inadimplente_pre_evento_do_mes' => 'Saldo devedor da carteria Inadimplente pre evento do mes',
        'saldo_devedor_da_carteria_adimplente_pos_evento_do_mes' => 'Saldo devedor da carteria Adimplente pos evento do mes',
        'saldo_devedor_da_carteria_inadimplente_pos_evento_do_mes' => 'Saldo devedor da carteria Inadimplente pos evento do mes',
        'saldo_inadimplencia_mes' => 'Saldo Inadimplencia Mes',
        'saldo_inadimplencia_geral' => 'Saldo Inadimplencia Geral',
        'creditos_vinculados_em_dia' => 'Creditos vinculados em dia',
        'vencidos_e_nao_pagos_ate_30_dias' => 'Vencidos E Nao Pagos Ate 30 Dias',
        'vencidos_e_nao_pagos_de_31_a_60_dias' => 'Vencidos E Nao Pagos De 31 A 60 Dias',
        'vencidos_e_nao_pagos_ds_61_a_90_dias' => 'Vencidos E Nao Pagos Ds 61 A 90 Dias',
        'vencidos_e_nao_pagos_de_91_a_120_dias' => 'Vencidos E Nao Pagos De 91 A 120 Dias',
        'vencidos_e_nao_pagos_de_121_a_150_dias' => 'Vencidos E Nao Pagos De 121 A 150 Dias',
        'vencidos_e_nao_pagos_de_151_a_180_dias' => 'Vencidos E Nao Pagos De 151 A 180 Dias',
        'vencidos_e_nao_pagos_de_181_a_360_dias' => 'Vencidos E Nao Pagos De 181 A 360 Dias',
        'vencidos_e_nao_pagos_acima_de_360_dias' => 'Vencidos E Nao Pagos Acima De 360 Dias',
        'pagos_antecipadamente_ate_30_dias' => 'Pagos Antecipadamente Ate 30 Dias',
        'pagos_antecipadamente_de_31_a_60_dias' => 'Pagos Antecipadamente De 31 A 60 Dias',
        'pagos_antecipadamente_ds_61_a_90_dias' => 'Pagos Antecipadamente Ds 61 A 90 Dias',
        'pagos_antecipadamente_de_91_a_120_dias' => 'Pagos Antecipadamente De 91 A 120 Dias',
        'pagos_antecipadamente_de_121_a_150_dias' => 'Pagos Antecipadamente De 121 A 150 Dias',
        'pagos_antecipadamente_de_151_a_180_dias' => 'Pagos Antecipadamente De 151 A 180 Dias',
        'pagos_antecipadamente_de_181_a_360_dias' => 'Pagos Antecipadamente De 181 A 360 Dias',
        'pagos_antecipadamente_acima_de_360_dias' => 'Pagos Antecipadamente Acima De 360 Dias',
        'creditos_vinculados_ate_30_dias' => 'Creditos Vinculados Ate 30 Dias',
        'creditos_vinculados_de_31_a_60_dias' => 'Creditos Vinculados De 31 A 60 Dias',
        'creditos_vinculados_ds_61_a_90_dias' => 'Creditos Vinculados Ds 61 A 90 Dias',
        'creditos_vinculados_de_91_a_120_dias' => 'Creditos Vinculados De 91 A 120 Dias',
        'creditos_vinculados_de_121_a_150_dias' => 'Creditos Vinculados De 121 A 150 Dias',
        'creditos_vinculados_de_151_a_180_dias' => 'Creditos Vinculados De 151 A 180 Dias',
        'creditos_vinculados_de_181_a_360_dias' => 'Creditos Vinculados De 181 A 360 Dias',
        'creditos_vinculados_acima_de_360_dias' => 'Creditos Vinculados Acima De 360 Dias',
        'valor_das_garantias_incorporadas_ao_pl_do_cri' => 'Valor das garantias incorporadas ao PL do CRI',
        'valor_total_de_pre_pagamento_no_mes' => 'Valor total de pre-pagamento no mes',
        'de_concentracao_dos_5_maiores_devedores' => '% De Concentracao Dos 5 Maiores Devedores',
        'saldo_devedor_total' => 'Saldo devedor Total',
        'ltv' => 'LTV',
        'ltv_venda' => 'LTV Venda',
        'duration_carteira_anos' => 'Duration Carteira (Anos)',
        'duration_carteira_meses' => 'Duration Carteira (Meses)',
        'taxa_media_da_carteira' => 'Taxa Media da Carteira',
    ];

    protected const INTEGER_FIELDS = [
        'active_contracts_count',
    ];

    protected const OPTIONAL_LABELS = [
        'creditos_vinculados_em_dia',
        'de_concentracao_dos_5_maiores_devedores',
        'ltv_venda',
    ];

    protected const OPTIONAL_NUMERIC_FIELDS = [
        'guarantees_value_amount',
        'top_five_debtors_concentration_ratio',
        'portfolio_ltv_ratio',
        'sale_ltv_ratio',
    ];

    protected const DECIMAL_FIELDS = [
        'top_five_debtors_concentration_ratio',
        'portfolio_ltv_ratio',
        'sale_ltv_ratio',
        'portfolio_duration_years',
        'portfolio_duration_months',
    ];

    /**
     * @return array{created: int, updated: int, total: int, reference_month: string}
     */
    public function handle(string $path, Emission $emission): array
    {
        $this->ensureRequiredSheet($path);

        [$mappedData, $payload] = $this->extractSummaryData($path);
        $mappedData['emission_id'] = $emission->id;
        $mappedData['summary_payload'] = $payload;

        $validatedData = Validator::make(
            $mappedData,
            $this->rules(),
            $this->messages(),
            $this->attributes(),
        )->validate();

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($validatedData, &$created, &$updated): void {
            $existingReceivable = Receivable::query()
                ->where('emission_id', $validatedData['emission_id'])
                ->whereDate('reference_month', $validatedData['reference_month'])
                ->first();

            if ($existingReceivable) {
                $existingReceivable->fill($validatedData)->save();
                $updated = 1;

                return;
            }

            Receivable::query()->create($validatedData);
            $created = 1;
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
            'reference_month' => $validatedData['reference_month'],
        ];
    }

    protected function ensureRequiredSheet(string $path): void
    {
        $sheetNames = SimpleExcelReader::create($path)->getSheetNames();

        if (! in_array('Resumo', $sheetNames, true)) {
            throw ValidationException::withMessages([
                'file' => ['A planilha precisa conter a aba "Resumo".'],
            ]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    protected function extractSummaryData(string $path): array
    {
        $rows = SimpleExcelReader::create($path)
            ->fromSheetName('Resumo')
            ->noHeaderRow()
            ->getRows();

        $rowsByLabel = [];
        $payload = [];
        $averageRateLines = [];
        $currentNormalizedLabel = null;

        foreach ($rows as $index => $row) {
            $label = Receivable::normalizeText($row[0] ?? null);
            $value = $row[1] ?? null;
            $note = Receivable::normalizeText($row[2] ?? null);

            if (($label === null) && ($value === null) && ($note === null)) {
                continue;
            }

            $normalizedLabel = $label !== null ? $this->normalizeLabel($label) : null;

            $payload[] = [
                'line' => $index + 1,
                'label' => $label,
                'normalized_label' => $normalizedLabel,
                'value' => $this->serializeValue($value),
                'note' => $note,
            ];

            if ($normalizedLabel !== null) {
                $rowsByLabel[$normalizedLabel] = [
                    'line' => $index + 1,
                    'label' => $label,
                    'value' => $value,
                    'note' => $note,
                ];

                $currentNormalizedLabel = $normalizedLabel;

                if (($normalizedLabel === 'taxa_media_da_carteira') && filled($value)) {
                    $averageRateLines[] = trim((string) $value);
                }

                continue;
            }

            if (($currentNormalizedLabel === 'taxa_media_da_carteira') && filled($value)) {
                $averageRateLines[] = trim((string) $value);
            }
        }

        $missingLabels = array_values(array_diff(array_keys(self::FIELD_MAP), array_keys($rowsByLabel)));
        $missingRequiredLabels = array_values(array_diff($missingLabels, self::OPTIONAL_LABELS));

        if ($missingRequiredLabels !== []) {
            $missingRows = array_map(
                fn (string $label): string => self::FIELD_LABELS[$label] ?? $label,
                $missingRequiredLabels,
            );

            throw ValidationException::withMessages([
                'file' => [
                    'A aba "Resumo" nao segue a estrutura esperada para importacao.',
                    'Linhas ausentes: '.implode(', ', $missingRows).'.',
                ],
            ]);
        }

        $mappedData = [];

        foreach (self::FIELD_MAP as $normalizedLabel => $field) {
            $mappedData[$field] = array_key_exists($normalizedLabel, $rowsByLabel)
                ? $this->mapFieldValue($field, $rowsByLabel[$normalizedLabel]['value'] ?? null)
                : $this->resolveMissingFieldValue($normalizedLabel, $field, $rowsByLabel);
        }

        $mappedData['average_rate_details'] = Receivable::normalizeMultilineText(implode(PHP_EOL, $averageRateLines));

        return [$mappedData, $payload];
    }

    protected function mapFieldValue(string $field, mixed $value): mixed
    {
        if ($field === 'reference_month') {
            return Receivable::normalizeReferenceMonth($value);
        }

        if ($field === 'portfolio_id') {
            return Receivable::normalizeText($value);
        }

        if (in_array($field, self::INTEGER_FIELDS, true)) {
            return Receivable::normalizeInteger($value);
        }

        if ($field === 'average_rate_details') {
            return Receivable::normalizeText($value);
        }

        if (in_array($field, self::DECIMAL_FIELDS, true)) {
            return Receivable::normalizeMetricDecimal($value);
        }

        return Receivable::normalizeMoney($value);
    }

    /**
     * @param  array<string, array{line:int,label:?string,value:mixed,note:?string}>  $rowsByLabel
     */
    protected function resolveMissingFieldValue(string $normalizedLabel, string $field, array $rowsByLabel): mixed
    {
        if ($normalizedLabel === 'creditos_vinculados_em_dia') {
            return $this->sumLinkedCreditBuckets($rowsByLabel);
        }

        return null;
    }

    /**
     * @param  array<string, array{line:int,label:?string,value:mixed,note:?string}>  $rowsByLabel
     */
    protected function sumLinkedCreditBuckets(array $rowsByLabel): ?float
    {
        $bucketLabels = [
            'creditos_vinculados_ate_30_dias',
            'creditos_vinculados_de_31_a_60_dias',
            'creditos_vinculados_ds_61_a_90_dias',
            'creditos_vinculados_de_91_a_120_dias',
            'creditos_vinculados_de_121_a_150_dias',
            'creditos_vinculados_de_151_a_180_dias',
            'creditos_vinculados_de_181_a_360_dias',
            'creditos_vinculados_acima_de_360_dias',
        ];

        $sum = 0.0;

        foreach ($bucketLabels as $bucketLabel) {
            if (! array_key_exists($bucketLabel, $rowsByLabel)) {
                return null;
            }

            $amount = Receivable::normalizeMoney($rowsByLabel[$bucketLabel]['value'] ?? null);

            if ($amount === null) {
                return null;
            }

            $sum += $amount;
        }

        return round($sum, 2);
    }

    protected function normalizeLabel(string $label): string
    {
        return Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    protected function serializeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = [
            'emission_id' => 'required|exists:emissions,id',
            'reference_month' => 'required|date',
            'portfolio_id' => 'required|string|max:255',
            'active_contracts_count' => 'required|integer|min:0',
            'average_rate_details' => 'required|string',
            'summary_payload' => 'nullable|array',
        ];

        foreach (self::FIELD_MAP as $normalizedLabel => $field) {
            if (in_array($field, ['reference_month', 'portfolio_id', 'active_contracts_count', 'average_rate_details'], true)) {
                continue;
            }

            if (in_array($field, self::OPTIONAL_NUMERIC_FIELDS, true)) {
                $rules[$field] = 'nullable|numeric|min:0';

                continue;
            }

            $rules[$field] = 'required|numeric|min:0';
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'O campo :attribute e obrigatorio.',
            'date' => 'O campo :attribute deve conter uma data valida.',
            'numeric' => 'O campo :attribute deve conter um valor numerico valido.',
            'integer' => 'O campo :attribute deve conter um numero inteiro valido.',
            'min' => 'O campo :attribute nao pode ser negativo.',
            'exists' => 'A emissao selecionada nao existe mais.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [
            'reference_month' => 'competencia',
            'portfolio_id' => 'carteira',
            'active_contracts_count' => 'numero de contratos ativos',
            'expected_interest_amount' => 'esperado a receber no mes de juros',
            'expected_amortization_amount' => 'esperado a receber no mes de amortizacao',
            'received_installment_interest_amount' => 'recebido no mes de parcelas do mes - juros',
            'received_installment_amortization_amount' => 'recebido no mes de parcelas do mes - amortizacao',
            'received_prepayment_interest_amount' => 'recebido no mes de antecipacao - juros',
            'received_prepayment_amortization_amount' => 'recebido no mes de antecipacao - amortizacao',
            'received_default_interest_amount' => 'recebido no mes de inadimplencia - juros',
            'received_default_amortization_amount' => 'recebido no mes de inadimplencia - amortizacao',
            'received_interest_and_penalty_amount' => 'recebido no mes de juros e mora',
            'performing_balance_pre_event_amount' => 'saldo devedor da carteira adimplente pre evento do mes',
            'non_performing_balance_pre_event_amount' => 'saldo devedor da carteira inadimplente pre evento do mes',
            'performing_balance_post_event_amount' => 'saldo devedor da carteira adimplente pos evento do mes',
            'non_performing_balance_post_event_amount' => 'saldo devedor da carteira inadimplente pos evento do mes',
            'monthly_default_balance_amount' => 'saldo inadimplencia mes',
            'total_default_balance_amount' => 'saldo inadimplencia geral',
            'linked_credits_current_amount' => 'creditos vinculados em dia',
            'overdue_up_to_30_days_amount' => 'vencidos e nao pagos ate 30 dias',
            'overdue_31_to_60_days_amount' => 'vencidos e nao pagos de 31 a 60 dias',
            'overdue_61_to_90_days_amount' => 'vencidos e nao pagos de 61 a 90 dias',
            'overdue_91_to_120_days_amount' => 'vencidos e nao pagos de 91 a 120 dias',
            'overdue_121_to_150_days_amount' => 'vencidos e nao pagos de 121 a 150 dias',
            'overdue_151_to_180_days_amount' => 'vencidos e nao pagos de 151 a 180 dias',
            'overdue_181_to_360_days_amount' => 'vencidos e nao pagos de 181 a 360 dias',
            'overdue_over_360_days_amount' => 'vencidos e nao pagos acima de 360 dias',
            'prepaid_up_to_30_days_amount' => 'pagos antecipadamente ate 30 dias',
            'prepaid_31_to_60_days_amount' => 'pagos antecipadamente de 31 a 60 dias',
            'prepaid_61_to_90_days_amount' => 'pagos antecipadamente de 61 a 90 dias',
            'prepaid_91_to_120_days_amount' => 'pagos antecipadamente de 91 a 120 dias',
            'prepaid_121_to_150_days_amount' => 'pagos antecipadamente de 121 a 150 dias',
            'prepaid_151_to_180_days_amount' => 'pagos antecipadamente de 151 a 180 dias',
            'prepaid_181_to_360_days_amount' => 'pagos antecipadamente de 181 a 360 dias',
            'prepaid_over_360_days_amount' => 'pagos antecipadamente acima de 360 dias',
            'linked_credits_up_to_30_days_amount' => 'creditos vinculados ate 30 dias',
            'linked_credits_31_to_60_days_amount' => 'creditos vinculados de 31 a 60 dias',
            'linked_credits_61_to_90_days_amount' => 'creditos vinculados de 61 a 90 dias',
            'linked_credits_91_to_120_days_amount' => 'creditos vinculados de 91 a 120 dias',
            'linked_credits_121_to_150_days_amount' => 'creditos vinculados de 121 a 150 dias',
            'linked_credits_151_to_180_days_amount' => 'creditos vinculados de 151 a 180 dias',
            'linked_credits_181_to_360_days_amount' => 'creditos vinculados de 181 a 360 dias',
            'linked_credits_over_360_days_amount' => 'creditos vinculados acima de 360 dias',
            'guarantees_value_amount' => 'valor das garantias incorporadas ao PL do CRI',
            'total_prepayment_amount' => 'valor total de pre-pagamento no mes',
            'top_five_debtors_concentration_ratio' => 'percentual de concentracao dos 5 maiores devedores',
            'total_outstanding_balance_amount' => 'saldo devedor total',
            'portfolio_ltv_ratio' => 'LTV',
            'sale_ltv_ratio' => 'LTV venda',
            'portfolio_duration_years' => 'duration carteira (anos)',
            'portfolio_duration_months' => 'duration carteira (meses)',
            'average_rate_details' => 'taxa media da carteira',
        ];
    }
}
