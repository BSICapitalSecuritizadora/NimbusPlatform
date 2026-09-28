<?php

declare(strict_types=1);

namespace App\Listeners\SalesBoards;

use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Services\SalesBoards\SalesBoardBuilderReviewSupersedingService;
use App\Services\SalesBoards\SalesBoardReviewSupersessionReconciler;
use Throwable;

/**
 * Liga o recálculo da Fase C à validação da Fase D sem que um saiba do outro.
 *
 * O filtro é o resumo da posição, não o id da versão: uma versão nova com o
 * mesmo quadro não desfaz a conferência da construtora, e sair invalidando por
 * troca de ponteiro faria toda correção de origem material custar uma nova ida à
 * construtora sem que nada tivesse mudado para ela.
 *
 * Registrado só pelo event discovery do Laravel, por morar em `app/Listeners`.
 * Um `Event::listen()` a mais no provider faria o ouvinte rodar duas vezes.
 */
class SupersedeBuilderReviewOnBaselineChange
{
    public function __construct(
        private readonly SalesBoardBuilderReviewSupersedingService $supersedingService,
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
