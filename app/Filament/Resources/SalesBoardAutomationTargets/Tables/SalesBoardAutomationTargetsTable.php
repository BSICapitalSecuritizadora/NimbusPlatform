<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets\Tables;

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\SalesBoardAutomationTarget;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * A lista que o time abre para saber o que a automação não conseguiu fazer.
 *
 * A ordenação padrão põe o que precisa de ação no topo: bloqueados e falhos
 * antes de satisfeitos, e dentro disso a competência mais antiga primeiro --
 * porque é a que está parada há mais tempo.
 */
class SalesBoardAutomationTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->formatStateUsing(fn (SalesBoardAutomationTarget $record): string => $record->referenceMonthLabel())
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardAutomationTargetStatus $state): string => $state->label())
                    ->color(fn (SalesBoardAutomationTargetStatus $state): string => $state->color()),

                TextColumn::make('attempt_count')
                    ->label('Tentativas')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('first_attempt_at')
                    ->label('Parado desde')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->visibleFrom('lg'),

                TextColumn::make('next_attempt_at')
                    ->label('Próxima tentativa')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->description(fn (SalesBoardAutomationTarget $record): ?string => $record->isSatisfied()
                        ? null
                        : ((implode(', ', $record->blockerCodes())) ?: null)),

                TextColumn::make('cycle.id')
                    ->label('Ciclo')
                    ->placeholder('—')
                    ->formatStateUsing(fn (mixed $state): string => '#'.$state)
                    ->visibleFrom('md'),
            ])
            ->defaultSort('reference_month')
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(SalesBoardAutomationTargetStatus::cases())
                        ->mapWithKeys(fn (SalesBoardAutomationTargetStatus $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all()),
            ])
            /**
             * Sem ações de linha: não há o que fazer daqui que não seja
             * conduzido pelo scheduler.
             */
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma competência sob automação')
            ->emptyStateDescription(
                'A automação nasce desligada. Enquanto nenhum empreendimento for habilitado, '
                    .'nada é descoberto e nada é gerado.'
            );
    }
}
