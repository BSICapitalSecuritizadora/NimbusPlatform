<?php

namespace App\Providers;

use App\Console\Commands\SalesBoardAutomationRunCommand;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationRecipientResolver;
use App\Services\SalesBoards\SalesBoardAutomationAlertDispatcher;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationRecipientResolver;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * O registro da automação do Quadro de Vendas.
 *
 * Provider próprio, e não `routes/console.php`, por duas razões. A primeira é de
 * convivência: aquele arquivo concentra o agendamento de todas as frentes e é
 * editado por várias ao mesmo tempo; acrescentar mais um bloco lá é convite a
 * conflito num arquivo que ninguém quer resolver às pressas. A segunda é de
 * coesão -- os dois bindings que decidem *quem* é automatizado e *quem* é
 * avisado moram aqui, ao lado do agendamento que os usa, e é este o arquivo que
 * se abre para entender de onde vem a elegibilidade.
 *
 * Os dois bindings são o ponto de extensão inteiro da automação:
 *
 * - o **provider de elegibilidade** lê o rollout por Emissão: modo, competência
 *   inicial e escopo homologado;
 * - o **resolvedor de destinatários** lê os responsáveis configurados por
 *   Emissão e papel.
 *
 * Não há implementação alternativa em produção. A antiga habilitação por
 * variável de ambiente da Fase F saiu de `app/` e vive só nos testes do motor.
 */
class SalesBoardAutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SalesBoardAutomationEligibilityProvider::class,
            DatabaseSalesBoardAutomationEligibilityProvider::class,
        );

        $this->app->bind(
            SalesBoardAutomationRecipientResolver::class,
            DatabaseSalesBoardAutomationRecipientResolver::class,
        );

        /**
         * O despachante acumula contadores de uma execução inteira, então
         * precisa ser a mesma instância para o orquestrador e para o motor de
         * lembretes -- `scoped` dá isso sem vazar entre requisições.
         */
        $this->app->scoped(SalesBoardAutomationAlertDispatcher::class);

        /**
         * O contexto de escrita vive por requisição/comando: uma flag estática
         * vazaria entre requisições no Octane e entre processos concorrentes, o
         * que seria pior que não ter guard -- seria um guard que às vezes deixa
         * passar.
         */
        $this->app->scoped(SalesBoardWriteContext::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([SalesBoardAutomationRunCommand::class]);

        $this->app->booted(function (): void {
            $this->scheduleAutomation($this->app->make(Schedule::class));
        });
    }

    /**
     * De hora em hora, e não "às 08:00 do dia 13".
     *
     * A regra de negócio é a **data** em que a competência vence, não um
     * horário. Agendar no instante exato faria a competência inteira depender de
     * o processo estar de pé naquele minuto; de hora em hora, um scheduler que
     * passou três dias fora volta e encontra a competência ainda devida. O
     * catch-up deixa de ser mecanismo e passa a ser consequência.
     *
     * Rodar a cada hora não significa apurar a cada hora: a descoberta é barata
     * e cada alvo só tenta quando `next_attempt_at` permite.
     *
     * `onOneServer()` e `withoutOverlapping()` são economia, não correção. Eles
     * dependem do store de cache compartilhado (`CACHE_STORE`); se ele falhar ou
     * duas instâncias atravessarem mesmo assim, quem impede a duplicação são a
     * reserva da tentativa sob lock e as uniques de
     * `sales_board_automation_targets` e `sales_board_cycles`, e é isso que os
     * testes de concorrência exercitam -- chamando o serviço direto, sem lock de
     * scheduler nenhum.
     *
     * O lock de sobreposição expira em duas horas, e não nas 24 do padrão do
     * Laravel. Ele sobrevive a um processo morto -- mora no store de cache, não
     * no processo --, e um deploy ou reinício no meio de uma execução deixava a
     * automação pulando todos os ticks até o dia seguinte. Duas horas cobrem com
     * folga a execução mais longa esperada; o `startup.sh` ainda limpa os locks
     * do scheduler antes de subir os laços.
     */
    private function scheduleAutomation(Schedule $schedule): void
    {
        $schedule->command(SalesBoardAutomationRunCommand::class)
            ->hourly()
            ->name('sales-board-automation-run')
            ->timezone(Config::get('measurements.business_timezone', 'America/Sao_Paulo'))
            ->onOneServer()
            ->withoutOverlapping(SalesBoardAutomationConfig::OVERLAP_LOCK_MINUTES);
    }
}
