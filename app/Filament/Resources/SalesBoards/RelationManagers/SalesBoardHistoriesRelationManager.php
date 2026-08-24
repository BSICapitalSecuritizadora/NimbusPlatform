<?php

namespace App\Filament\Resources\SalesBoards\RelationManagers;

use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesBoardHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'valueHistories';

    protected static ?string $title = 'Histórico de Valores';

    protected static ?string $modelLabel = 'Histórico de Valores';

    protected static ?string $pluralModelLabel = 'Histórico de Valores';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Ids of the latest version of each competence. History rows are only ever
     * appended, so the highest id per competence is the position in force.
     *
     * @var list<int>|null
     */
    protected ?array $versionsInForce = null;

    /**
     * @return list<int>
     */
    protected function versionsInForce(): array
    {
        return $this->versionsInForce ??= SalesBoardHistory::query()
            ->where('sales_board_id', $this->getOwnerRecord()->getKey())
            ->groupBy('reference_month')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (SalesBoardHistory $record): ?string => match (true) {
                        $record->is_initial => 'Início da Operação',
                        in_array($record->getKey(), $this->versionsInForce(), true) => 'Vigente',
                        default => null,
                    })
                    ->color(fn (?string $state): string => $state === 'Início da Operação' ? 'warning' : 'success')
                    ->placeholder('—'),
                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->sortable(),
                TextColumn::make('changedBy.name')
                    ->label('Alterado por')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('change_reason')
                    ->label('Motivo')
                    ->alignCenter()
                    ->icon(fn (?string $state): ?string => filled($state) ? 'heroicon-o-chat-bubble-left-ellipsis' : null)
                    ->color('warning')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? 'Alteração justificada — clique em "Ver motivo".' : null),
                TextColumn::make('stock_units')
                    ->label('Estoque')
                    ->sortable(),
                TextColumn::make('financed_units')
                    ->label('Financiado')
                    ->sortable(),
                TextColumn::make('paid_units')
                    ->label('Quitado')
                    ->sortable(),
                TextColumn::make('exchanged_units')
                    ->label('Permutado')
                    ->sortable(),
                TextColumn::make('total_units')
                    ->label('Quantidade Total')
                    ->sortable(),
                TextColumn::make('stock_value')
                    ->label('Valor em estoque')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('financed_value')
                    ->label('Valor financiado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('paid_value')
                    ->label('Valor quitado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('exchanged_value')
                    ->label('Valor permutado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([
                Action::make('viewChangeReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Motivo da alteração')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (SalesBoardHistory $record): bool => $record->hasChangeReason())
                    ->schema(fn (SalesBoardHistory $record): array => [
                        TextEntry::make('changed_by')
                            ->label('Alterado por')
                            ->state($record->changedBy?->name ?? 'Não identificado'),
                        TextEntry::make('changed_at')
                            ->label('Data')
                            ->state($record->created_at !== null
                                ? $record->created_at->format('d/m/Y').' às '.$record->created_at->format('H:i')
                                : '—'),
                        TextEntry::make('competence')
                            ->label('Competência')
                            ->state(SalesBoard::formatReferenceMonthForDisplay($record->reference_month)),
                        TextEntry::make('change_reason')
                            ->label('Motivo da alteração')
                            ->state((string) $record->change_reason)
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhum histórico de valores registrado');
    }
}
