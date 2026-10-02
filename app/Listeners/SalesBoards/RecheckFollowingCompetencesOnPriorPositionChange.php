<?php

declare(strict_types=1);

namespace App\Listeners\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardChainStructure;
use App\Enums\SalesBoardCycleStatus;
use App\Events\SalesBoards\SalesBoardPriorPositionChanged;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Support\Dates\InclusiveDateBound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Confere de novo a fonte das competências seguintes em andamento quando a
 * posição de uma anterior muda.
 *
 * Nunca marca uma competência à toa: a verificação deriva a fonte, e só acusa
 * "Alterações materiais" quando o que a competência herdou da anterior mudou o
 * resumo dela, ou quando a cadeia de onde ela parte mudou -- a anterior foi
 * reaberta ou cancelada ({@see SalesBoardChainStructure}). A retificação
 * publicada da anterior só troca a versão da âncora, e a seguinte continua
 * julgada pelo conteúdo. É uma antecipação do selo -- sem o ouvinte, a mudança
 * aparece na próxima abertura ou verificação, e o portão da aprovação continua
 * sendo a garantia.
 *
 * Enfileirado, depois do commit: cada competência seguinte custa uma derivação
 * completa, que não deve pesar na requisição da aprovação ou do cancelamento.
 * Depende do `queue:work` do startup.
 *
 * Registrado só pelo event discovery do Laravel, por morar em `app/Listeners`.
 * Um `Event::listen()` a mais no provider faria o ouvinte rodar duas vezes.
 */
class RecheckFollowingCompetencesOnPriorPositionChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
    ) {}

    /**
     * Uma falha numa competência não impede a verificação das outras, e não
     * sobe: o ato que mudou a posição já está gravado.
     */
    public function handle(SalesBoardPriorPositionChanged $event): void
    {
        $cycles = SalesBoardCycle::query()
            ->where('construction_id', $event->constructionId)
            ->where('reference_month', '>', InclusiveDateBound::upperBound($event->referenceMonth->endOfMonth()))
            ->whereNotIn('status', [SalesBoardCycleStatus::Approved->value, SalesBoardCycleStatus::Cancelled->value])
            ->whereNotNull('current_baseline_id')
            ->orderBy('reference_month')
            ->get();

        foreach ($cycles as $cycle) {
            try {
                $this->staleDetectionService->check($cycle);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
