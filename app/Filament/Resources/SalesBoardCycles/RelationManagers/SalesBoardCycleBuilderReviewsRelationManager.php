<?php

namespace App\Filament\Resources\SalesBoardCycles\RelationManagers;

use App\Enums\BuilderReviewerType;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Models\SalesBoardBuilderReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * O histórico de validações da competência.
 *
 * Somente leitura. Uma rodada substituída continua aqui, com tudo o que a
 * construtora declarou na época: aquela declaração continua verdadeira sobre a
 * versão a que se referia, e escondê-la apagaria a explicação de por que a
 * competência voltou à construtora.
 */
class SalesBoardCycleBuilderReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'builderReviews';

    protected static ?string $title = 'Validações da construtora';

    protected static ?string $modelLabel = 'Validação';

    protected static ?string $pluralModelLabel = 'Validações';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clipboard-document-check';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('attempt')
            ->description('Cada rodada é registrada por inteiro. Nova versão material da posição exige nova validação.')
            ->defaultSort('attempt', 'desc')
            ->emptyStateHeading('Nenhuma validação aberta')
            ->emptyStateDescription('A competência ainda não foi enviada à construtora.')
            ->columns([
                TextColumn::make('attempt')
                    ->label('Rodada')
                    ->formatStateUsing(fn (int $state): string => 'Tentativa '.$state)
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('baseline.version')
                    ->label('Versão validada')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'V'.$state)
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardBuilderReviewStatus $state): string => $state->label())
                    ->color(fn (SalesBoardBuilderReviewStatus $state): string => $state->color()),

                TextColumn::make('progress')
                    ->label('Progresso')
                    ->state(fn (SalesBoardBuilderReview $record): string => sprintf(
                        '%d de %d seções',
                        $record->progress()['resolved'],
                        $record->progress()['total'],
                    ))
                    ->alignCenter(),

                TextColumn::make('divergences_count')
                    ->label('Divergências')
                    ->counts('divergences')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success')
                    ->alignCenter(),

                TextColumn::make('reviewer_name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->description(fn (SalesBoardBuilderReview $record): ?string => $record->reviewer_type === null
                        ? null
                        : BuilderReviewerType::tryFrom($record->reviewer_type)?->label()),

                TextColumn::make('submitted_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('superseded_at')
                    ->label('Substituída em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                Action::make('openWorkspace')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (SalesBoardBuilderReview $record): string => BuilderReviewWorkspace::getUrl([
                        'record' => $record->sales_board_cycle_id,
                    ]).'?review='.$record->getKey()),
            ])
            ->toolbarActions([])
            ->extraAttributes(['class' => 'bsi-cycle-toolbar-end']);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
