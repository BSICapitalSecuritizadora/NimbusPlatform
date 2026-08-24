<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Histórico de versões';

    protected static ?string $modelLabel = 'Versão';

    protected static ?string $pluralModelLabel = 'Versões';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->heading('Histórico de versões')
            ->description('Acompanhe arquivos anteriores e substituições realizadas neste documento.')
            ->emptyStateHeading('Nenhuma versão anterior')
            ->emptyStateDescription('Este documento ainda não teve seu arquivo substituído.')
            ->emptyStateIcon('heroicon-o-clock')
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->columns([
                TextColumn::make('version')
                    ->label('Versão')
                    ->formatStateUsing(fn ($state): string => "v{$state}")
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->tooltip(fn (Document $record): string => $record->title),

                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->icon('heroicon-o-paper-clip')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(28)
                    ->tooltip(fn (Document $record): ?string => $record->file_name),

                TextColumn::make('storage_disk')
                    ->label('Disco')
                    ->formatStateUsing(fn (?string $state, Document $record): string => ucfirst($state ?: $record->resolved_storage_disk))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('replaced_at')
                    ->label('Substituído em')
                    ->dateTime('d/m/Y · H:i')
                    ->description(fn (Document $record): ?string => $record->replaced_at?->diffForHumans())
                    ->sortable(),
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
                    ->color('primary')
                    ->url(fn (Document $record): ?string => $record->file_path
                        ? route('admin.documents.download', $record)
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
