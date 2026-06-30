<?php

namespace App\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
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
            ->modifyQueryUsing(fn ($query) => $query->latest('created_at'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data / Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Autor')
                    ->placeholder('Sistema'),
                TextColumn::make('description')
                    ->label('Ação Executada')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('properties')
                    ->label('Detalhes Adicionais')
                    ->formatStateUsing(function ($state, $record) {
                        $props = $record->properties;
                        if (! $props) {
                            return '—';
                        }

                        $lines = [];
                        if (isset($props['attributes'])) {
                            foreach ($props['attributes'] as $key => $value) {
                                // Skip generic fields
                                if (in_array($key, ['created_at', 'updated_at', 'id'])) {
                                    continue;
                                }
                                $lines[] = "{$key}: ".(is_array($value) ? json_encode($value) : $value);
                            }
                        }

                        return count($lines) > 0 ? implode('<br>', $lines) : '—';
                    })
                    ->html()
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
