<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->url(fn (\App\Models\Document $record): ?string => $record->file_path
                        ? \Illuminate\Support\Facades\Storage::disk(config('filesystems.default') === 'local' ? 'public' : config('filesystems.default'))->url($record->file_path)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (\App\Models\Document $record): bool => (bool) $record->file_path),
            ])
            ->bulkActions([
                // Remove delete for history retention
            ])
            ->defaultSort('version', 'desc');
    }
}
