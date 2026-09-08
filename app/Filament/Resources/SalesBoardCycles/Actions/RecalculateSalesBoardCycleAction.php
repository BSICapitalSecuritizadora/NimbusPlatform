<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardRecalculationOutcome;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;

/**
 * "Recalcular posição": cria a próxima versão a partir da fonte atual.
 *
 * O motivo é obrigatório e a confirmação mostra, antes, o que mudaria. Uma
 * versão financeira nova sem justificativa é uma alteração que ninguém consegue
 * explicar depois -- e confirmar sem ver o diff seria assinar em branco.
 *
 * Se nada mudou, o serviço devolve no-op e nenhuma versão nasce: clicar no botão
 * não é motivo para existir uma V2 idêntica à V1.
 */
class RecalculateSalesBoardCycleAction
{
    public static function make(string $name = 'recalculate'): Action
    {
        return Action::make($name)
            ->label('Recalcular posição')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Recalcular a posição da competência')
            ->modalDescription(fn (SalesBoardCycle $record): string => self::preview($record))
            ->modalSubmitActionLabel('Criar nova versão')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canRecalculate()
                && ($record->current_baseline_id !== null))
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo do recálculo')
                    ->helperText('Fica gravado na nova versão. Descreva o que mudou na fonte e por que a posição precisa ser refeita.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (SalesBoardCycle $record, array $data): void {
                $result = app(SalesBoardRecalculationService::class)->recalculate(
                    cycle: $record,
                    actor: auth()->user(),
                    reason: (string) $data['reason'],
                    expectedBaselineId: (int) $record->current_baseline_id,
                );

                $notification = Notification::make()
                    ->title($result->outcome->label())
                    ->body($result->message());

                match ($result->outcome) {
                    SalesBoardRecalculationOutcome::Recalculated => $notification->success(),
                    SalesBoardRecalculationOutcome::Unchanged => $notification->info(),
                    SalesBoardRecalculationOutcome::Blocked => $notification->danger(),
                };

                $notification->send();
            });
    }

    /**
     * O que o operador vê antes de confirmar: situação da fonte, prontidão e o
     * resumo do que mudaria.
     */
    private static function preview(SalesBoardCycle $record): string
    {
        $assessment = app(SalesBoardStaleDetectionService::class)
            ->assessWithoutPersisting($record, $record->currentBaseline);

        return implode(' ', array_filter([
            sprintf('Versão vigente: %s.', $record->currentBaseline?->versionLabel() ?? '—'),
            $assessment->message(),
            $assessment->readiness->isReady()
                ? null
                : 'Enquanto a fonte estiver incompleta, nenhuma versão nova será criada.',
        ]));
    }
}
