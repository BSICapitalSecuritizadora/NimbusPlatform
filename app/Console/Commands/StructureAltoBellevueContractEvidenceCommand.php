<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegalInstruments\AltoBellevueContractEvidenceBackfill;
use Illuminate\Console\Command;
use Throwable;

class StructureAltoBellevueContractEvidenceCommand extends Command
{
    /** @var string */
    protected $signature = 'legal-instruments:structure-alto-bellevue-baseline
                            {--write : Persiste somente propostas pending_review após conferir o dry-run}';

    /** @var string */
    protected $description = 'Estrutura as evidências contratuais do baseline do CRI Alto Bellevue (dry-run por padrão)';

    public function handle(AltoBellevueContractEvidenceBackfill $backfill): int
    {
        $write = (bool) $this->option('write');

        try {
            $report = $backfill->execute($write);

            $this->components->info($write
                ? 'Backfill executado: todas as novas evidências permanecem pending_review.'
                : 'Dry-run: nenhum instrumento, vínculo documental ou campo foi alterado.');

            $this->table(
                ['field_key', 'valor canônico', 'documento', 'página', 'cláusula', 'status proposto', 'ação'],
                collect($report['rows'])->map(fn (array $row): array => [
                    $row['field_key'],
                    $this->displayValue($row['canonical_value']),
                    sprintf('#%d %s', $row['document_id'], $row['document']),
                    $row['page'],
                    $row['clause'],
                    $row['proposed_status'],
                    $row['action'],
                ])->all(),
            );

            if ($report['infrastructure_actions'] !== []) {
                $this->newLine();
                $this->line('<comment>Ações de infraestrutura do dossiê:</comment> '.implode(', ', $report['infrastructure_actions']));
            }

            if ($report['manual_review_required'] !== []) {
                $this->newLine();
                $this->components->warn('Itens não gravados automaticamente: manual_review_required.');
                $this->table(
                    ['field_key', 'valor', 'documento', 'registro', 'motivo'],
                    collect($report['manual_review_required'])->map(fn (array $row): array => [
                        $row['field_key'],
                        $this->displayValue($row['canonical_value']),
                        $row['document'] ?? '—',
                        $row['existing_field_id'] ?? '—',
                        $row['reason'] ?? 'Existe proposta pendente divergente ou evidência equivalente rejeitada.',
                    ])->all(),
                );
            }

            $this->newLine();
            $this->line(sprintf(
                '<info>Resumo:</info> %d insert(s) pending_review; %d registro(s) preservado(s); %d revisão(ões) manual(is).',
                $report['inserts'],
                $report['preserved'],
                count($report['manual_review_required']),
            ));

            if (! $write) {
                $this->components->warn('Revise a tabela antes de executar novamente com --write.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
