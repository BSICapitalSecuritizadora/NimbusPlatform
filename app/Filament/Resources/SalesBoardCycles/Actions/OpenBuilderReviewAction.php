<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardBuilderReviewOpeningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Enviar para validação da construtora": abre a rodada sobre a versão vigente.
 *
 * Só aparece enquanto a competência ainda não está com a Gestão. Antes de criar
 * qualquer coisa, o serviço confere a posição contra a fonte de agora -- mandar
 * para a construtora um quadro que já se sabe desatualizado custa uma ida e
 * volta inteira, e a mensagem de recusa diz exatamente isso.
 *
 * "Enviar" é o passo do fluxo, não um envio do sistema: a validação é aberta
 * aqui dentro, a posição vai à construtora pelo canal combinado, e a resposta
 * dela volta anexada ao envio da validação.
 */
class OpenBuilderReviewAction
{
    public static function make(string $name = 'openBuilderReview'): Action
    {
        return Action::make($name)
            /**
             * Com a competência já em validação, o mesmo clique abre a rodada em
             * andamento -- ou a próxima, se a anterior foi substituída por
             * recálculo. O rótulo acompanha: "enviar" de novo sugeriria um
             * segundo envio que não acontece.
             */
            ->label(fn (SalesBoardCycle $record): string => $record->status === SalesBoardCycleStatus::BuilderReview
                ? 'Abrir validação da construtora'
                : 'Enviar para validação da construtora')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Abrir a validação da construtora')
            ->modalDescription(fn (SalesBoardCycle $record): string => $record->status === SalesBoardCycleStatus::BuilderReview
                ? 'Abre a rodada de validação em andamento. Se a posição foi recalculada, uma nova rodada é aberta sobre a versão vigente.'
                : 'Abre a validação interna da competência. Envie a posição congelada à construtora pelo canal combinado (e-mail, reunião) e registre aqui a resposta dela, com o anexo. O Nimbus ainda não tem acesso externo para a construtora: nada é enviado automaticamente.')
            ->modalSubmitActionLabel('Abrir validação')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canRecalculate()
                && in_array($record->status, [SalesBoardCycleStatus::Generated, SalesBoardCycleStatus::BuilderReview], true)
                && ($record->current_baseline_id !== null))
            ->action(function (SalesBoardCycle $record) {
                try {
                    $review = app(SalesBoardBuilderReviewOpeningService::class)->open($record, auth()->user());
                } catch (SalesBoardBuilderReviewException $exception) {
                    Notification::make()
                        ->title('Não foi possível abrir a validação')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                Notification::make()
                    ->title('Validação aberta')
                    ->body(sprintf('%s sobre a %s.', $review->attemptLabel(), $review->baseline?->versionLabel() ?? 'versão vigente'))
                    ->success()
                    ->send();

                return redirect(BuilderReviewWorkspace::getUrl(['record' => $record]));
            });
    }
}
