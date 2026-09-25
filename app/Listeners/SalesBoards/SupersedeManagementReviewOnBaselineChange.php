<?php

declare(strict_types=1);

namespace App\Listeners\SalesBoards;

use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Services\SalesBoards\SalesBoardManagementReviewSupersedingService;
use App\Services\SalesBoards\SalesBoardReviewSupersessionReconciler;
use Throwable;

/**
 * Liga o recálculo da Fase C à análise da Fase E sem que um saiba do outro.
 *
 * Ouvinte próprio, e não uma chamada dentro do que já invalida a validação da
 * construtora: as duas invalidações respondem à mesma pergunta -- "estes fatos
 * ainda são os vigentes?" -- mas são decisões de fases diferentes, e encadeá-las
 * faria a Fase D passar a saber que existe análise da Gestão.
 *
 * O filtro é o resumo da posição, não o id da versão. Uma versão nova com o
 * mesmo quadro não desfaz decisão nenhuma: a Gestão continuaria vendo
 * exatamente as mesmas linhas, com exatamente os mesmos números.
 *
 * Registrado só pelo event discovery do Laravel, por morar em `app/Listeners`.
 * Um `Event::listen()` a mais no provider faria o ouvinte rodar duas vezes.
 */
class SupersedeManagementReviewOnBaselineChange
{
    public function __construct(
        private readonly SalesBoardManagementReviewSupersedingService $supersedingService,
    ) {}

    /**
     * Uma falha aqui não sobe.
     *
     * O ouvinte roda depois do commit: a versão nova já existe, e deixar a
     * exceção chegar a quem recalculou mostraria um erro para um recálculo
     * gravado -- e ainda impediria o ouvinte seguinte de rodar. A falha é
     * registrada, e a substituição que ficou pendente é concluída na próxima
     * abertura de validação ou análise, ou no próximo recálculo: o sem
     * alteração e o que só troca a origem material chamam a
     * {@see SalesBoardReviewSupersessionReconciler}; o que muda a posição
     * dispara estes ouvintes de novo, contra a versão vigente.
     */
    public function handle(SalesBoardCurrentBaselineChanged $event): void
    {
        if (! $event->snapshotChanged()) {
            return;
        }

        try {
            $this->supersedingService->supersedeOutdated($event->cycle, $event->newBaseline);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
