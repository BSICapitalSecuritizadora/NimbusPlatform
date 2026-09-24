<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por título, categoria, arquivo ou série...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum documento encontrado')
            ->emptyStateDescription('Não há documentos cadastrados que correspondam à busca ou aos filtros aplicados.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->searchable()
                    ->sortable()
                    ->description(fn (Document $record): ?string => $record->version > 1 ? "Versão {$record->version}" : null)
                    ->tooltip(fn (Document $record): string => $record->title),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->formatStateUsing(fn (?string $state): string => Document::CATEGORY_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'societarios', 'governanca' => 'info',
                        'fatos_relevantes', 'anuncios' => 'warning',
                        'demonstracoes_financeiras', 'relatorios_anuais' => 'primary',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('emissions.name')
                    ->label('Séries')
                    ->badge()
                    ->separator(',')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->tooltip(fn (Document $record): ?string => $record->emissions->isNotEmpty() ? $record->emissions->pluck('name')->implode(', ') : null),

                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->icon('heroicon-o-paper-clip')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(26)
                    ->searchable()
                    ->tooltip(fn (Document $record): ?string => $record->file_name)
                    ->description(fn (Document $record): ?string => $record->mime_type ? strtoupper(pathinfo($record->file_name ?? '', PATHINFO_EXTENSION) ?: (explode('/', $record->mime_type)[1] ?? '')) : null)
                    ->toggleable(),

                TextColumn::make('file_size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn ($state): string => $state ? Number::fileSize($state) : '—')
                    ->alignEnd()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('storage_disk')
                    ->label('Disco')
                    ->formatStateUsing(fn (?string $state, Document $record): string => $state ?: $record->resolved_storage_disk)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('workflow_status')
                    ->label('Status')
                    ->state(function (Document $record): string {
                        if (! $record->is_published) {
                            return 'Rascunho';
                        }
                        if ($record->is_published && ! $record->is_public) {
                            return 'Publicado';
                        }

                        return 'Público';
                    })
                    ->badge()
                    ->icon(fn (string $state): string => match ($state) {
                        'Rascunho' => 'heroicon-m-pencil-square',
                        'Publicado' => 'heroicon-m-check-circle',
                        'Público' => 'heroicon-m-globe-americas',
                        default => 'heroicon-m-document',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Rascunho' => 'gray',
                        'Publicado' => 'info',
                        'Público' => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: function ($query, string $direction) {
                        return $query->orderBy('is_published', $direction)->orderBy('is_public', $direction);
                    }),

                IconColumn::make('is_public')
                    ->label('Site')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->label('Publicado em')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Document $record): ?string => $record->published_at?->diffForHumans())
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('publisher.name')
                    ->label('Publicado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Document $record): ?string => $record->created_at?->diffForHumans())
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

                Action::make('new_version')
                    ->label('Nova Versão')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->visible(fn (Document $record): bool => auth()->user()->can('documents.update') && ! $record->replaced_at)
                    ->form([
                        FileUpload::make('file_path')
                            ->label('Novo Arquivo')
                            ->required()
                            ->disk('local')
                            ->visibility('private')
                            ->directory('documents')
                            ->acceptedFileTypes((array) config('uploads.document.allowed_mimes', []))
                            ->maxSize((int) config('uploads.document.max_kb', 102400))
                            ->helperText('Tamanho máximo por arquivo: '.(int) ceil(config('uploads.document.max_kb', 102400) / 1024).' MB.')
                            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                                $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file->getClientOriginalName());

                                return now()->format('Y/m/').uniqid().'_'.$safe;
                            })
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if ($state instanceof TemporaryUploadedFile) {
                                    $set('file_name', $state->getClientOriginalName());
                                    $set('storage_disk', 'local');
                                }
                            }),
                        // `mime_type` e `file_size` são derivados do arquivo em
                        // disco no `saving` do model, não enviados pelo formulário.
                        Hidden::make('file_name'),
                        Hidden::make('storage_disk')
                            ->default(Document::defaultStorageDisk()),
                    ])
                    ->action(function (Document $record, array $data): void {
                        $newVersion = $record->replicate([
                            'file_path',
                            'file_name',
                            'mime_type',
                            'file_size',
                            'checksum',
                            'storage_disk',
                            'version',
                            'parent_document_id',
                            'replaced_at',
                            'published_at',
                            'published_by',
                        ]);
                        $newVersion->file_path = $data['file_path'];
                        $newVersion->file_name = $data['file_name'] ?? null;
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

                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.update')),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('documents.delete')),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(Document::CATEGORY_OPTIONS)
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('emissions')
                    ->label('Série')
                    ->relationship('emissions', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                Filter::make('rascunho')
                    ->label('Rascunho')
                    ->query(fn (Builder $query) => $query->where('is_published', false)),

                Filter::make('publicado')
                    ->label('Publicado')
                    ->query(fn (Builder $query) => $query->where('is_published', true)->where('is_public', false)),

                Filter::make('publico')
                    ->label('Público')
                    ->query(fn (Builder $query) => $query->where('is_published', true)->where('is_public', true)),
            ]);
    }
}
