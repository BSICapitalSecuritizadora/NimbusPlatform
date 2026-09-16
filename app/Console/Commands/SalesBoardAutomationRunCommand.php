<?php

namespace App\Console\Commands;

use App\DTOs\SalesBoards\SalesBoardAutomationTargetCandidate;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Models\SalesBoardAutomationRun;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A porta de entrada da automação mensal.
 *
 * O código de saída é semântico e vale para o monitoramento: `0` significa que a
 * execução técnica terminou, **mesmo com bloqueios de domínio**. Um
 * empreendimento com cadastro incompleto é operação normal antes do fechamento,
 * e fazer o comando falhar por isso ensinaria o time a ignorar o alarme. Saída
 * diferente de zero é falha técnica do orquestrador -- aí sim alguém precisa
 * olhar.
 *
 * Desligado também é `0`. O scheduler continua chamando o comando de hora em
 * hora, e desligado não é falha: é a automação fazendo exatamente o que foi
 * decidido -- nada.
 */
class SalesBoardAutomationRunCommand extends Command
{
    protected $signature = 'sales-boards:automation-run
        {--dry-run : Apenas mostra o que aconteceria, sem gravar nada}
        {--json : Saída estruturada, para monitoramento}
        {--as-of= : Data de negócio a considerar (Y-m-d), para reprodução temporal}';

    protected $description = 'Garante que exista o ciclo mensal do Quadro de Vendas para cada empreendimento habilitado';

    public function handle(SalesBoardAutomationService $automation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        /**
         * O interruptor vem antes de tudo -- antes da data forçada, antes do
         * serviço, antes de qualquer leitura de alvo. Não existe opção que o
         * contorne: desligado vale para o scheduler e para quem roda à mão.
         */
        if (! SalesBoardAutomationConfig::enabled()) {
            $json ? $this->renderDisabledJson($dryRun) : $this->renderDisabledText();

            return self::SUCCESS;
        }

        try {
            $asOf = $this->asOf($dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $run = $automation->run(
                trigger: $this->trigger(),
                asOf: $asOf,
                dryRun: $dryRun,
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->error(sprintf(
                'Falha técnica na automação (%s). O detalhe está no log da aplicação.',
                class_basename($exception),
            ));

            return self::FAILURE;
        }

        $json ? $this->renderJson($run, $dryRun) : $this->renderText($run, $dryRun);

        /**
         * Bloqueio não é falha do comando: quem precisa de ação é o cadastro,
         * não o scheduler.
         */
        return self::SUCCESS;
    }

    /**
     * A data de negócio forçada, quando houver.
     *
     * Uma execução **com escrita** e data forçada é recusada em produção: ela
     * geraria competências históricas de verdade a partir de um engano de linha
     * de comando, e a automação não tem como desfazer um ciclo congelado. A
     * prévia continua livre -- ela não grava nada.
     */
    private function asOf(bool $dryRun): ?CarbonImmutable
    {
        $option = $this->option('as-of');

        if (blank($option)) {
            return null;
        }

        if (! $dryRun && app()->isProduction()) {
            throw new \RuntimeException(
                'A opção --as-of não é permitida em produção sem --dry-run: '
                    .'ela geraria competências históricas reais a partir de uma data forçada.'
            );
        }

        return CarbonImmutable::parse((string) $option)->startOfDay();
    }

    /**
     * Executado pelo scheduler ou por uma pessoa.
     *
     * A distinção não cria usuário nenhum: a automação continua sem ator
     * humano, e a procedência dela é a própria trilha.
     */
    private function trigger(): SalesBoardAutomationRunTrigger
    {
        return $this->laravel->runningInConsole() && ! app()->runningUnitTests() && $this->input->isInteractive()
            ? SalesBoardAutomationRunTrigger::Manual
            : SalesBoardAutomationRunTrigger::Scheduled;
    }

    private function renderJson(SalesBoardAutomationRun $run, bool $dryRun): void
    {
        $payload = $run->toSummaryArray() + [
            'dry_run' => $dryRun,
            'automation_enabled' => SalesBoardAutomationConfig::enabled(),
        ];

        if ($dryRun) {
            $payload['run_id'] = null;
            $payload['targets'] = $this->candidates($run)
                ->map(fn (SalesBoardAutomationTargetCandidate $candidate): array => $candidate->toArray())
                ->all();
        }

        /**
         * `line()` sem decoração: banner ANSI dentro do JSON quebraria o
         * consumidor, que é a única razão de o `--json` existir.
         */
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * O mesmo formato de sempre, com o estado dizendo o que aconteceu: nada.
     *
     * Não há `run_id` porque não há execução -- desligado não registra nem a
     * própria passagem. Os contadores são zero, e não nulos: zero é o que de
     * fato foi feito.
     */
    private function renderDisabledJson(bool $dryRun): void
    {
        $payload = [
            'event' => 'sales_board_automation_run',
            'run_id' => null,
            'trigger' => $this->trigger()->value,
            'status' => 'disabled',
            'as_of' => null,
            'latest_due_month' => null,
            'discovered' => 0,
            'attempted' => 0,
            'generated' => 0,
            'existing' => 0,
            'blocked' => 0,
            'failed' => 0,
            'skipped' => 0,
            'alerts_sent' => 0,
            'alerts_deduped' => 0,
            'duration_ms' => 0,
            'dry_run' => $dryRun,
            'automation_enabled' => false,
        ];

        if ($dryRun) {
            $payload['targets'] = [];
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function renderDisabledText(): void
    {
        $this->warn('A automação do Quadro de Vendas está desligada (sales_board.automation.enabled). '
            .'Nada foi executado nem registrado.');
    }

    private function renderText(SalesBoardAutomationRun $run, bool $dryRun): void
    {
        $this->info(sprintf(
            '%sData de negócio %s · última competência devida %s',
            $dryRun ? '[prévia] ' : '',
            $run->as_of_date->toDateString(),
            $run->latestDueMonthLabel(),
        ));

        if ($dryRun) {
            $candidates = $this->candidates($run);

            if ($candidates->isEmpty()) {
                $this->line('Nenhuma competência devida para os empreendimentos habilitados.');

                return;
            }

            $this->table(
                ['Empreendimento', 'Competência', 'Devida desde', 'Abrir validação'],
                $candidates->map(fn (SalesBoardAutomationTargetCandidate $candidate): array => [
                    $candidate->constructionId,
                    $candidate->referenceMonth->format('m/Y'),
                    $candidate->dueDate->toDateString(),
                    $candidate->autoOpenBuilderReview ? 'sim' : 'não',
                ])->all(),
            );

            return;
        }

        $this->line(collect($run->toSummaryArray())
            ->only(['discovered', 'attempted', 'generated', 'existing', 'blocked', 'failed', 'skipped', 'alerts_sent', 'alerts_deduped', 'duration_ms'])
            ->map(fn (mixed $value, string $key): string => $key.'='.($value ?? '—'))
            ->implode(', '));

        if (! $run->status->isTechnicallyHealthy()) {
            $this->error('Execução concluída com falhas técnicas: '.$run->status->label());
        }
    }

    /**
     * @return Collection<int, SalesBoardAutomationTargetCandidate>
     */
    private function candidates(SalesBoardAutomationRun $run): Collection
    {
        return $run->relationLoaded('previewCandidates')
            ? $run->getRelation('previewCandidates')
            : collect();
    }
}
