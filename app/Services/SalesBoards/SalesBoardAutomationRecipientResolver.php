<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\Emission;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\User;

/**
 * Quem deve ser avisado sobre cada situação da automação.
 *
 * A resposta de produção vem dos responsáveis configurados no rollout de cada
 * Emissão, por papel ({@see DatabaseSalesBoardAutomationRecipientResolver}).
 * `Emission` e `Construction` não têm responsável próprio, e o
 * `Operation.responsible_user_id` responde pelo fluxo de **medição** -- outro
 * papel, outro assunto. Mapear bloqueio de Quadro de Vendas para essa pessoa
 * seria inventar uma responsabilidade que ninguém atribuiu.
 *
 * As alternativas descartadas merecem registro, porque são as tentadoras:
 * notificar todos os administradores, ou todos que tenham `sales-boards.update`.
 * As duas transformam alerta operacional em ruído para gente que não tem o que
 * fazer com ele, e as duas violam o mínimo privilégio que o resto do sistema
 * respeita. Lista vazia continua sendo resposta legítima.
 *
 * @return list<User> em todos os métodos
 */
interface SalesBoardAutomationRecipientResolver
{
    /**
     * @return list<User>
     */
    public function forGenerationBlocked(SalesBoardAutomationTarget $target): array;

    /**
     * @return list<User>
     */
    public function forGenerationFailed(SalesBoardAutomationTarget $target): array;

    /**
     * @return list<User>
     */
    public function forBuilderHandoff(SalesBoardCycle $cycle): array;

    /**
     * @return list<User>
     */
    public function forBuilderReminder(SalesBoardBuilderReview $review): array;

    /**
     * A escalação da validação parada com a construtora.
     *
     * Método próprio porque o destinatário é outro: escalar para quem já recebe
     * o lembrete não escala para ninguém.
     *
     * @return list<User>
     */
    public function forBuilderEscalation(SalesBoardBuilderReview $review): array;

    /**
     * A âncora é o **ciclo**, não a análise: a Fase E abre a análise da Gestão
     * sob demanda, então uma competência pode estar parada esperando a Gestão
     * sem que exista revisão alguma. Pedir uma revisão aqui obrigaria o motor a
     * criar uma para poder avisar que ninguém a criou.
     *
     * @return list<User>
     */
    public function forManagementReminder(SalesBoardCycle $cycle): array;

    /**
     * A automação da Emissão inteira ficou suspensa por mudança de escopo.
     *
     * @return list<User>
     */
    public function forScopeSuspended(Emission $emission): array;

    /**
     * A automação da Emissão foi encerrada porque ela foi liquidada.
     *
     * É a Gestão quem registra o fim do rollout ("Retornar ao modo legado") e
     * conduz ou cancela os ciclos que ficaram, por isso o aviso é dela.
     *
     * @return list<User>
     */
    public function forEmissionLiquidated(Emission $emission): array;

    /**
     * Uma execução morreu no meio. Ela não pertence a uma Emissão só: todas as
     * automatizadas ficaram sem processamento naquele intervalo.
     *
     * @return list<User>
     */
    public function forRunInterrupted(SalesBoardAutomationRun $run): array;
}
