<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\User;

/**
 * Quem deve ser avisado sobre cada situação da automação.
 *
 * Interface separada da implementação porque a resposta hoje é "ninguém", e
 * isso não é um detalhe a esconder: `Emission` e `Construction` não têm
 * responsável. O que existe no projeto é `Operation.responsible_user_id`, que é
 * o responsável pelo fluxo de **medição** daquela operação -- outro papel, outro
 * assunto. Mapear bloqueio de Quadro de Vendas para essa pessoa seria inventar
 * uma responsabilidade que ninguém atribuiu.
 *
 * As alternativas descartadas merecem registro, porque são as tentadoras:
 * notificar todos os administradores, ou todos que tenham `sales-boards.update`.
 * As duas transformam alerta operacional em ruído para gente que não tem o que
 * fazer com ele, e as duas violam o mínimo privilégio que o resto do sistema
 * respeita.
 *
 * Então o motor de lembretes nasce completo e a entrega nasce **diferida**:
 * quando a Fase G definir o responsável por Emissão, troca-se a implementação e
 * nada mais muda.
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
     * A âncora é o **ciclo**, não a análise: a Fase E abre a análise da Gestão
     * sob demanda, então uma competência pode estar parada esperando a Gestão
     * sem que exista revisão alguma. Pedir uma revisão aqui obrigaria o motor a
     * criar uma para poder avisar que ninguém a criou.
     *
     * @return list<User>
     */
    public function forManagementReminder(SalesBoardCycle $cycle): array;
}
