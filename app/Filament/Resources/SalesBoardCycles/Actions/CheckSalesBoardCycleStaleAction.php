<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Enums\SalesBoardStaleImpact;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
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
 *
 * Mas também não é consulta: roda a derivação e a observação completas da obra
 * e grava a constatação na versão vigente (situação, impacto, data da
 * verificação e impressões digitais), que decide se a validação ainda pode
 * seguir. Por isso é de quem conduz a competência -- quem a opera ou a Gestão,
 * que precisa checar a fonte antes de aprovar. Quem só consulta continua vendo a
 * última constatação gravada, na coluna e no filtro de desatualizados. O
 * serviço não muda: a automação, as aberturas e a aprovação também o usam.
 */
class CheckSalesBoardCycleStaleAction
{
    public static function make(string $name = 'checkStale'): Action
    {
        return Action::make($name)
            ->label('Verificar alterações')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->tooltip('Compara a versão congelada com a fonte atual e registra o resultado na versão vigente. Não recalcula nem altera a posição.')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && ($record->current_baseline_id !== null))
            ->action(function (SalesBoardCycle $record): void {
                $assessment = app(SalesBoardStaleDetectionService::class)->check($record);

                self::notify($assessment, $record)->send();
            });
    }

    /**
     * "Fonte alterada sem impacto" e "alterações materiais" pedem coisas opostas
     * -- seguir sem recalcular e recalcular --, e a notificação diz qual das duas
     * vale, em vez de deixar a diferença a cargo da cor.
     *
     * Os avisos da fonte atual vêm por último, quando houver: não impedem a
     * apuração, mas são o que a próxima versão congelaria -- e, para uma versão
     * congelada antes de os avisos serem registrados, a única forma de vê-los.
     *
     * Na competência publicada, sem retificação aberta, a orientação é outra: a
     * posição publicada não se recalcula, e o que mudou entra como
     * extemporâneo na próxima competência -- ou, na última publicada, pela
     * retificação ({@see SalesBoardCycleNextAction::publishedStaleGuidance()}).
     */
    public static function notify(SalesBoardStaleAssessment $assessment, ?SalesBoardCycle $cycle = null): Notification
    {
        $published = ($cycle !== null) && $cycle->hasPublication() && ! $cycle->isUnderRectification();

        $guidance = $published
            ? SalesBoardCycleNextAction::publishedStaleGuidance(
                $assessment->impact,
                $cycle,
                PublishedCompetenceBoundary::lastPublishedMonth((int) $cycle->construction_id),
            )
            : SalesBoardCycleNextAction::staleGuidance($assessment->impact);

        $body = match ($assessment->impact) {
            SalesBoardStaleImpact::None => $assessment->message(),
            SalesBoardStaleImpact::Blocking => new HtmlString(e($guidance)
                .'<br>'.SalesBoardIssuePresenter::toHtml($assessment->readiness->blockingIssueCounts())->toHtml()),
            SalesBoardStaleImpact::SourceOnly => $published ? $guidance : $assessment->message().' '.$guidance,
            default => $assessment->message().' '.$guidance,
        };

        $warnings = $assessment->readiness->warningCounts();

        if ($warnings !== []) {
            $body = new HtmlString(($body instanceof HtmlString ? $body->toHtml() : e($body))
                .'<br><br>Avisos da fonte atual (não impedem a apuração):<br>'
                .SalesBoardIssuePresenter::toHtml($warnings)->toHtml());
        }

        $notification = Notification::make()
            ->title($assessment->impact->label())
            ->body($body);

        return match ($assessment->impact) {
            SalesBoardStaleImpact::None => $notification->success(),
            SalesBoardStaleImpact::SourceOnly => $notification->info(),
            SalesBoardStaleImpact::Material => $notification->warning(),
            SalesBoardStaleImpact::Blocking => $notification->danger(),
        };
    }
}
