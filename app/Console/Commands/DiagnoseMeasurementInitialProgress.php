<?php

namespace App\Console\Commands;

use App\Enums\MeasurementInitialProgressClassification as Classification;
use App\Services\MeasurementInitialProgressDiagnosticService as Diagnostic;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Relatório durável, somente leitura, do avanço físico inicial que a migração
 * 2026_10_05_170828 copia do "Realiz. inicial" da 1ª linha do cronograma para o
 * plano de medição -- só quando o histórico aprovado prova que ele estava em
 * vigor; nos demais casos o plano fica em 0,00% e a decisão é do dono.
 *
 * A fase vem do esquema. Antes da migração (sem as colunas novas), a tabela
 * prevê a decisão que ela vai gravar em cada plano. Depois, mostra a decisão
 * gravada na trilha (`measurements`, initial_physical_progress_*) e confere o
 * avanço inicial do plano com ela: divergência de integridade -- decisão
 * ausente, duplicada ou incoerente, avanço inicial diferente do registrado --
 * sai com código de falha. Nas duas fases também sai com falha o plano em que
 * a migração vai quebrar: aprovação com avanço gravado como lista, que ela não
 * converte em texto -- o `migrate` do startup pararia nele. Planos que pedem
 * decisão do dono só geram aviso.
 *
 * O deploy depende deste relatório? Tecnicamente não: nada no CI nem no
 * startup o chama. Mas a previsão "antes" só existe até o startup do próximo
 * deploy de main -- push verde de QUALQUER sessão, nova execução de um run
 * reprovado ou workflow_dispatch --, porque o startup roda o `migrate --force`
 * logo no início (só o `config:clear` vem antes), e este relatório nunca roda
 * lá antes da migração. Se o dono quer conferir os planos antes, gere agora o
 * "antes" de fora e combine com as outras sessões não publicar em main até lá.
 * Depois resta só a conferência "depois", e a cópia (ou o 0,00%) fica imutável
 * até existir o fluxo de correção do avanço inicial (Fase 2).
 *
 * O "antes" de produção se gera de uma máquina com o código desta versão e
 * acesso de leitura ao banco de produção, de preferência com um usuário que só
 * tenha SELECT. Com configuração em cache (bootstrap/cache/config.php, que um
 * `optimize` grava), o Laravel ignora as variáveis DB_* do shell e lê, sem
 * aviso, o banco do cache: o APP_CONFIG_CACHE apontando para um arquivo que não
 * existe obriga a configuração a sair delas. DB_URL e DB_SOCKET vazios impedem
 * que um `.env` local troque o destino.
 *
 *   APP_CONFIG_CACHE=/caminho/inexistente.php DB_URL= DB_SOCKET= \
 *   DB_CONNECTION=mysql DB_HOST=... DB_PORT=3306 DB_DATABASE=... \
 *   DB_USERNAME=<somente leitura> DB_PASSWORD=... MYSQL_ATTR_SSL_CA=<ca.pem> \
 *     php artisan measurements:initial-progress-report --all --json > avanco-inicial-antes.json
 *
 * Confira na saída que o banco lido é o de produção (`connection` no JSON,
 * "Banco lido" no texto). Nessa máquina nunca rode `migrate`. Guarde a saída:
 * ela é a previsão que o dono confere antes de o avanço inicial copiado virar
 * imutável.
 *
 * Depois do deploy, no servidor da aplicação:
 *
 *   php artisan measurements:initial-progress-report
 *
 * Depois da migração, código de saída diferente de zero aponta divergência
 * entre a trilha e o plano (ou um filtro de operação inválido); com --json, as
 * classificações se comparam uma a uma com as da saída de antes.
 *
 * A conferência "depois" supõe que nada muda o avanço inicial que a migração
 * deixou. Quando existir o fluxo de correção dele (Fase 2), ela precisa aceitar
 * o avanço sustentado pela activity de correção posterior no log
 * `measurements`; até lá, diferença é escrita fora do domínio, e um plano
 * corrigido por esse fluxo sairia como falha permanente.
 *
 * Só lê: consultas pelo query builder, sem models, sem lock, sem gravar
 * arquivo; no MySQL, numa transação READ ONLY sempre desfeita no fim. O JSON
 * sai cru, sem o formatador do console: marcação de estilo num nome de plano
 * não some nem quebra o arquivo.
 *
 * @phpstan-import-type PlanReport from Diagnostic
 * @phpstan-import-type Report from Diagnostic
 * @phpstan-import-type Connection from Diagnostic
 */
class DiagnoseMeasurementInitialProgress extends Command
{
    protected $signature = 'measurements:initial-progress-report
                            {--operation=* : Limita a operações específicas (id)}
                            {--all : Lista também os planos sem avanço inicial legado}
                            {--json : Devolve o relatório completo, com todos os planos, como JSON}';

    protected $description = 'Relata (somente leitura) o avanço físico inicial legado de cada plano de medição: a decisão da migração de 05/10/2026, prevista antes dela ou gravada depois, e se o plano confere com ela';

    private const HEADERS = ['Operação', 'Plano', '1ª linha', 'Inicial legado', 'Engenharia vigente', 'Baseline atual', 'Classificação', 'Motivo/medição', 'Confere'];

    private const ISSUE_LABELS = [
        Diagnostic::ISSUE_DECISION_MISSING => 'a migração não registrou decisão',
        Diagnostic::ISSUE_BASELINE_WITHOUT_TRAIL => 'avanço inicial sem trilha e sem data',
        Diagnostic::ISSUE_DUPLICATE_TRAIL => 'mais de uma decisão registrada',
        Diagnostic::ISSUE_TRAIL_INCONSISTENT => 'registro da decisão incoerente',
        Diagnostic::ISSUE_BASELINE_DIFFERS_FROM_TRAIL => 'avanço inicial diferente da decisão registrada',
    ];

    private const NOTHING = '—';

    public function handle(Diagnostic $diagnostic): int
    {
        $operationIds = $this->requestedOperationIds();

        if ($operationIds === null) {
            return self::FAILURE;
        }

        $report = $diagnostic->report($operationIds);

        if ($report['missing_operation_ids'] !== []) {
            $this->error(sprintf(
                'Operação não encontrada: %s. Informe o id de uma operação cadastrada.',
                implode(', ', $report['missing_operation_ids']),
            ));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'migration' => Diagnostic::MIGRATION,
                'phase' => $report['phase'],
                'read_only' => true,
                'connection' => $report['connection'],
                'operation_ids' => $operationIds,
                'consistent' => $report['consistent'],
                'migration_would_fail_plan_ids' => $report['migration_would_fail_plan_ids'],
                'summary' => $report['summary'],
                'plans' => $report['plans'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->render($report);
        }

        return $report['consistent'] === false || $report['migration_would_fail_plan_ids'] !== []
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * As operações pedidas, ou `null` quando alguma não é um id.
     *
     * Valor ilegível -- inclusive o vazio de `--operation=` -- nunca vira "todas
     * as operações": quem pediu uma operação e digitou errado precisa saber, e
     * não receber a base inteira como se fosse a pedida.
     *
     * @return list<int>|null
     */
    private function requestedOperationIds(): ?array
    {
        $operationIds = [];

        foreach ((array) $this->option('operation') as $value) {
            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1) {
                $this->error(sprintf('Operação inválida: "%s". Informe o id numérico.', is_scalar($value) ? (string) $value : get_debug_type($value)));

                return null;
            }

            $operationIds[] = (int) trim($value);
        }

        return array_values(array_unique($operationIds));
    }

    /**
     * @param  Report  $report
     */
    private function render(array $report): void
    {
        $beforeMigration = $report['phase'] === Diagnostic::PHASE_BEFORE_MIGRATION;

        $this->line($beforeMigration
            ? 'Antes da migração 2026_10_05_170828: a classificação é a decisão que ela vai gravar em cada plano.'
            : 'Depois da migração 2026_10_05_170828: a classificação é a decisão gravada na trilha, conferida com o avanço inicial do plano.');
        $this->line($this->connectionLine($report['connection']));

        $rows = [];
        $hidden = 0;

        foreach ($report['plans'] as $plan) {
            if (! $this->option('all') && $plan['classification'] === Classification::NoLegacyInitialProgress) {
                $hidden++;

                continue;
            }

            $rows[] = $this->row($plan);
        }

        if ($report['plans'] === []) {
            $this->warn('Nenhum plano de medição encontrado.');
        } elseif ($rows !== []) {
            $this->table(self::HEADERS, $rows);
        }

        $this->newLine();
        $this->line(sprintf('Planos analisados: %d', count($report['plans'])));

        foreach ($report['summary'] as $classification => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %s: %d', Classification::from($classification)->label(), $count));
            }
        }

        if ($hidden > 0) {
            $this->line(sprintf('Planos sem avanço inicial legado fora da tabela: %d (use --all para listá-los).', $hidden));
        }

        $this->renderWarnings($report, $beforeMigration);

        $this->comment('Somente leitura: nenhum registro foi alterado.');
    }

    /**
     * @param  Report  $report
     */
    private function renderWarnings(array $report, bool $beforeMigration): void
    {
        $plans = collect($report['plans']);
        $breaking = count($report['migration_would_fail_plan_ids']);

        if ($breaking > 0) {
            $this->error(sprintf(
                'A migração vai falhar em %d plano(s): uma aprovação da Engenharia guarda o avanço como lista, e não como número (veja a coluna Motivo/medição). No deploy, o migrate para nesse plano e o startup não segue até o dado ser corrigido.',
                $breaking,
            ));
        }

        $ownerDecisions = $plans->filter(fn (array $plan): bool => $plan['classification']->requiresOwnerDecision())->count();

        if ($ownerDecisions > 0) {
            $this->warn($beforeMigration
                ? sprintf('%d plano(s) com "Realiz. inicial" que a migração não vai copiar: o avanço inicial fica em 0,00%% e a decisão é do dono.', $ownerDecisions)
                : sprintf('%d plano(s) com "Realiz. inicial" que a migração não copiou: o avanço inicial ficou em 0,00%% e a decisão é do dono.', $ownerDecisions));
        }

        if ($beforeMigration) {
            return;
        }

        $drifted = $plans->filter(fn (array $plan): bool => $plan['drift'])->count();

        if ($drifted > 0) {
            $this->warn(sprintf(
                '%d plano(s) mudaram desde a decisão: ela continua valendo, mas a regra, com as aprovações vigentes até a data dela, hoje daria outro resultado.',
                $drifted,
            ));
        }

        $divergent = $plans->filter(fn (array $plan): bool => $plan['issues'] !== [])->count();

        if ($divergent > 0) {
            $this->error(sprintf('%d plano(s) não conferem com a trilha da migração: veja a coluna Confere.', $divergent));
        } else {
            $this->info('Todos os planos conferem com a trilha da migração.');
        }
    }

    /**
     * @param  PlanReport  $plan
     * @return list<string>
     */
    private function row(array $plan): array
    {
        $firstLine = $plan['first_line'];

        return [
            $plan['operation_code'] ?? '#'.$plan['operation_id'],
            sprintf('%s (#%d)', $plan['plan_name'], $plan['plan_set_id']),
            $firstLine === null ? self::NOTHING : sprintf('nº %d (#%d)', $firstLine['sequence_number'], $firstLine['id']),
            $firstLine === null ? self::NOTHING : $this->percent($firstLine['initial_percent']),
            $plan['current_approvals'] > 0 ? sprintf('Sim (%d)', $plan['current_approvals']) : 'Não',
            $this->baseline($plan['baseline']),
            $plan['classification']->label(),
            $this->reason($plan),
            $this->agreement($plan),
        ];
    }

    /**
     * @param  array{percent: string, reference_date: string|null}|null  $baseline
     */
    private function baseline(?array $baseline): string
    {
        if ($baseline === null) {
            return self::NOTHING;
        }

        if ($baseline['reference_date'] === null) {
            return $this->percent($baseline['percent']).' (sem data)';
        }

        $date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $baseline['reference_date'], $parts) === 1
            ? sprintf('%s/%s/%s', $parts[3], $parts[2], $parts[1])
            : $baseline['reference_date'];

        return sprintf('%s em %s', $this->percent($baseline['percent']), $date);
    }

    /**
     * Primeiro, se a migração vai quebrar no plano -- é o que para o deploy, e
     * a classificação ao lado não vale até o dado ser corrigido. Depois, o que
     * decidiu a classificação -- a medição que provou ou desmentiu o inicial, a
     * soma que passou de 100% -- e o que a migração lê de um jeito que o dono
     * precisa saber.
     *
     * @param  PlanReport  $plan
     */
    private function reason(array $plan): string
    {
        $decision = $plan['recorded'] ?? $plan['prediction'];
        $parts = [];

        if ($plan['migration_would_fail_measurement_ids'] !== []) {
            $parts[] = sprintf('a migração vai falhar neste plano (medição %s)', $this->measurementList($plan['migration_would_fail_measurement_ids']));
        }

        $main = match ($plan['classification']) {
            Classification::SafeBackfilled => $this->measurementReference('provado por', $decision['proven_by_measurement_id'] ?? null),
            Classification::ApprovalStartedBelowDeclaredInitial => $this->measurementReference('contradito por', $decision['contradicted_by_measurement_id'] ?? null),
            Classification::InitialPlusMeasuredAbove100 => $this->initialPlusMeasured($decision['line_initial_percent'] ?? null, $decision['measured_percent'] ?? null),
            Classification::DecisionMissing => $plan['prediction'] === null ? null : 'previsto: '.$plan['prediction']['classification']->label(),
            Classification::NoLegacyInitialProgress => $plan['first_line'] === null ? 'plano sem linhas' : null,
            default => null,
        };

        if ($main !== null) {
            $parts[] = $main;
        }

        foreach ($plan['other_lines_with_initial'] as $line) {
            $parts[] = sprintf('inicial também na linha nº %d', $line['sequence_number']);
        }

        if ($plan['unreadable_approval_measurement_ids'] !== []) {
            $parts[] = 'avanço ilegível na medição '.$this->measurementList($plan['unreadable_approval_measurement_ids']);
        }

        return $parts === [] ? self::NOTHING : implode('; ', $parts);
    }

    /**
     * @param  list<int>  $measurementIds
     */
    private function measurementList(array $measurementIds): string
    {
        return implode(', ', array_map(fn (int $id): string => '#'.$id, $measurementIds));
    }

    /**
     * De qual banco o relatório leu: com configuração em cache, as variáveis
     * DB_* do shell são ignoradas sem aviso, e esta linha é a prova.
     *
     * @param  Connection  $connection
     */
    private function connectionLine(array $connection): string
    {
        if ($connection['host'] === null) {
            return sprintf('Banco lido: %s, base %s.', $connection['driver'], $connection['database']);
        }

        return sprintf(
            'Banco lido: %s em %s%s, base %s.',
            $connection['driver'],
            $connection['host'],
            $connection['port'] === null ? '' : ':'.$connection['port'],
            $connection['database'],
        );
    }

    /**
     * @param  PlanReport  $plan
     */
    private function agreement(array $plan): string
    {
        if ($plan['consistent'] === null) {
            return self::NOTHING;
        }

        if ($plan['issues'] !== []) {
            return 'Não: '.implode('; ', array_map(
                fn (string $issue): string => self::ISSUE_LABELS[$issue] ?? $issue,
                $plan['issues'],
            ));
        }

        return $plan['drift'] ? 'Sim (mudou desde a decisão)' : 'Sim';
    }

    private function measurementReference(string $verb, ?int $measurementId): ?string
    {
        return $measurementId === null ? null : sprintf('%s #%d', $verb, $measurementId);
    }

    /**
     * A soma que passou de 100%, com os números que a decisão registrou; nada se
     * algum deles não for número (uma trilha alterada à mão, por exemplo).
     */
    private function initialPlusMeasured(mixed $initial, mixed $measured): ?string
    {
        foreach ([$initial, $measured] as $value) {
            if (! is_string($value) || preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
                return null;
            }
        }

        return 'inicial + medido: '.$this->percent(bcadd($initial, $measured, 2));
    }

    /**
     * Percentual no formato brasileiro, sem passar por float; o que não é número
     * sai como está.
     */
    private function percent(string $value): string
    {
        return preg_match('/^-?\d+\.\d{2}$/', $value) === 1 ? str_replace('.', ',', $value).'%' : $value;
    }
}
