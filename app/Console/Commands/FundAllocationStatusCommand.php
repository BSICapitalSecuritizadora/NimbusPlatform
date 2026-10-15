<?php

namespace App\Console\Commands;

use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Services\Funds\FundAllocationService;
use Illuminate\Console\Command;

/**
 * Read-only inspection of the monthly allocation domain (Phase 2A).
 *
 * Minimal technical interface to exercise and validate the new domain
 * without a UI: shows the current reference snapshot, the effective
 * approved revision and the open revision for one application and
 * competence. It never writes — no drafts, no approvals, no balance
 * changes — and it reads the allocation tables only, never funds.balance.
 */
class FundAllocationStatusCommand extends Command
{
    protected $signature = 'fund-allocations:status
                            {application : Bank application id}
                            {competence : Reporting competence (AAAA-MM)}
                            {--format=table : Output format: table or json}';

    protected $description = 'Shows (read-only) the reference balance, effective revision and open revision for an application and competence';

    public function handle(): int
    {
        $application = BankApplication::query()->find($this->argument('application'));

        if (! $application instanceof BankApplication) {
            $this->error('Aplicação bancária não encontrada.');

            return self::FAILURE;
        }

        try {
            $competence = FundAllocationService::normalizeCompetence((string) $this->argument('competence'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $snapshot = BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competence)
            ->orderByDesc('version')
            ->first();

        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competence)
            ->with(['effectiveRevision.snapshot', 'openRevision'])
            ->first();

        $effective = $period?->effectiveRevision;
        $open = $period?->openRevision;

        $payload = [
            'bank_application_id' => $application->getKey(),
            'codigo_produto' => $application->codigo_produto,
            'competence' => $competence,
            'reference' => $snapshot instanceof BankApplicationBalanceSnapshot ? [
                'snapshot_id' => $snapshot->getKey(),
                'version' => $snapshot->version,
                'amount' => (string) $snapshot->amount,
                'source' => $snapshot->source,
                'source_label' => $snapshot->sourceLabel(),
                'captured_at' => $snapshot->captured_at?->toDateTimeString(),
            ] : null,
            'effective_revision' => $effective !== null ? [
                'revision_id' => $effective->getKey(),
                'revision_number' => $effective->revision_number,
                'snapshot_id' => $effective->bank_application_balance_snapshot_id,
                'reference_amount' => (string) $effective->reference_amount,
                'total_amount' => (string) $effective->total_amount,
                'unallocated_amount' => (string) $effective->unallocated_amount,
                'line_count' => $effective->lines()->count(),
                'approved_at' => $effective->approved_at?->toDateTimeString(),
            ] : null,
            'open_revision' => $open !== null ? [
                'revision_id' => $open->getKey(),
                'revision_number' => $open->revision_number,
                'status' => $open->status,
                'snapshot_id' => $open->bank_application_balance_snapshot_id,
            ] : null,
        ];

        if ($this->option('format') === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Aplicação', "#{$payload['bank_application_id']} · {$payload['codigo_produto']}"],
                ['Competência', $payload['competence']],
                ['Referência vigente', $snapshot instanceof BankApplicationBalanceSnapshot
                    ? "v{$snapshot->version} · R$ ".number_format((float) $snapshot->amount, 2, ',', '.')." · {$snapshot->sourceLabel()}"
                    : 'nenhuma'],
                ['Revisão vigente', $effective !== null
                    ? "#{$effective->getKey()} (r{$effective->revision_number}) · total R$ ".number_format((float) $effective->total_amount, 2, ',', '.').' · não alocado R$ '.number_format((float) $effective->unallocated_amount, 2, ',', '.')
                    : 'nenhuma'],
                ['Revisão aberta', $open !== null ? "#{$open->getKey()} ({$open->statusLabel()})" : 'nenhuma'],
            ]
        );

        return self::SUCCESS;
    }
}
