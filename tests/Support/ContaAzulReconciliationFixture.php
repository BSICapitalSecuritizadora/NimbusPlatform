<?php

namespace Tests\Support;

use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\User;
use App\Services\Funds\ContaAzulBillAttributionService;
use App\Services\Funds\ContaAzulBillIngestionService;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Illuminate\Support\Facades\DB;

/**
 * Shared setup for the Phase 3B reconciliation suites.
 *
 * Raw payload shapes mirror the Phase 3A staging fixtures; helpers live on
 * a class (not global functions) so suite files never collide.
 */
class ContaAzulReconciliationFixture
{
    public static function bill(array $overrides = []): array
    {
        return array_merge([
            'id' => 'bill-'.fake()->uuid(),
            'status' => 'PENDING',
            'status_traduzido' => null,
            'total' => 1200.00,
            'pago' => 0,
            'nao_pago' => 1200.00,
            'descricao' => 'DESPESA OPERACIONAL',
            'data_vencimento' => '2026-10-20',
            'data_competencia' => '2026-10-20',
            'data_criacao' => '2026-10-01T10:00:00.000000',
            'data_alteracao' => '2026-10-02T10:00:00.000000',
            'categorias' => [
                ['id' => 'cat-uuid-sistema', 'nome' => 'SISTEMA'],
            ],
            'centros_de_custo' => [],
            'fornecedor' => ['id' => null, 'nome' => null],
        ], $overrides);
    }

    public static function page(array $bills, ?int $total = null): array
    {
        return [
            'itens' => $bills,
            'itens_totais' => $total ?? count($bills),
        ];
    }

    public static function account(array $overrides = []): BankAccount
    {
        return BankAccount::factory()->create(array_merge([
            'conta_azul_account_id' => 'ca-acc-'.fake()->uuid(),
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public static function ingest(User $actor, BankAccount $account, array $pages, array $options = []): ContaAzulImportBatch
    {
        return app(ContaAzulBillIngestionService::class)->ingest($actor, $account, $pages, array_merge([
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ], $options));
    }

    public static function attributionService(): ContaAzulBillAttributionService
    {
        return app(ContaAzulBillAttributionService::class);
    }

    public static function recoveryService(): ContaAzulStaleBatchRecoveryService
    {
        return app(ContaAzulStaleBatchRecoveryService::class);
    }

    /**
     * Operational tables Phase 3B must never write to.
     *
     * @return array<string, int>
     */
    public static function operationalSnapshot(): array
    {
        $tables = [
            'expenses',
            'expense_histories',
            'funds',
            'fund_balance_histories',
            'bank_application_balance_snapshots',
            'fund_allocation_periods',
            'fund_allocation_revisions',
            'fund_allocation_lines',
            'guarantees',
            'guarantee_monthly_positions',
            'extracted_guarantees',
        ];

        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }
}
