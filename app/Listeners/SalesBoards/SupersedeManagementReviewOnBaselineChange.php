<?php

declare(strict_types=1);

namespace App\Listeners\SalesBoards;

use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Services\SalesBoards\SalesBoardManagementReviewSupersedingService;

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
 */
class SupersedeManagementReviewOnBaselineChange
{
    public function __construct(
        private readonly SalesBoardManagementReviewSupersedingService $supersedingService,
    ) {}

    public function handle(SalesBoardCurrentBaselineChanged $event): void
    {
        if (! $event->snapshotChanged()) {
            return;
        }

        $this->supersedingService->supersedeOutdated($event->cycle, $event->newBaseline);
    }
}
