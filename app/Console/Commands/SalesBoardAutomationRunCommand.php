<?php

namespace App\Console\Commands;

use App\DTOs\SalesBoards\SalesBoardAutomationTargetCandidate;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Models\SalesBoardAutomationRun;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Support\SalesBoards\BusinessDateInput;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * A porta de entrada da automação mensal.
 *
 * O código de saída é semântico e vale para o monitoramento: `0` significa que a
 * execução terminou tecnicamente saudável ("concluída" ou "concluída com
 * bloqueios"), **mesmo com bloqueios de domínio**. Um empreendimento com cadastro
 * incompleto é operação normal antes do fechamento, e fazer o comando falhar por
 * isso ensinaria o time a ignorar o alarme. Qualquer outro desfecho -- falha
 * técnica de alvo, aviso que não saiu, lembretes que estouraram, orquestração
 * que caiu -- sai com código diferente de zero, e o scheduler registra a falha
 * no log da aplicação: aí sim alguém precisa olhar.
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

        $this->ensureMemoryLimit();

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
         * não o scheduler. Falha técnica é.
         */
        return $run->status->isTechnicallyHealthy() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Garante para esta execução o teto de memória configurado.
     *
     * O scheduler dispara este comando num processo PHP novo, com o
     * `memory_limit` do `php.ini` da imagem -- 128 MB quando nada o define --, e
     * sem as flags `-d` de quem o chamou. A geração de uma obra grande hidrata as
     * parcelas da obra inteira, e o estouro de memória não é `Throwable`: o
     * processo morria sem registrar nada. Aqui o limite só sobe; um ambiente que
     * já dá mais memória, ou nenhum limite, fica como está.
     */
    private function ensureMemoryLimit(): void
    {
        $desired = SalesBoardAutomationConfig::runMemoryLimit();
        $desiredBytes = SalesBoardAutomationConfig::memoryLimitBytes($desired);
        $currentBytes = SalesBoardAutomationConfig::memoryLimitBytes((string) ini_get('memory_limit'));

        if ($currentBytes === null) {
            return;
        }

        if ($desiredBytes === null || $desiredBytes > $currentBytes) {
            ini_set('memory_limit', $desired);
        }
    }

    /**
     * A data de negócio forçada, quando houver.
     *
     * Só `aaaa-mm-dd` ({@see BusinessDateInput}): uma data ilegível é recusada
     * antes de tudo, inclusive da recusa de produção -- `now`, `last month` e
     * `07/25` eram aceitos e viravam uma execução de outro dia.
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

        $asOf = BusinessDateInput::parse($option);

        if ($asOf === null) {
            throw new RuntimeException('Data inválida em --as-of. Use aaaa-mm-dd (data de negócio).');
        }

        if (! $dryRun && app()->isProduction()) {
            throw new RuntimeException(
                'A opção --as-of não é permitida em produção sem --dry-run: '
                    .'ela geraria competências históricas reais a partir de uma data forçada.'
            );
        }

        return $asOf;
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
            'alerts_failed' => 0,
            'alerts_without_recipient' => 0,
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
            .'Nada foi executado nem registrado. O fluxo humano continua: “Congelar competência” segue disponível.');
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
            ->only(['discovered', 'attempted', 'generated', 'existing', 'blocked', 'failed', 'skipped', 'alerts_sent', 'alerts_deduped', 'alerts_failed', 'alerts_without_recipient', 'duration_ms'])
            ->map(fn (mixed $value, string $key): string => $key.'='.($value ?? '—'))
            ->implode(', '));

        if (! $run->status->isTechnicallyHealthy()) {
            $this->error('Execução concluída com falhas técnicas: '.$run->status->label()
                .(filled($run->failure_message) ? ' -- '.$run->failure_message : ''));
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
