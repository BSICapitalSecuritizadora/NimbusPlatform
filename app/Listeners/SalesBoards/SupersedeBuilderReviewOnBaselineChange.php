<?php

declare(strict_types=1);

namespace App\Listeners\SalesBoards;

use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Services\SalesBoards\SalesBoardBuilderReviewSupersedingService;

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

    public function handle(SalesBoardCurrentBaselineChanged $event): void
    {
        if (! $event->snapshotChanged()) {
            return;
        }

        $this->supersedingService->supersedeOutdated($event->cycle, $event->newBaseline);
    }
}
