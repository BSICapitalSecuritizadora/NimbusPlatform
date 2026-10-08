<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Cronograma de Pagamentos (`payments`) volta a ser só o cronograma INFORMADO
 * (planilha ou cadastro manual) -- Fase 5.
 *
 * Até aqui a conciliação da curva oficial escrevia o valor calculado nas colunas
 * de juros e amortização da linha e guardava o informado em `expected_*`. Um
 * campo representava dois fatos, e um cálculo novo apagava o anterior. Desde a
 * Fase 5 o valor esperado oficial vive em `emission_pu_obligation_calculations`
 * (versionado, por componente) e a liquidação em `emission_pu_settlements`;
 * nada escreve mais o cálculo em `payments`.
 *
 * Esta migration só toca linhas marcadas `value_source = official_curve` --
 * produção não tem nenhuma (o PU nunca foi usado lá; a primeira curva oficial
 * nasce depois desta fase), então lá ela não faz nada. Em bancos de
 * desenvolvimento:
 *
 * - linha que tinha valor informado: juros e amortização voltam ao informado
 *   guardado (`expected_*`; sem informado, zero), prêmio e amortização
 *   extraordinária zerados pela conciliação anterior à Fase 2 voltam do
 *   `expected_*`, e as marcas da curva são limpas;
 * - linha criada pela própria conciliação (nenhum valor informado guardado, sem
 *   prêmio nem amortização extraordinária): era só projeção do cálculo, que a
 *   obrigação passa a guardar, e é removida.
 *
 * As colunas de projeção continuam no schema (migração aditiva), sem escritor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $projected = DB::table('payments')
            ->where('value_source', 'official_curve')
            ->orderBy('id')
            ->get();

        foreach ($projected as $payment) {
            $hasInformedValue = $payment->expected_interest_value !== null
                || $payment->expected_amortization_value !== null
                || $payment->expected_premium_value !== null
                || $payment->expected_extra_amortization_value !== null
                || bccomp((string) $payment->premium_value, '0', 2) !== 0
                || bccomp((string) $payment->extra_amortization_value, '0', 2) !== 0;

            if (! $hasInformedValue) {
                DB::table('payments')->where('id', $payment->id)->delete();

                continue;
            }

            DB::table('payments')->where('id', $payment->id)->update([
                'interest_value' => $payment->expected_interest_value ?? '0.00',
                'amortization_value' => $payment->expected_amortization_value ?? '0.00',
                'premium_value' => $payment->expected_premium_value ?? $payment->premium_value,
                'extra_amortization_value' => $payment->expected_extra_amortization_value ?? $payment->extra_amortization_value,
                'value_source' => null,
                'expected_premium_value' => null,
                'expected_interest_value' => null,
                'expected_amortization_value' => null,
                'expected_extra_amortization_value' => null,
                'pu_curve_version_id' => null,
                'calculated_at' => null,
            ]);
        }
    }

    /**
     * Sem volta de dado: a projeção removida é derivável da curva oficial e o
     * cálculo esperado passa a viver nas obrigações. Nada a desfazer no schema.
     */
    public function down(): void
    {
        //
    }
};
