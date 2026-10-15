<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\Guarantee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dependency-impact report for a future clean-slate fund re-registration.
 *
 * Strictly read-only: it counts fund registrations and everything that
 * references them so the operator can judge what an explicitly authorized
 * cleanup would affect. It never deletes, updates or inserts anything, and
 * it prints no bank identifiers — counts only.
 *
 * Verified foreign-key consequences (see migrations):
 * - fund_balance_histories.fund_id cascades: deleting a fund deletes its
 *   balance histories.
 * - guarantees.fund_id nulls on delete: deleting a fund keeps the guarantee
 *   but clears its fund link (valuation inputs change).
 * - Imported expenses are NOT fund-owned: Expense rows match on
 *   (emission_id, category) and ExpenseHistory rows on conta_azul_bill_id,
 *   so they survive a fund cleanup — but the Conta Azul sync only resumes
 *   after the recreated funds carry their fund-level conta_azul_account_id
 *   again, entered manually per fund.
 */
class FundRegistrationImpactReport extends Command
{
    protected $signature = 'funds:registrations:impact
                            {--connection= : Database connection to inspect (defaults to the app connection)}
                            {--format=table : Output format: table or json}';

    protected $description = 'Reports (read-only) what depends on the current fund registrations: histories, guarantees, expenses and financial readers';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Formato inválido: use table ou json.');

            return self::FAILURE;
        }

        $connection = $this->option('connection');

        if (is_string($connection) && $connection !== '') {
            if (config("database.connections.{$connection}") === null) {
                $this->error("Conexão desconhecida [{$connection}].");

                return self::FAILURE;
            }

            DB::setDefaultConnection($connection);
        }

        $report = $this->buildReport();

        if ($format === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReport(): array
    {
        return [
            'connection' => DB::getDefaultConnection(),
            'funds' => [
                'total' => Fund::query()->count(),
                'legacy_unlinked' => Fund::query()->whereNull('bank_account_id')->whereNull('bank_application_id')->count(),
                'linked_to_account' => Fund::query()->whereNotNull('bank_account_id')->count(),
                'linked_to_application' => Fund::query()->whereNotNull('bank_application_id')->count(),
                'distinct_emissions' => Fund::query()->distinct()->count('emission_id'),
                'with_conta_azul_id' => Fund::query()->whereNotNull('conta_azul_account_id')->count(),
            ],
            'bank_registry' => [
                'bank_accounts' => BankAccount::query()->count(),
                'bank_applications' => BankApplication::query()->count(),
                'bank_accounts_with_conta_azul_id' => BankAccount::query()->whereNotNull('conta_azul_account_id')->count(),
            ],
            'balance_histories' => [
                'total' => FundBalanceHistory::query()->count(),
                'distinct_funds' => FundBalanceHistory::query()->distinct()->count('fund_id'),
                'on_fund_delete' => 'cascade — deleting a fund deletes its histories',
            ],
            'guarantees' => [
                'linked_to_funds' => Guarantee::query()->whereNotNull('fund_id')->count(),
                'distinct_funds_referenced' => Guarantee::query()->whereNotNull('fund_id')->distinct()->count('fund_id'),
                'on_fund_delete' => 'null — deleting a fund keeps the guarantee but clears its fund link',
            ],
            'expenses' => [
                'expenses_with_conta_azul_bill' => Expense::query()->whereNotNull('conta_azul_bill_id')->count(),
                'expense_histories_with_conta_azul_bill' => ExpenseHistory::query()->whereNotNull('conta_azul_bill_id')->count(),
                'note' => 'Imported expenses key on (emission_id, category) and bill id, not on fund id: they survive a fund cleanup, but the sync only resumes after recreated funds carry their fund-level conta_azul_account_id again.',
            ],
            'financial_readers' => [
                'App\\Jobs\\SyncContaAzulExpensesJob (fund-level conta_azul_account_id → bills → expenses)',
                'App\\Actions\\Funds\\SnapshotMonthlyFundBalancesAction (monthly balance snapshots)',
                'App\\Actions\\FundAlerts\\SendFundMinimumBalanceAlertAction + SendFundMinimumBalanceAlertsAction (minimum-balance alerts)',
                'App\\Services\\Guarantees\\GuaranteeValueResolver (fund balances feed guarantee valuation)',
                'App\\Services\\Guarantees\\GuaranteeConsolidationPlanner (fund lookup for consolidation)',
                'App\\Services\\Guarantees\\GuaranteeSuggestionReviewService (links guarantees to funds)',
                'App\\Services\\Funds\\FinancialIntegrityBaseline (funds tables integrity snapshot)',
                'App\\Services\\Funds\\FundBankAccountDiagnosticService (legacy fund → account diagnostics)',
                'App\\Filament\\Widgets\\Dashboard\\ExecutiveIndicatorsWidget (fund counters)',
                'Emission guarantees relation manager (guarantee → fund display)',
            ],
            'warning' => 'Read-only report. No cleanup is executed: clearing and recreating fund registrations requires a separate, explicitly authorized operation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function render(array $report): void
    {
        $this->newLine();
        $this->line('<options=bold>Impacto de uma futura recriação dos fundos — SOMENTE LEITURA</>');
        $this->line(sprintf('Conexão: %s', $report['connection']));
        $this->newLine();

        $this->table(['Registros', 'Total'], [
            ['Fundos cadastrados', $report['funds']['total']],
            ['Fundos legados (sem vínculo novo)', $report['funds']['legacy_unlinked']],
            ['Fundos vinculados a uma conta', $report['funds']['linked_to_account']],
            ['Fundos vinculados a uma aplicação', $report['funds']['linked_to_application']],
            ['Operações com fundos', $report['funds']['distinct_emissions']],
            ['Fundos com ID Conta Azul operacional', $report['funds']['with_conta_azul_id']],
            ['Contas bancárias globais', $report['bank_registry']['bank_accounts']],
            ['Aplicações bancárias', $report['bank_registry']['bank_applications']],
            ['Contas com ID Conta Azul conceitual', $report['bank_registry']['bank_accounts_with_conta_azul_id']],
        ]);

        $this->table(['Dependência', 'Total', 'Efeito de excluir fundos'], [
            ['Históricos de saldo', $report['balance_histories']['total'], $report['balance_histories']['on_fund_delete']],
            ['Garantias vinculadas a fundos', $report['guarantees']['linked_to_funds'], $report['guarantees']['on_fund_delete']],
            ['Despesas importadas (Conta Azul)', $report['expenses']['expenses_with_conta_azul_bill'], 'sobrevivem; sync retoma após recadastrar os IDs por fundo'],
            ['Históricos de despesa importados', $report['expenses']['expense_histories_with_conta_azul_bill'], 'sobrevivem (chaveados por bill id)'],
        ]);

        $this->newLine();
        $this->line('<options=bold>Leitores financeiros de Fundos</>');

        foreach ($report['financial_readers'] as $reader) {
            $this->line("  • {$reader}");
        }

        $this->newLine();
        $this->warn($report['warning']);
    }
}
