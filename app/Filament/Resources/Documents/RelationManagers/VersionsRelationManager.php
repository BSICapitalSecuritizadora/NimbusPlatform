<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('version')
                    ->label('Versão')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('title')
                    ->label('Título'),
                TextColumn::make('file_name')
                    ->label('Arquivo'),
                TextColumn::make('storage_disk')
                    ->label('Disco')
                    ->formatStateUsing(fn (?string $state, Document $record): string => $state ?: $record->resolved_storage_disk),
                TextColumn::make('replaced_at')
                    ->label('Substituído Em')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Action::make('download')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): ?string => $record->file_path
                        ? Storage::disk($record->resolved_storage_disk)->url($record->file_path)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => (bool) $record->file_path),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('version', 'desc');
    }
}
