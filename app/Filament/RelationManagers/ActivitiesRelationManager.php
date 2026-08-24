<?php

namespace App\Filament\RelationManagers;

use App\Support\ActivityLog\ActivityPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Histórico da Operação (Linha do Tempo)';

    protected static ?string $modelLabel = 'Atividade';

    protected static ?string $pluralModelLabel = 'Atividades';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer')->latest('created_at')->latest('id'))
            ->columns([])
            ->content(view('filament.tables.activity-timeline'))
            ->description('Registro cronológico das principais movimentações e alterações da operação.')
            ->filters([
                SelectFilter::make('event')
                    ->label('Tipo de evento')
                    ->options(fn (): array => $this->getOwnerRecord()->activities()
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event')
                        ->mapWithKeys(fn (string $event): array => [$event => ActivityPresenter::eventLabel($event)])
                        ->all()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading('Nenhuma movimentação registrada')
            ->emptyStateDescription('As movimentações e alterações desta operação aparecerão aqui em ordem cronológica.')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
