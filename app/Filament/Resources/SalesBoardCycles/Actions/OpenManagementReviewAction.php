<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardManagementReviewOpeningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Análise da Gestão": abre a rodada sobre a submissão da construtora.
 *
 * A abertura é idempotente -- clicar de novo devolve a mesma análise -- e é o
 * que materializa as pendências a decidir. Ela não acontece sozinha quando a
 * construtora envia: fazer a submissão criar estado da Gestão acoplaria as duas
 * fases e produziria uma análise que ninguém pediu, ocupando a fila de trabalho
 * de todo mês que a construtora fechasse.
 *
 * Só aparece quando a competência está com a Gestão. Antes disso não há
 * submissão para analisar; depois, a análise já existe e a própria tela é o
 * caminho.
 *
 * A Gestão também abre a análise, e não só quem opera: com `sales-boards.view`
 * e `sales-boards.approve` ela decidia e aprovava uma análise já aberta, mas
 * dependia de um operador para abri-la. Abrir não é preparo de dado -- é o que
 * materializa as pendências que ela mesma vai decidir -- e não fura o
 * maker/checker, que compara o aprovador com quem enviou a validação. O serviço
 * confere a mesma regra.
 */
class OpenManagementReviewAction
{
    public static function make(string $name = 'openManagementReview'): Action
    {
        return Action::make($name)
            ->label('Análise da Gestão')
            ->icon('heroicon-o-scale')
            ->color('primary')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && ($record->status === SalesBoardCycleStatus::ManagementReview)
                && ($record->current_baseline_id !== null))
            ->action(function (SalesBoardCycle $record) {
                try {
                    $review = app(SalesBoardManagementReviewOpeningService::class)->open($record, auth()->user());
                } catch (SalesBoardManagementReviewException $exception) {
                    Notification::make()
                        ->title('Não foi possível abrir a análise')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                return redirect(ManagementReviewWorkspace::getUrl([
                    'record' => $record,
                    'review' => $review->getKey(),
                ]));
            });
    }
}
