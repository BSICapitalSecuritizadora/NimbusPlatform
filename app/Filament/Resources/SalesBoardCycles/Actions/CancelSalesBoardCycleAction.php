<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardCycleCancellationException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Cancelar competência": encerra um ciclo que não vai ser publicado.
 *
 * É da Gestão, como aprovar. Aparece para quem conduz a competência, de um lado
 * ou do outro: a Gestão sem `sales-boards.update` o encontra habilitado, e quem
 * opera a competência vê o botão desabilitado, dizendo por quê, em vez de não
 * encontrá-lo -- a mensagem do portão de publicação manda usar este botão.
 * Quem só consulta não o vê: seria um botão sem saída. O serviço confere a
 * permissão de novo.
 *
 * O cancelamento tem volta: "Reabrir competência"
 * ({@see ReopenSalesBoardCycleAction}) devolve o mesmo ciclo a "Gerado".
 */
class CancelSalesBoardCycleAction
{
    public static function make(string $name = 'cancelCompetence'): Action
    {
        return Action::make($name)
            ->label('Cancelar competência')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->outlined()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Cancelar a competência')
            ->modalDescription(fn (SalesBoardCycle $record): string => sprintf(
                'A competência %s de %s é encerrada sem publicação. As rodadas abertas de validação e de análise são substituídas '
                    .'e a automação deixa de responder por ela. Tudo o que foi apurado continua consultável. Se o cancelamento se '
                    .'mostrar um engano, a Gestão pode reabrir a mesma competência depois, com motivo, enquanto ela estiver coberta '
                    .'pela automação da Emissão e nenhuma competência posterior tiver sido publicada.',
                $record->reference_month?->format('m/Y') ?? '—',
                (string) $record->construction?->development_name,
            ))
            ->modalSubmitActionLabel('Cancelar competência')
            ->modalCancelActionLabel('Voltar')
            /**
             * Competência com publicação não é cancelada, nem a que está em
             * retificação (voltou a "Gerado" com a posição publicada valendo):
             * a saída dela é "Desistir da retificação".
             */
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && ! in_array($record->status, [SalesBoardCycleStatus::Approved, SalesBoardCycleStatus::Cancelled], true)
                && ! $record->hasPublication())
            ->disabled(fn (): bool => ! SalesBoardCycleResource::canApprove())
            ->tooltip(fn (): ?string => SalesBoardCycleResource::canApprove()
                ? null
                : 'Cancelar a competência é da Gestão: exige a permissão de aprovação do Quadro de Vendas.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo do cancelamento')
                    ->helperText('Fica registrado na competência, com o seu nome, e na auditoria.')
                    ->required()
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (SalesBoardCycle $record, array $data): void {
                try {
                    app(SalesBoardCycleCancellationService::class)->cancel($record, auth()->user(), (string) $data['reason']);
                } catch (SalesBoardCycleCancellationException|AuthorizationException $exception) {
                    Notification::make()
                        ->title('Não foi possível cancelar a competência')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title('Competência cancelada')
                    ->body('O ciclo foi encerrado sem publicação e a automação deixou de responder por esta competência. '
                        .'Para desfazer, use “Reabrir competência”.')
                    ->success()
                    ->send();
            });
    }
}
