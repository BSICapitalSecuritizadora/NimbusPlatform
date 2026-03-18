<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
                    ->formatStateUsing(fn (?string $state): string => Document::CATEGORY_OPTIONS[$state] ?? (string) $state)
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

                TextColumn::make('storage_disk')
                    ->label('Disco')
                    ->formatStateUsing(fn (?string $state, Document $record): string => $state ?: $record->resolved_storage_disk)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('workflow_status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Rascunho' => 'gray',
                        'Publicado' => 'info',
                        'Público' => 'success',
                        default => 'gray',
                    }),

                IconColumn::make('is_public')
                    ->label('Site')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->label('Publicado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('publisher.name')
                    ->label('Publicado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Action::make('new_version')
                    ->label('Nova Versão')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->visible(fn (Document $record): bool => auth()->user()->can('documents.update') && ! $record->replaced_at)
                    ->form([
                        FileUpload::make('file_path')
                            ->label('Novo Arquivo')
                            ->required()
                            ->disk(Document::defaultStorageDisk())
                            ->directory('documents')
                            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                                $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file->getClientOriginalName());

                                return now()->format('Y/m/').uniqid().'_'.$safe;
                            })
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if ($state instanceof TemporaryUploadedFile) {
                                    $set('file_name', $state->getClientOriginalName());
                                    $set('mime_type', $state->getMimeType());
                                    $set('file_size', $state->getSize());
                                    $set('storage_disk', Document::defaultStorageDisk());
                                }
                            }),
                        Hidden::make('file_name'),
                        Hidden::make('mime_type'),
                        Hidden::make('file_size'),
                        Hidden::make('storage_disk')
                            ->default(Document::defaultStorageDisk()),
                    ])
                    ->action(function (Document $record, array $data): void {
                        $newVersion = $record->replicate([
                            'file_path',
                            'file_name',
                            'mime_type',
                            'file_size',
                            'storage_disk',
                            'version',
                            'parent_document_id',
                            'replaced_at',
                            'published_at',
                            'published_by',
                        ]);
                        $newVersion->file_path = $data['file_path'];
                        $newVersion->file_name = $data['file_name'] ?? null;
                        $newVersion->mime_type = $data['mime_type'] ?? null;
                        $newVersion->file_size = $data['file_size'] ?? null;
                        $newVersion->storage_disk = $data['storage_disk'] ?? Document::defaultStorageDisk();
                        $newVersion->version = $record->version + 1;
                        $newVersion->parent_document_id = $record->parent_document_id ?? $record->id;
                        $newVersion->is_published = false;
                        $newVersion->published_at = null;
                        $newVersion->published_by = null;
                        $newVersion->save();

                        $newVersion->emissions()->sync($record->emissions->pluck('id'));
                        $newVersion->investors()->sync($record->investors->pluck('id'));

                        $record->update([
                            'is_published' => false,
                            'replaced_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Nova versão criada com sucesso')
                            ->success()
                            ->send();
                    }),

                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-circle')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar Documento')
                    ->modalDescription('Tem certeza que deseja publicar este documento para os investidores vinculados?')
                    ->modalSubmitActionLabel('Sim, publicar')
                    ->action(function (Document $record): void {
                        $record->update([
                            'is_published' => true,
                            'published_at' => $record->published_at ?? now(),
                            'published_by' => $record->published_by ?? auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Documento publicado!')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Document $record): bool => ! $record->is_published && auth()->user()->can('documents.update')),

                Action::make('make_public')
                    ->label('Tornar Público')
                    ->icon('heroicon-o-globe-americas')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Tornar Documento Público')
                    ->modalDescription('Tem certeza que deseja deixar este documento aberto para o público em geral?')
                    ->modalSubmitActionLabel('Sim, tornar público')
                    ->action(function (Document $record): void {
                        $record->update([
                            'is_published' => true,
                            'is_public' => true,
                            'published_at' => $record->published_at ?? now(),
                            'published_by' => $record->published_by ?? auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Documento agora é público!')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Document $record): bool => ! $record->is_public && auth()->user()->can('documents.update')),

                Action::make('download')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): ?string => $record->file_path
                        ? Storage::disk($record->resolved_storage_disk)->url($record->file_path)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => (bool) $record->file_path),

                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.update')),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.delete')),
            ])
            ->filters([
                //
            ])
            ->defaultSort('created_at', 'desc');
    }
}
