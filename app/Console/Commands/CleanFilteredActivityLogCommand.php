<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CleanFilteredActivityLogCommand extends Command
{
    protected $signature = 'audit:clean-filtered {--dry-run : Apenas relata quantos seriam removidos}';

    protected $description = 'Remove logs de auditoria antigos preservando evidências reguladas de medições/operações (P0.4) e a evidência financeira do PU (Fase 6)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Retenção: descartável 365 dias; workflow/operational retido 7 anos (2555 dias).
        $disposableDays = (int) config('audit.retention_disposable_days', 365);
        $workflowDays = (int) config('audit.retention_workflow_days', 2555);

        $disposableCutoff = now()->subDays($disposableDays);
        $workflowCutoff = now()->subDays($workflowDays);

        // Log names considerados regulados / evidência — nunca deletados antes
        // de workflowDays. A lista vem do config e só de lá: enquanto ela vivia
        // aqui, a cópia declarada em `config/audit.php` não surtia efeito nenhum
        // e as duas divergiam sem que nada acusasse.
        $protectedLogs = (array) config('audit.protected_logs', []);

        // Ler a política de fora tem um risco que a lista hardcoded não tinha:
        // um config vazio ou mal publicado transformaria toda a evidência
        // regulada em descartável, e a limpeza apagaria em 365 dias o que
        // deveria durar sete anos. Sem lista, o comando não roda.
        if ($protectedLogs === []) {
            $this->error('audit.protected_logs está vazio: a limpeza foi abortada para não descartar evidência regulada.');

            return self::FAILURE;
        }

        // Fase 6 (P1-10): evidência protegida também por EVENTO numa trilha
        // descartável (`pu-calculation`) e pelo tipo do registro (insumos
        // financeiros do PU que caem em `default`). Mesma regra de segurança: se a
        // lista de eventos do PU sumir do config, a limpeza não roda -- senão
        // homologação, correção de índice e liquidação voltariam a expirar em um
        // ano em silêncio.
        /** @var array<string, list<string>> $protectedEvents */
        $protectedEvents = array_filter((array) config('audit.protected_events', []), fn ($events): bool => is_array($events) && $events !== []);
        /** @var list<string> $protectedSubjectTypes */
        $protectedSubjectTypes = array_values(array_filter((array) config('audit.protected_subject_types', []), 'is_string'));

        if (! isset($protectedEvents['pu-calculation'])) {
            $this->error('audit.protected_events não declara a evidência do PU (pu-calculation): a limpeza foi abortada para não descartar evidência financeira.');

            return self::FAILURE;
        }

        $protectedCount = $this->protectedQuery($protectedLogs, $protectedEvents, $protectedSubjectTypes)
            ->where('created_at', '<', $workflowCutoff)
            ->count();

        $disposableCount = $this->disposableQuery($protectedLogs, $protectedEvents, $protectedSubjectTypes)
            ->where('created_at', '<', $disposableCutoff)
            ->count();

        if ($dryRun) {
            $this->info("Dry-run: disposable={$disposableCount} (> {$disposableDays}d), protected_older_than_workflow={$protectedCount} (> {$workflowDays}d, log_name in [".implode(',', $protectedLogs).'] + eventos protegidos + tipos protegidos)');

            return self::SUCCESS;
        }

        $deletedProtected = 0;
        if ($protectedCount > 0) {
            $deletedProtected = $this->protectedQuery($protectedLogs, $protectedEvents, $protectedSubjectTypes)
                ->where('created_at', '<', $workflowCutoff)
                ->delete();
        }

        $deletedDisposable = $this->disposableQuery($protectedLogs, $protectedEvents, $protectedSubjectTypes)
            ->where('created_at', '<', $disposableCutoff)
            ->delete();

        $this->info("Audit cleanup: deleted_disposable={$deletedDisposable}, deleted_protected_expired={$deletedProtected}");

        return self::SUCCESS;
    }

    /**
     * Evidência protegida: categoria inteira, evento protegido de uma trilha, ou
     * registro de um tipo protegido.
     *
     * @param  list<string>  $protectedLogs
     * @param  array<string, list<string>>  $protectedEvents
     * @param  list<string>  $protectedSubjectTypes
     */
    private function protectedQuery(array $protectedLogs, array $protectedEvents, array $protectedSubjectTypes): Builder
    {
        return DB::table('activity_log')->where(fn (Builder $query) => $this->protectedPredicate($query, $protectedLogs, $protectedEvents, $protectedSubjectTypes));
    }

    /**
     * Todo o resto. Cada termo do predicado protegido é um booleano definido (os
     * NULL são testados antes), então o `NOT` não deixa linha nenhuma em
     * "desconhecido": o que não é protegido continua sendo apagado no prazo
     * descartável, inclusive as linhas sem log name ou sem evento.
     *
     * @param  list<string>  $protectedLogs
     * @param  array<string, list<string>>  $protectedEvents
     * @param  list<string>  $protectedSubjectTypes
     */
    private function disposableQuery(array $protectedLogs, array $protectedEvents, array $protectedSubjectTypes): Builder
    {
        return DB::table('activity_log')->whereNot(fn (Builder $query) => $this->protectedPredicate($query, $protectedLogs, $protectedEvents, $protectedSubjectTypes));
    }

    /**
     * @param  list<string>  $protectedLogs
     * @param  array<string, list<string>>  $protectedEvents
     * @param  list<string>  $protectedSubjectTypes
     */
    private function protectedPredicate(Builder $query, array $protectedLogs, array $protectedEvents, array $protectedSubjectTypes): void
    {
        $query->where(fn (Builder $logs) => $logs->whereNotNull('log_name')->whereIn('log_name', $protectedLogs));

        foreach ($protectedEvents as $logName => $events) {
            $query->orWhere(fn (Builder $event) => $event
                ->whereNotNull('log_name')
                ->where('log_name', $logName)
                ->whereNotNull('event')
                ->whereIn('event', $events));
        }

        if ($protectedSubjectTypes !== []) {
            $query->orWhere(fn (Builder $subject) => $subject
                ->whereNotNull('subject_type')
                ->whereIn('subject_type', $protectedSubjectTypes));
        }
    }
}
