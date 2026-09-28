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
 * É da Gestão, como aprovar. Quem opera a competência vê o botão desabilitado,
 * dizendo por quê, em vez de não encontrá-lo -- a mensagem do portão de
 * publicação manda usar este botão. O serviço confere a permissão de novo.
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
                'A competência %s de %s é encerrada sem publicação. As rodadas abertas de validação e de análise são substituídas, '
                    .'a automação deixa de responder por ela e nenhum outro ciclo pode ser gerado para o mesmo mês. '
                    .'Tudo o que foi apurado continua consultável. Esta ação não pode ser desfeita.',
                $record->reference_month?->format('m/Y') ?? '—',
                (string) $record->construction?->development_name,
            ))
            ->modalSubmitActionLabel('Cancelar competência')
            ->modalCancelActionLabel('Voltar')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canRecalculate()
                && ! in_array($record->status, [SalesBoardCycleStatus::Approved, SalesBoardCycleStatus::Cancelled], true))
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
                    ->body('O ciclo foi encerrado sem publicação e a automação deixou de responder por esta competência.')
                    ->success()
                    ->send();
            });
    }
}
