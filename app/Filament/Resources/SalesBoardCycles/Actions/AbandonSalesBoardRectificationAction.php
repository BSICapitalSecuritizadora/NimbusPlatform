<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Exceptions\SalesBoardRectificationException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleRectification;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Desistir da retificação": volta a competência à posição publicada.
 *
 * Só aparece com retificação aberta, para quem conduz a competência; é da
 * Gestão, e fica desabilitada sem a permissão de aprovação. As rodadas abertas
 * da retificação são substituídas, o ponteiro volta à versão publicada e o
 * ciclo a "Aprovado" -- a posição publicada nunca mudou. O serviço confere a
 * permissão de novo.
 */
class AbandonSalesBoardRectificationAction
{
    public static function make(string $name = 'abandonRectification'): Action
    {
        return Action::make($name)
            ->label('Desistir da retificação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->outlined()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Desistir da retificação')
            ->modalDescription(fn (SalesBoardCycle $record): string => sprintf(
                'A posição publicada (%s) continua valendo; as versões e rodadas da retificação ficam no histórico. '
                    .'As rodadas abertas de validação e de análise são substituídas.',
                $record->openRectification?->rectifiedPublication?->baseline?->versionLabel() ?? '—',
            ))
            ->modalSubmitActionLabel('Desistir da retificação')
            ->modalCancelActionLabel('Voltar')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && $record->isUnderRectification())
            ->disabled(fn (): bool => ! SalesBoardCycleResource::canApprove())
            ->tooltip(fn (): ?string => SalesBoardCycleResource::canApprove()
                ? null
                : 'Desistir da retificação é da Gestão: exige a permissão de aprovação do Quadro de Vendas.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da desistência')
                    ->helperText('Fica registrado na retificação, com o seu nome, e na auditoria.')
                    ->required()
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (SalesBoardCycle $record, array $data): void {
                $rectification = $record->openRectification()->first();

                try {
                    if (! $rectification instanceof SalesBoardCycleRectification) {
                        throw SalesBoardRectificationException::notOpen();
                    }

                    app(SalesBoardCycleRectificationService::class)->abandon($rectification, auth()->user(), (string) $data['reason']);
                } catch (SalesBoardRectificationException|AuthorizationException $exception) {
                    Notification::make()
                        ->title('Não foi possível desistir da retificação')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();
                $record->unsetRelation('openRectification');

                Notification::make()
                    ->title('Retificação desistida')
                    ->body('A competência voltou à posição publicada e a “Aprovado”. As versões e rodadas da retificação continuam no histórico.')
                    ->success()
                    ->send();
            });
    }
}
