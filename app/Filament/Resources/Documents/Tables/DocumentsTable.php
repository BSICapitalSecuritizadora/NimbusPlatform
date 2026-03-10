<?php

namespace App\Filament\Resources\Documents\Tables;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->searchable(),

                TextColumn::make('emissions.name')
                    ->label('Séries')
                    ->badge()
                    ->separator(','),

                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->toggleable(),

                TextColumn::make('file_size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn ($state): string => $state ? Number::fileSize($state) : '—')
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label('Publicado')
                    ->boolean(),

                IconColumn::make('is_public')
                    ->label('Público')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('new_version')
                    ->label('Nova Versão')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->visible(fn (\App\Models\Document $record): bool => auth()->user()->can('documents.update') && ! $record->replaced_at)
                    ->form([
                        \Filament\Forms\Components\FileUpload::make('file_path')
                            ->label('Novo Arquivo')
                            ->required()
                            ->disk(config('filesystems.default'))
                            ->directory('documents')
                            ->getUploadedFileNameForStorageUsing(function (\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file): string {
                                $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file->getClientOriginalName());

                                return now()->format('Y/m/').uniqid().'_'.$safe;
                            })
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if ($state instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                    $set('file_name', $state->getClientOriginalName());
                                    $set('mime_type', $state->getMimeType());
                                    $set('file_size', $state->getSize());
                                }
                            }),
                        \Filament\Forms\Components\Hidden::make('file_name'),
                        \Filament\Forms\Components\Hidden::make('mime_type'),
                        \Filament\Forms\Components\Hidden::make('file_size'),
                    ])
                    ->action(function (\App\Models\Document $record, array $data): void {
                        // Create the new version
                        $newVersion = $record->replicate(['file_path', 'file_name', 'mime_type', 'file_size', 'version', 'parent_document_id', 'replaced_at']);
                        $newVersion->file_path = $data['file_path'];
                        $newVersion->file_name = $data['file_name'] ?? null;
                        $newVersion->mime_type = $data['mime_type'] ?? null;
                        $newVersion->file_size = $data['file_size'] ?? null;

                        $newVersion->version = $record->version + 1;
                        $newVersion->parent_document_id = $record->parent_document_id ?? $record->id;
                        $newVersion->is_published = false;
                        $newVersion->save();

                        // Attach the same relations to the new document
                        $newVersion->emissions()->sync($record->emissions->pluck('id'));
                        $newVersion->investors()->sync($record->investors->pluck('id'));

                        // Archive the old version
                        $record->update([
                            'is_published' => false,
                            'replaced_at' => now(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Nova versão criada com sucesso')
                            ->success()
                            ->send();
                    }),

                Action::make('download')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record): ?string => $record->file_path
                        ? Storage::disk('public')->url($record->file_path)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => (bool) $record->file_path),

                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.update')),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.delete')),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Publicado'),

                TernaryFilter::make('is_public')
                    ->label('Público'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
