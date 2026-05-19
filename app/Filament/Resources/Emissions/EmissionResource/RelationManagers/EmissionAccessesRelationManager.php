<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Models\EmissionAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmissionAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'accesses';

    protected static ?string $title = 'Consultas públicas';

    protected static ?string $modelLabel = 'Consulta pública';

    protected static ?string $pluralModelLabel = 'Consultas públicas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('emission'))
            ->recordTitleAttribute('requester_name')
            ->columns([
                TextColumn::make('requester_name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester_email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('requester_phone')
                    ->label('Telefone')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('emission.if_code')
                    ->label('Código IF')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (EmissionAccess $record): string => $record->status_color),
                TextColumn::make('sent_at')
                    ->label('Solicitado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->label('Validado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_accessed_at')
                    ->label('Último acesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma consulta pública registrada');
    }
}
