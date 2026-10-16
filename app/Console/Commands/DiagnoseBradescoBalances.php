<?php

namespace App\Console\Commands;

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\DTOs\Bradesco\BradescoBalanceRow;
use App\DTOs\Bradesco\BradescoMatchReport;
use App\DTOs\Bradesco\BradescoStatementReport;
use App\Exceptions\Bradesco\BradescoException;
use App\Models\BankAccount;
use App\Services\Bradesco\BradescoAccountIdentity;
use App\Services\Bradesco\BradescoApplicationMatcher;
use App\Services\Bradesco\BradescoBalanceInterpreter;
use App\Services\Bradesco\BradescoClient;
use App\Services\Bradesco\BradescoStatementInspector;
use App\Services\Funds\ReadOnlyDiagnosticGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only Bradesco balance/statement diagnostic (Phase 4A).
 *
 * Fixture-first: given a physical account and a captured or synthetic
 * JSON response, it normalizes the payload, classifies every row,
 * matches application products against registered BankApplications
 * and reports completeness — without writing anything, anywhere.
 *
 * Live execution exists as a fenced path (--live plus --confirm-live
 * plus --source-note) and stays blocked until the bank documents the
 * authentication contract; the client enforces the remaining gates.
 * Output carries masked identifiers only: no credentials, no tokens,
 * no client names.
 */
class DiagnoseBradescoBalances extends Command
{
    protected $signature = 'bradesco:balances:diagnose
                            {--bank-account= : ID da conta física (BankAccount)}
                            {--fixture= : Caminho local do JSON de resposta (sintético ou capturado)}
                            {--format=table : Formato de saída: table ou json}
                            {--tipo-operacao= : Tipo de operação usado na captura (1 ou 2)}
                            {--live : Executa chamada real ao banco (exige ativação separada)}
                            {--confirm-live : Confirma a chamada real}
                            {--source-note= : Nota de autorização da chamada real}';

    protected $description = 'Diagnostica (somente leitura) uma resposta Bradesco normalizada contra a conta física e suas aplicações';

    public function handle(
        BradescoAccountIdentity $identity,
        BradescoBalanceInterpreter $interpreter,
        BradescoApplicationMatcher $matcher,
        BradescoStatementInspector $inspector,
        BradescoClient $client,
    ): int {
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Formato inválido: use table ou json.');

            return self::FAILURE;
        }

        $account = BankAccount::query()->find($this->option('bank-account'));

        if (! $account instanceof BankAccount) {
            $this->error('Informe uma conta física válida com --bank-account.');

            return self::FAILURE;
        }

        $tipoOperacao = $this->resolveTipoOperacao();

        if ($tipoOperacao === false) {
            $this->error('Tipo de operação inválido: use 1 ou 2.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('live')) {
                return $this->runLive($account, $client, $matcher, $tipoOperacao, $format);
            }

            return $this->runFixture($account, $identity, $interpreter, $matcher, $inspector, $tipoOperacao, $format);
        } catch (BradescoException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('O diagnóstico falhou antes de concluir: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function runLive(
        BankAccount $account,
        BradescoClient $client,
        BradescoApplicationMatcher $matcher,
        ?int $tipoOperacao,
        string $format,
    ): int {
        if (! (bool) $this->option('confirm-live')) {
            $this->error('Chamadas reais exigem --confirm-live além de --live.');

            return self::FAILURE;
        }

        $note = trim((string) ($this->option('source-note') ?? ''));

        if ($note === '') {
            $this->error('Chamadas reais exigem --source-note descrevendo a autorização separada.');

            return self::FAILURE;
        }

        $report = $client->fetchBalances($account, [
            'live' => true,
            'source_note' => $note,
            'tipo_operacao' => $tipoOperacao,
        ]);

        $match = $this->matchReadOnly($account, $matcher, $report);

        $this->renderBalance($account, $report, $match, 'live (chamada real autorizada)', $format);

        return self::SUCCESS;
    }

    private function runFixture(
        BankAccount $account,
        BradescoAccountIdentity $identity,
        BradescoBalanceInterpreter $interpreter,
        BradescoApplicationMatcher $matcher,
        BradescoStatementInspector $inspector,
        ?int $tipoOperacao,
        string $format,
    ): int {
        $path = trim((string) ($this->option('fixture') ?? ''));

        if ($path === '') {
            $this->error('Informe --fixture com o caminho do JSON, ou use --live com ativação separada.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Fixture ilegível: [{$path}].");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('Fixture fora do formato JSON objeto.');

            return self::FAILURE;
        }

        $source = "fixture [{$path}]";
        $now = Carbon::now('UTC')->toIso8601String();
        $isBalance = $this->isBalancePayload($decoded);
        $isStatement = $this->isStatementPayload($decoded);

        if ($isBalance && ! $isStatement) {
            $report = $interpreter->interpret($decoded, $account, $tipoOperacao, $now, $now);
            $match = $this->matchReadOnly($account, $matcher, $report);

            $this->renderBalance($account, $report, $match, $source, $format);

            return self::SUCCESS;
        }

        if ($isStatement && ! $isBalance) {
            $report = $inspector->inspect($decoded, $account, $now, $now);

            $this->renderStatement($account, $report, $source, $format);

            return self::SUCCESS;
        }

        $this->error('Fixture ambígua ou vazia: use chaves de saldo (saldoCC/saldoCP/saldoAplicacaoCC) ou de extrato (extrato*), sem misturar.');

        return self::FAILURE;
    }

    private function matchReadOnly(
        BankAccount $account,
        BradescoApplicationMatcher $matcher,
        BradescoBalanceReport $report,
    ): BradescoMatchReport {
        return ReadOnlyDiagnosticGuard::run(
            (string) DB::getDefaultConnection(),
            fn (): BradescoMatchReport => $matcher->match($account, $report)
        );
    }

    private function resolveTipoOperacao(): int|false|null
    {
        $value = $this->option('tipo-operacao');

        if ($value === null || $value === '') {
            return null;
        }

        if ($value === '1' || $value === 1) {
            return 1;
        }

        if ($value === '2' || $value === 2) {
            return 2;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isBalancePayload(array $payload): bool
    {
        foreach (['saldoCC', 'saldoCP', 'saldoAplicacaoCC'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isStatementPayload(array $payload): bool
    {
        foreach (['extratoUltimosLancamentos', 'extratoLancamentosFuturos', 'extratoPorPeriodo'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function renderBalance(
        BankAccount $account,
        BradescoBalanceReport $report,
        BradescoMatchReport $match,
        string $source,
        string $format,
    ): void {
        if ($format === 'json') {
            $this->line((string) json_encode(
                [
                    'source' => $source,
                    'manual' => config('bradesco.manual_version'),
                    'balance' => $report->toArray(),
                    'matching' => $match->toArray(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Diagnóstico Bradesco — SOMENTE LEITURA (sem escrita financeira)</>');
        $this->line("Conta física: {$report->maskedAccount} (id {$report->bankAccountId})");
        $this->line("Origem:       {$source}");
        $this->line('Manual:       '.config('bradesco.manual_version'));
        $this->line('Solicitada:   '.$report->requestedAt.' | Capturada: '.$report->capturedAt);
        $this->line('Tipo operação: '.($report->tipoOperacao ?? '(não informado)'));
        $this->newLine();

        $this->line('<options=bold>Seções</>');
        $sectionRows = [];

        foreach ($report->sections as $name => $meta) {
            $sectionRows[] = [
                $name,
                $meta['codigo_retorno'] ?? '(ausente)',
                $meta['mensagem'] ?? '—',
                $meta['quantidade_declarada'] ?? '(ausente)',
                $meta['quantidade_retornada'] ?? '—',
            ];
        }

        $this->table(['Seção', 'Retorno', 'Mensagem', 'Declarados', 'Retornados'], $sectionRows);

        $this->line('<options=bold>Linhas classificadas (evidência, sem consolidação)</>');
        $this->table(
            ['Seção', 'Produto', 'Nome resumido', 'Classificação', 'Valor', 'Sinal', 'Aplicação?', 'Flags'],
            array_map(fn (BradescoBalanceRow $row): array => [
                $row->section,
                $row->codigoProduto ?? '(ausente)',
                $row->nomeProdutoResumido ?? '—',
                $row->identificadorSaldo !== null
                    ? "[{$row->identificadorSaldo}] {$row->identificadorMeaning}"
                    : '(ausente)',
                $row->valorRaw ?? '(ausente)',
                $row->sinalSaldo ?? '(ausente)',
                $row->isApplicationProduct ? 'sim' : 'não',
                $row->flags === [] ? '—' : implode(', ', $row->flags),
            ], $report->rows),
        );

        $this->line('<options=bold>Produtos de aplicação por código (agrupados, NÃO somados)</>');
        $groupRows = [];

        foreach ($report->groupedByProduct as $product => $rows) {
            $classifications = [];

            foreach ($rows as $row) {
                $classifications[] = $row->identificadorSaldo ?? '(ausente)';
            }

            $groupRows[] = [
                $product,
                $rows[0]->nomeProdutoResumido ?? '—',
                count($rows),
                implode(', ', array_unique($classifications)),
            ];
        }

        if ($groupRows === []) {
            $this->info('Nenhum produto de aplicação retornado.');
        } else {
            $this->table(['Produto', 'Nome resumido', 'Linhas', 'Classificações'], $groupRows);
        }

        $this->renderMatch($match);
        $this->renderCompleteness($report->complete, $report->completenessIssues, $report->warnings);

        $this->warn('Mapeamento financeiro: '.mb_strtoupper($report->financialMapping).' — '.$report->financialMappingReason);
    }

    private function renderMatch(BradescoMatchReport $match): void
    {
        $this->newLine();
        $this->line('<options=bold>Pareamento com aplicações cadastradas (somente leitura)</>');

        $this->table(
            ['Situação', 'Produto', 'Aplicação id', 'Detalhe'],
            array_merge(
                array_map(fn (array $row): array => [
                    'reconhecida', $row['codigo_produto'], $row['bank_application_id'],
                    'linhas: '.$row['row_count'].' | class: '.implode(',', $row['classifications']),
                ], $match->recognized),
                array_map(fn (array $row): array => [
                    'variante de formato', $row['returned_codigo'].' (cadastrado: '.$row['registered_codigo'].')',
                    $row['bank_application_id'], $row['reason'],
                ], $match->formatVariants),
                array_map(fn (array $row): array => [
                    'não cadastrada', $row['codigo_produto'] ?? '(ausente)', '—',
                    ($row['nome_produto'] ?? '—').' | linhas: '.$row['row_count'],
                ], $match->unregistered),
                array_map(fn (array $row): array => [
                    'ausente na resposta', $row['codigo_produto'], $row['bank_application_id'],
                    $row['nome_produto'] ?? '—',
                ], $match->absentFromResponse),
                array_map(fn (array $row): array => [
                    'ambígua', $row['codigo_produto'] ?? '(ausente)',
                    implode(',', $row['bank_application_ids']), $row['reason'],
                ], $match->ambiguous),
                array_map(fn (array $row): array => [
                    'sem identificador', '(ausente)', '—',
                    ($row['nome_produto'] ?? '—').' | flags: '.implode(',', $row['flags']),
                ], $match->missingIdentifiers),
            )
        );

        if (
            $match->recognized === [] && $match->formatVariants === [] && $match->unregistered === []
            && $match->absentFromResponse === [] && $match->ambiguous === [] && $match->missingIdentifiers === []
        ) {
            $this->info('Nada a parear: sem produtos retornados e sem aplicações cadastradas.');
        }
    }

    /**
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private function renderCompleteness(bool $complete, array $issues, array $warnings): void
    {
        $this->newLine();
        $this->line('<options=bold>Integridade da resposta</>');
        $this->line($complete ? 'Completa: sim' : 'Completa: NÃO');

        foreach ($issues as $issue) {
            $this->error('  • '.$issue);
        }

        foreach ($warnings as $warning) {
            $this->warn('  • '.$warning);
        }
    }

    private function renderStatement(
        BankAccount $account,
        BradescoStatementReport $report,
        string $source,
        string $format,
    ): void {
        if ($format === 'json') {
            $this->line((string) json_encode(
                [
                    'source' => $source,
                    'manual' => config('bradesco.manual_version'),
                    'statement' => $report->toArray(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Diagnóstico Bradesco (extrato) — SOMENTE LEITURA</>');
        $this->line("Conta física: {$report->maskedAccount} (id {$report->bankAccountId})");
        $this->line("Origem:       {$source}");
        $this->line('Solicitada:   '.$report->requestedAt.' | Capturada: '.$report->capturedAt);
        $this->line('Movimentos:   '.$report->totalMovements);
        $this->line('Janela:       '.($report->windowStart ?? '—').' … '.($report->windowEnd ?? '—'));
        $this->newLine();

        $rows = [];

        foreach ($report->sections as $name => $meta) {
            $rows[] = [
                $name,
                $meta['codigo_retorno'] ?? '(ausente)',
                $meta['quantidade_declarada'] ?? '(ausente)',
                $meta['movimentos_retornados'] ?? '—',
            ];
        }

        $this->table(['Seção', 'Retorno', 'Declarados', 'Retornados'], $rows);
        $this->renderCompleteness($report->complete, $report->completenessIssues, $report->warnings);
    }
}
