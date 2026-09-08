<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;

/**
 * A implementação enquanto não existe responsável definido para o Quadro de
 * Vendas.
 *
 * Devolve lista vazia em tudo -- deliberadamente, e não por não estar pronta. O
 * diagnóstico da Fase F não encontrou nenhuma fonte confiável: `Emission` e
 * `Construction` não têm responsável, e o de `Operation` responde pelo fluxo de
 * medição, que é outro papel.
 *
 * Vazio **não** é falha: a execução continua, os alvos continuam sendo
 * processados, e o que se registra é um aviso estruturado dizendo que havia
 * alerta a emitir e ninguém a quem emitir. É o mesmo tratamento que a avaliação
 * de SLA das medições dá ao caso, e pela mesma razão -- alerta silenciosamente
 * descartado é pior que alerta ausente.
 */
class DeferredSalesBoardAutomationRecipientResolver implements SalesBoardAutomationRecipientResolver
{
    public function forGenerationBlocked(SalesBoardAutomationTarget $target): array
    {
        return [];
    }

    public function forGenerationFailed(SalesBoardAutomationTarget $target): array
    {
        return [];
    }

    public function forBuilderHandoff(SalesBoardCycle $cycle): array
    {
        return [];
    }

    public function forBuilderReminder(SalesBoardBuilderReview $review): array
    {
        return [];
    }

    public function forManagementReminder(SalesBoardCycle $cycle): array
    {
        return [];
    }
}
