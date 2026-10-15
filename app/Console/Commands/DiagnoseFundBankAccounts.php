<?php

namespace App\Console\Commands;

use App\DTOs\Funds\FundBankAccountDiagnosticReport;
use App\Services\Funds\FinancialIntegrityBaseline;
use App\Services\Funds\FundBankAccountDiagnosticService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Diagnóstico somente-leitura do mapeamento fundos → contas bancárias globais.
 *
 * Fase 1B.1: agrupa os fundos legados pela identidade canônica estrita da
 * conta (banco + agência + conta, com zeros à esquerda preservados), aponta
 * conflitos de Conta Azul e candidatos de merge por zeros à esquerda, e gera
 * uma prévia do que a futura migração tentaria fazer — sem inserir, atualizar
 * ou apagar nada. Não existe opção `--execute` nesta fase.
 *
 * O alvo é sempre explícito e confirmado pelo operador (`--connection` mais
 * `--confirm-connection` repetindo o nome), e a origem dos dados (`--source`)
 * sai impressa em destaque para que ninguém confunda um diagnóstico sintético
 * com uma leitura da base real.
 */
class DiagnoseFundBankAccounts extends Command
{
    protected $signature = 'funds:bank-accounts:diagnose
                            {--connection= : Conexão de banco a diagnosticar (obrigatória; nunca usa o padrão silenciosamente)}
                            {--confirm-connection= : Repete o nome da conexão para confirmar o alvo}
                            {--source= : Origem dos dados: synthetic, staging ou authorized (obrigatória)}
                            {--source-note= : Nota de autorização (obrigatória quando --source=authorized)}
                            {--chunk=500 : Fundos lidos por chunk}
                            {--limit= : Número máximo de fundos a processar}
                            {--conflicts : Exibe o relatório detalhado de conflitos}
                            {--preview : Exibe a prévia seca do mapeamento, fundo a fundo}
                            {--baseline : Inclui o retrato de integridade financeira (somente leitura)}
                            {--format=table : Formato de saída: table, json ou csv}
                            {--output= : Grava a saída estruturada (json/csv) neste caminho local}';

    protected $description = 'Diagnostica (somente leitura) o mapeamento dos fundos legados para contas bancárias globais, com prévia seca da migração futura';

    /**
     * @var list<string>
     */
    private const SOURCES = ['synthetic', 'staging', 'authorized'];

    /**
     * @var list<string>
     */
    private const FORMATS = ['table', 'json', 'csv'];

    public function handle(
        FundBankAccountDiagnosticService $diagnostics,
        FinancialIntegrityBaseline $baseline,
    ): int {
        $connection = (string) $this->option('connection');

        if ($connection === '' || Config::get("database.connections.{$connection}") === null) {
            $this->error('Informe uma conexão explícita com --connection (nenhuma conexão padrão é usada silenciosamente).');

            return self::FAILURE;
        }

        if ((string) $this->option('confirm-connection') !== $connection) {
            $this->error('Confirme o alvo repetindo a conexão em --confirm-connection com o mesmo valor.');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');

        if (! in_array($source, self::SOURCES, true)) {
            $this->error('Informe a origem dos dados com --source: '.implode(', ', self::SOURCES).'.');

            return self::FAILURE;
        }

        $sourceNote = (string) ($this->option('source-note') ?? '');

        if ($source === 'authorized' && $sourceNote === '') {
            $this->error('Origem authorized exige --source-note descrevendo a autorização.');

            return self::FAILURE;
        }

        $format = (string) $this->option('format');

        if (! in_array($format, self::FORMATS, true)) {
            $this->error('Formato inválido: use '.implode(', ', self::FORMATS).'.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($chunk === false) {
            $this->error('O chunk deve ser um inteiro maior ou igual a 1.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $limit = null;

        if ($limitOption !== null && $limitOption !== '') {
            $limit = filter_var($limitOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($limit === false) {
                $this->error('O limite deve ser um inteiro maior ou igual a 1.');

                return self::FAILURE;
            }
        }

        $output = (string) ($this->option('output') ?? '');

        if ($output !== '' && $format === 'table') {
            $this->error('A opção --output exige --format=json ou --format=csv.');

            return self::FAILURE;
        }

        try {
            $report = $diagnostics->diagnose(
                $connection,
                $chunk,
                $limit,
                $source,
                $sourceNote === '' ? null : $sourceNote
            );

            $snapshot = $this->option('baseline')
                ? $baseline->snapshot($connection)
                : null;
        } catch (Throwable $exception) {
            $this->error('O diagnóstico falhou antes de concluir: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($format === 'json') {
            $payload = $report->toArray();

            if ($snapshot !== null) {
                $payload['baseline'] = $snapshot->toArray(withRowDigests: true);
            }

            $encoded = (string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return $this->emitStructured($output, $encoded);
        }

        if ($format === 'csv') {
            return $this->emitStructured($output, $this->previewCsv($report));
        }

        $this->renderHeader($report);
        $this->renderTotals($report);

        if ($this->option('conflicts')) {
            $this->renderConflicts($report);
        }

        if ($this->option('preview')) {
            $this->renderPreview($report);
        }

        if ($snapshot !== null) {
            $this->renderBaseline($snapshot->toArray());
        }

        if (! $this->option('conflicts') && ! $this->option('preview')) {
            $this->comment('Use --conflicts e --preview para o detalhamento; --format=json|csv para saída estruturada.');
        }

        return self::SUCCESS;
    }

    private function emitStructured(string $output, string $content): int
    {
        if ($output === '') {
            $this->line($content);

            return self::SUCCESS;
        }

        $written = @file_put_contents($output, $content);

        if ($written === false) {
            $this->error("Não foi possível gravar o artefato de diagnóstico em [{$output}].");

            return self::FAILURE;
        }

        $this->info("Artefato de diagnóstico gravado em [{$output}] ({$written} bytes).");

        return self::SUCCESS;
    }

    private function renderHeader(FundBankAccountDiagnosticReport $report): void
    {
        $this->newLine();
        $this->line('<options=bold>Diagnóstico de contas bancárias — SOMENTE LEITURA</>');
        $this->line(sprintf('Conexão:      %s (driver %s, base %s)', $report->connection, $report->driver, $report->database));
        $this->line(sprintf(
            'Origem:       %s%s',
            $report->source,
            $report->sourceNote !== null ? " ({$report->sourceNote})" : ''
        ));
        $this->line(sprintf('Chunk:        %d%s', $report->chunkSize, $report->limit !== null ? " | limite {$report->limit}" : ''));

        if ($report->truncated) {
            $this->warn('O limite cortou a leitura: existem fundos além dos analisados.');
        }

        $this->newLine();
    }

    private function renderTotals(FundBankAccountDiagnosticReport $report): void
    {
        $labels = [
            'total_funds' => 'Fundos analisados',
            'parseable_funds' => 'Com identificadores parseáveis',
            'missing_identifier_funds' => 'Com identificadores ausentes',
            'malformed_identifier_funds' => 'Com identificadores malformados',
            'distinct_strict_accounts' => 'Contas canônicas estritas distintas',
            'multi_fund_accounts' => 'Contas referenciadas por vários fundos',
            'multi_emission_accounts' => 'Contas referenciadas por várias emissões',
            'conta_azul_conflict_groups' => 'Grupos com Conta Azul conflitante',
            'zero_padding_candidate_groups' => 'Grupos candidatos a merge de zeros',
            'zero_padding_collapsible_groups' => 'Grupos que colapsariam no modo numérico',
            'review_required_funds' => 'Fundos exigindo revisão humana',
            'unmappable_funds' => 'Fundos não mapeáveis com segurança',
            'already_linked_funds' => 'Fundos já vinculados a uma conta',
            'existing_match_funds' => 'Fundos com conta existente correspondente',
            'spelling_variant_groups' => 'Grupos com grafias variantes (informativo)',
            'existing_mismatch_groups' => 'Grupos divergentes de conta existente',
            'status_account_identified' => 'Status ACCOUNT_IDENTIFIED',
            'status_application_pending' => 'Status APPLICATION_PENDING',
            'status_mapping_conflict' => 'Status MAPPING_CONFLICT',
            'status_blocked_invalid_account' => 'Status BLOCKED_INVALID_ACCOUNT',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            $rows[] = [$label, $report->totals[$key] ?? 0, $key];
        }

        $this->table(['Total', 'Valor', 'Chave'], $rows);
    }

    private function renderConflicts(FundBankAccountDiagnosticReport $report): void
    {
        $conflicts = $report->conflicts;

        $this->newLine();
        $this->line('<options=bold>Conflitos de Conta Azul (nenhum vencedor é escolhido)</>');

        if ($conflicts['conta_azul'] === []) {
            $this->info('Nenhum grupo com Conta Azul conflitante.');
        } else {
            $this->table(
                ['Grupo canônico (mascarado)', 'Fundos', 'Emissões', 'Conta Azul (mascarados)'],
                array_map(fn (array $row): array => [
                    $row['canonical_group'],
                    implode(', ', $row['fund_ids']),
                    implode(', ', $row['emission_ids']),
                    implode(', ', $row['masked_conta_azul_ids']),
                ], $conflicts['conta_azul']),
            );
        }

        $this->newLine();
        $this->line('<options=bold>Candidatos a merge por zeros à esquerda (estrito vence; nada é colapsado)</>');

        if ($conflicts['numeric_candidates'] === []) {
            $this->info('Nenhum candidato a merge numérico.');
        } else {
            $this->table(
                ['Grupo numérico (mascarado)', 'Grupos estritos (mascarados)', 'Fundos'],
                array_map(fn (array $row): array => [
                    $row['numeric_group'],
                    implode("\n", $row['strict_groups']),
                    implode(', ', $row['fund_ids']),
                ], $conflicts['numeric_candidates']),
            );
        }

        $this->newLine();
        $this->line('<options=bold>Fundos bloqueados (identificador ausente ou malformado)</>');

        if ($conflicts['blocked'] === []) {
            $this->info('Nenhum fundo bloqueado.');
        } else {
            $this->table(
                ['Fundo', 'Emissão', 'Motivo', 'Agência (mascarada)', 'Conta (mascarada)'],
                array_map(fn (array $row): array => [
                    $row['fund_id'],
                    $row['emission_id'],
                    $row['reason'],
                    $row['masked_agency'],
                    $row['masked_account'],
                ], $conflicts['blocked']),
            );
        }

        $this->newLine();
        $this->line('<options=bold>Divergências contra contas já cadastradas</>');

        if ($conflicts['existing_mismatches'] === []) {
            $this->info('Nenhuma divergência contra conta existente.');
        } else {
            $this->table(
                ['Grupo canônico (mascarado)', 'Conta existente', 'Conta Azul existente', 'Conta Azul do grupo', 'Fundos'],
                array_map(fn (array $row): array => [
                    $row['canonical_group'],
                    $row['existing_bank_account_id'],
                    $row['masked_existing_conta_azul_id'],
                    implode(', ', $row['masked_group_conta_azul_ids']),
                    implode(', ', $row['fund_ids']),
                ], $conflicts['existing_mismatches']),
            );
        }

        $this->newLine();
        $this->line('<options=bold>Grafias variantes do mesmo grupo (informativo)</>');

        if ($conflicts['spelling_variants'] === []) {
            $this->info('Nenhum grupo com grafias variantes.');
        } else {
            $this->table(
                ['Grupo canônico (mascarado)', 'Fundos', 'Variações'],
                array_map(fn (array $row): array => [
                    $row['canonical_group'],
                    implode(', ', $row['fund_ids']),
                    $row['variant_count'],
                ], $conflicts['spelling_variants']),
            );
        }
    }

    private function renderPreview(FundBankAccountDiagnosticReport $report): void
    {
        $this->newLine();
        $this->line('<options=bold>Prévia seca do mapeamento (nada foi persistido)</>');

        if ($report->preview === []) {
            $this->info('Nenhum fundo encontrado nesta base.');

            return;
        }

        $this->table(
            ['Fundo', 'Emissão', 'Status', 'Motivos', 'Agência', 'Conta', 'Fundos', 'Emissões', 'Conta Azul', 'Conta exist.', 'Elegível'],
            array_map(fn (array $row): array => [
                $row['fund_id'],
                $row['emission_id'],
                $row['status'],
                $row['reasons'] === [] ? '—' : implode(', ', $row['reasons']),
                $row['masked_agency'],
                $row['masked_account'],
                $row['group_fund_count'],
                $row['group_emission_count'],
                $row['conta_azul_status'],
                $row['existing_bank_account_id'] ?? '—',
                $row['eligible_for_auto_mapping'] ? 'sim' : 'não',
            ], $report->preview),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function renderBaseline(array $snapshot): void
    {
        $this->newLine();
        $this->line('<options=bold>Retrato de integridade financeira (SHA-256 por linha, ordenado por id)</>');

        $rows = [];

        foreach ($snapshot['tables'] as $name => $table) {
            $rows[] = [$name, $table['row_count'], $table['digest']];
        }

        $this->table(['Tabela', 'Linhas', 'Digest'], $rows);
    }

    private function previewCsv(FundBankAccountDiagnosticReport $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'fund_id',
            'emission_id',
            'bank_id',
            'status',
            'reasons',
            'masked_agency',
            'masked_account',
            'canonical_group',
            'group_fund_count',
            'group_emission_count',
            'shared_by_funds',
            'shared_by_emissions',
            'conta_azul_status',
            'existing_bank_account_id',
            'linked_bank_account_id',
            'application_resolved',
            'eligible_for_auto_mapping',
            'must_skip',
        ]);

        foreach ($report->preview as $row) {
            fputcsv($handle, [
                $row['fund_id'],
                $row['emission_id'],
                $row['bank_id'],
                $row['status'],
                implode('|', $row['reasons']),
                $row['masked_agency'],
                $row['masked_account'],
                $row['canonical_group'],
                $row['group_fund_count'],
                $row['group_emission_count'],
                $row['shared_by_funds'] ? '1' : '0',
                $row['shared_by_emissions'] ? '1' : '0',
                $row['conta_azul_status'],
                $row['existing_bank_account_id'],
                $row['linked_bank_account_id'],
                $row['application_resolved'] ? '1' : '0',
                $row['eligible_for_auto_mapping'] ? '1' : '0',
                $row['must_skip'] ? '1' : '0',
            ]);
        }

        rewind($handle);

        $csv = stream_get_contents($handle);

        fclose($handle);

        return (string) $csv;
    }
}
