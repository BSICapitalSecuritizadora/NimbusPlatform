<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registra o calendário financeiro consolidado `BR_FINANCIAL_MARKET` no catálogo.
 *
 * NÃO altera schema: é uma migration de DADOS, no mesmo padrão de
 * `register_national_legal_calendar`. A proveniência de múltiplas fontes já é suportada pelo schema
 * atual — a unique de `business_holidays` é `(calendar_code, holiday_date, source)`, de modo que a
 * mesma data sustentada por ANBIMA e FEBRABAN ocupa duas linhas de EVIDÊNCIA sem virar dois feriados.
 *
 * O calendário nasce deliberadamente inerte:
 *
 * - `materialization_policy = explicit_official_decisions` faz `allowsImplicitWeekdayDecision()`
 *   retornar false, de modo que qualquer tentativa de calcular sobre uma data sem linha persistida
 *   levanta exceção em vez de assumir que segunda a sexta é dia útil. Ausência de cobertura não pode
 *   se passar por confirmação;
 * - `financial_use_allowed = false` e `available_for_new_configurations = false` mantêm o código fora
 *   das opções de novas configurações de PU até a homologação;
 * - `accepts_anbima = false` impede que o importador ANBIMA escreva aqui, o que transformaria o
 *   calendário num alias silencioso de `BR_BANKING_ANBIMA`.
 *
 * Nenhum consumidor é migrado e nenhuma emissão muda de calendário.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('business_calendars')->updateOrInsert(
            ['code' => 'BR_FINANCIAL_MARKET'],
            [
                'name' => 'Mercado financeiro brasileiro — calendário consolidado',
                'purpose' => 'Dias úteis aplicáveis às operações do mercado financeiro brasileiro. As decisões são '
                    .'materializadas a partir de fontes financeiras reconhecidas (ANBIMA e FEBRABAN) mediante '
                    .'reconciliação auditável, e não a partir de uma única publicação. Não inclui automaticamente '
                    .'feriados estaduais ou municipais, nem expediente especial de agência (quarta-feira de cinzas, '
                    .'último dia útil do ano).',
                'calendar_type' => 'financial_market',
                'source' => 'Reconciliação ANBIMA × FEBRABAN — Resolução CMN 4.880, de 23.12.2020',
                'status' => 'review_required',
                'import_mode' => 'source_reconciliation',
                'materialization_policy' => 'explicit_official_decisions',
                'is_official' => true,
                'financial_use_allowed' => false,
                'is_legacy' => false,
                'is_homologation' => false,
                'accepts_anbima' => false,
                'available_for_new_configurations' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('business_calendars')->where('code', 'BR_FINANCIAL_MARKET')->delete();
    }
};
