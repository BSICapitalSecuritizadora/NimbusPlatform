<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardCycleReopeningException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardCycleReopeningService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Support\BusinessTime;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Reabrir competência": devolve a competência cancelada a "Gerado".
 *
 * É da Gestão, como cancelar, e segue o mesmo portão de superfície: aparece
 * para quem conduz a competência, de um lado ou do outro
 * ({@see SalesBoardCycleResource::canOperateOrApprove()}). A Gestão o encontra
 * habilitado; quem opera vê o botão desabilitado, dizendo por quê, em vez de
 * não encontrá-lo -- é para ele que a tela de cancelamento, o "Congelar
 * competência" e o comando apontam. Quem só consulta não o vê. O serviço
 * confere a permissão de novo, e todas as recusas de domínio.
 */
class ReopenSalesBoardCycleAction
{
    public static function make(string $name = 'reopenCompetence'): Action
    {
        return Action::make($name)
            ->label('Reabrir competência')
            ->icon('heroicon-o-arrow-uturn-up')
            ->color('warning')
            ->outlined()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Reabrir a competência cancelada')
            ->modalDescription(fn (SalesBoardCycle $record): string => self::description($record))
            ->modalSubmitActionLabel('Reabrir competência')
            ->modalCancelActionLabel('Voltar')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && ($record->status === SalesBoardCycleStatus::Cancelled))
            ->disabled(fn (): bool => ! SalesBoardCycleResource::canApprove())
            ->tooltip(fn (): ?string => SalesBoardCycleResource::canApprove()
                ? null
                : 'Reabrir a competência é da Gestão: exige a permissão de aprovação do Quadro de Vendas.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da reabertura')
                    ->helperText('Fica registrado na competência, com o seu nome, e na auditoria.')
                    ->required()
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (SalesBoardCycle $record, array $data): void {
                try {
                    app(SalesBoardCycleReopeningService::class)->reopen($record, auth()->user(), (string) $data['reason']);
                } catch (SalesBoardCycleReopeningException|AuthorizationException $exception) {
                    Notification::make()
                        ->title('Não foi possível reabrir a competência')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title('Competência reaberta')
                    ->body('O ciclo voltou a “Gerado”. Próximo passo: conferir a fonte e enviar para a validação da construtora.')
                    ->success()
                    ->send();
            });
    }

    private static function description(SalesBoardCycle $record): string
    {
        return sprintf(
            'A competência %s de %s volta a “Gerado” sobre a versão %s e segue o fluxo normal: conferir a fonte (recalcular se ela mudou), '
                .'validação da construtora e análise da Gestão. O cancelamento de %s por %s continua registrado, e as rodadas '
                .'substituídas continuam no histórico.',
            $record->reference_month?->format('m/Y') ?? '—',
            (string) $record->construction?->development_name,
            $record->currentBaseline?->versionLabel() ?? '—',
            $record->cancelled_at === null ? '—' : BusinessTime::at($record->cancelled_at)->format('d/m/Y'),
            $record->cancelledBy?->name ?? 'usuário não registrado',
        );
    }
}
