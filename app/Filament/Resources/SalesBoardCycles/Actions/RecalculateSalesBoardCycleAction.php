<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

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
            /**
             * Aprovado e cancelado são finais: o serviço recusa o recálculo. O
             * botão continua à vista, desabilitado e dizendo por quê, em vez de
             * abrir o modal, apurar a prévia e só então recusar. A recusa do
             * serviço continua sendo a autoridade.
             */
            ->disabled(fn (SalesBoardCycle $record): bool => self::finalStatusReason($record) !== null)
            ->tooltip(fn (SalesBoardCycle $record): ?string => self::finalStatusReason($record))
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

                /**
                 * A recusa por versão alterada no meio do caminho também traz a
                 * prontidão; só a recusa sobre a versão que o operador viu lista
                 * os bloqueios, para não esconder a mensagem de concorrência.
                 */
                $sameVersion = (int) $result->previousBaseline->getKey() === (int) $record->current_baseline_id;
                $blockers = ($result->isBlocked() && $sameVersion) ? $result->readiness->blockingIssueCounts() : [];

                // Versão nova e ciclo devolvido à construtora aparecem já nesta resposta.
                $record->refresh();

                $notification = Notification::make()
                    ->title($result->outcome->label())
                    ->body($blockers === []
                        ? $result->message()
                        : new HtmlString('A fonte atual está incompleta e não permite uma versão nova.<br>'
                            .SalesBoardIssuePresenter::toHtml($blockers)->toHtml()));

                match ($result->outcome) {
                    SalesBoardRecalculationOutcome::Recalculated => $notification->success(),
                    SalesBoardRecalculationOutcome::Unchanged => $notification->info(),
                    SalesBoardRecalculationOutcome::Blocked => $notification->danger(),
                };

                $notification->send();
            });
    }

    private static function finalStatusReason(SalesBoardCycle $record): ?string
    {
        return match ($record->status) {
            SalesBoardCycleStatus::Approved => 'Competência aprovada e publicada: a posição publicada é imutável e não é recalculada.',
            SalesBoardCycleStatus::Cancelled => 'Ciclo cancelado: não há recálculo.',
            default => null,
        };
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
