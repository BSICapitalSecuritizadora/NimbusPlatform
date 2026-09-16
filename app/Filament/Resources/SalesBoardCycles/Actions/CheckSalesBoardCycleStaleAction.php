<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Enums\SalesBoardStaleImpact;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Support\SalesBoards\SalesBoardCycleNextAction;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * "Verificar alterações": compara a versão vigente com a fonte de agora.
 *
 * Verificar não recalcula. A ação responde o que mudou e onde, e para por aí --
 * criar uma versão nova é decisão de quem está olhando, com motivo, na ação ao
 * lado. Encadear as duas transformaria "dar uma olhada" em reescrever o passado.
 */
class CheckSalesBoardCycleStaleAction
{
    public static function make(string $name = 'checkStale'): Action
    {
        return Action::make($name)
            ->label('Verificar alterações')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->tooltip('Compara a versão congelada com a fonte atual, sem alterar nada.')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canView($record)
                && ($record->current_baseline_id !== null))
            ->action(function (SalesBoardCycle $record): void {
                $assessment = app(SalesBoardStaleDetectionService::class)->check($record);

                self::notify($assessment)->send();
            });
    }

    /**
     * "Fonte alterada sem impacto" e "alterações materiais" pedem coisas opostas
     * -- seguir sem recalcular e recalcular --, e a notificação diz qual das duas
     * vale, em vez de deixar a diferença a cargo da cor.
     */
    public static function notify(SalesBoardStaleAssessment $assessment): Notification
    {
        $notification = Notification::make()
            ->title($assessment->impact->label())
            ->body(match ($assessment->impact) {
                SalesBoardStaleImpact::None => $assessment->message(),
                SalesBoardStaleImpact::Blocking => new HtmlString(e(SalesBoardCycleNextAction::staleGuidance($assessment->impact))
                    .'<br>'.SalesBoardIssuePresenter::toHtml($assessment->readiness->blockingIssueCounts())->toHtml()),
                default => $assessment->message().' '.SalesBoardCycleNextAction::staleGuidance($assessment->impact),
            });

        return match ($assessment->impact) {
            SalesBoardStaleImpact::None => $notification->success(),
            SalesBoardStaleImpact::SourceOnly => $notification->info(),
            SalesBoardStaleImpact::Material => $notification->warning(),
            SalesBoardStaleImpact::Blocking => $notification->danger(),
        };
    }
}
