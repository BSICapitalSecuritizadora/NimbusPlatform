<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do documento')
                ->description('Informações cadastrais, classificação, arquivo e regras de visibilidade.')
                ->icon('heroicon-o-document-text')
                ->columns([
                    'default' => 1,
                    'md' => 12,
                ])
                ->schema([
                    TextInput::make('title')
                        ->label('Título')
                        ->placeholder('Ex: Ata da Assembleia Geral Ordinária')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['default' => 12, 'md' => 8]),

                    Select::make('category')
                        ->label('Categoria')
                        ->options(Document::CATEGORY_OPTIONS)
                        ->required()
                        ->searchable()
                        ->preload()
                        ->columnSpan(['default' => 12, 'md' => 4]),

                    // `mime_type` e `file_size` não vêm mais do formulário: são
                    // derivados do arquivo em disco por DerivesStoredFileMetadata.
                    Hidden::make('file_name'),
                    Hidden::make('storage_disk'),

                    // Representação visual e confortável do arquivo atual quando gravado
                    Placeholder::make('current_file_preview')
                        ->label('Arquivo atualmente armazenado')
                        ->content(function (?Document $record): ?HtmlString {
                            if (! $record || ! $record->file_path) {
                                return null;
                            }

                            $fileName = htmlspecialchars($record->file_name ?? basename($record->file_path), ENT_QUOTES, 'UTF-8');
                            $fileSize = $record->file_size ? Number::fileSize($record->file_size) : '';
                            $ext = strtoupper(pathinfo($record->file_name ?? $record->file_path, PATHINFO_EXTENSION) ?: 'PDF');

                            return new HtmlString('
                                <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-4 p-4 rounded-xl bg-gray-500/10 border border-gray-500/20 text-gray-200">
                                    <div class="flex items-center gap-4 min-w-0 flex-1">
                                        <span class="flex-shrink-0 px-3 py-1.5 text-xs font-bold rounded-md bg-amber-500/20 text-amber-300 border border-amber-500/30 tracking-wider">
                                            '.$ext.'
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2.5 flex-wrap">
                                                <span class="text-sm font-semibold text-gray-100 truncate" title="'.$fileName.'">'.$fileName.'</span>
                                                '.($fileSize ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-600/30 text-gray-300 border border-gray-500/20 tabular-nums">'.$fileSize.'</span>' : '').'
                                            </div>
                                            <div class="text-xs text-gray-400 mt-0.5">Arquivo gravado e verificado no armazenamento seguro</div>
                                        </div>
                                    </div>
                                    <a href="'.route('admin.documents.download', $record).'" target="_blank" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg bg-gray-500/20 hover:bg-gray-500/30 text-gray-100 border border-gray-500/30 transition shadow-sm">
                                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        <span>Baixar arquivo</span>
                                    </a>
                                </div>
                            ');
                        })
                        ->visibleOn('edit')
                        ->visible(fn (?Document $record): bool => (bool) $record?->file_path)
                        ->columnSpanFull(),

                    FileUpload::make('file_path')
                        ->label(fn (string $operation): string => $operation === 'edit' ? 'Substituir arquivo' : 'Arquivo')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->disk('local')
                        ->visibility('private')
                        ->directory('documents')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize((int) config('uploads.document.max_kb', 102400))
                        ->helperText('Tamanho máximo por arquivo: '.(int) ceil(config('uploads.document.max_kb', 102400) / 1024).' MB.')
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state instanceof TemporaryUploadedFile) {
                                $set('file_name', $state->getClientOriginalName());
                                $set('storage_disk', 'local');
                            }
                        })
                        ->columnSpanFull(),

                    Select::make('emissions')
                        ->label('Série')
                        ->relationship('emissions', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->placeholder('Selecione uma ou mais séries...')
                        ->required(false)
                        ->columnSpanFull(),

                    /**
                     * Workflow: Visibilidade e Publicação em 2 colunas amplas
                     */
                    Section::make('Visibilidade e publicação')
                        ->description('Configure a disponibilidade do documento para investidores vinculados e para o site público.')
                        ->compact()
                        ->schema([
                            Toggle::make('is_published')
                                ->label('Publicado')
                                ->helperText('Disponibiliza o documento aos investidores conforme as permissões configuradas.')
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    // se despublicar, não pode continuar público
                                    if (! $state && $get('is_public')) {
                                        $set('is_public', false);
                                    }
                                })
                                ->default(false),

                            Toggle::make('is_public')
                                ->label('Público')
                                ->helperText('Permite exibição pública no site institucional (requer publicação ativa).')
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    // se tornar público, força publicado
                                    if ($state && ! $get('is_published')) {
                                        $set('is_published', true);
                                    }
                                })
                                ->default(false),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('Informações do arquivo')
                ->description('Metadados técnicos derivados do arquivo armazenado e registro de publicação.')
                ->icon('heroicon-o-information-circle')
                ->schema([
                    Placeholder::make('file_name_display')
                        ->label('Nome do arquivo')
                        ->content(fn ($record): string => $record?->file_name ?? '—')
                        ->columnSpanFull(),

                    Placeholder::make('mime_type_display')
                        ->label('Formato')
                        ->content(function ($record): HtmlString|string {
                            if (! $record?->mime_type) {
                                return '—';
                            }
                            $ext = strtoupper(pathinfo($record->file_name ?? '', PATHINFO_EXTENSION) ?: (explode('/', $record->mime_type)[1] ?? 'PDF'));

                            return new HtmlString(
                                '<span class="font-semibold text-gray-200">'.htmlspecialchars($ext).'</span>'.
                                '<span class="text-xs text-gray-400 block mt-0.5">'.htmlspecialchars($record->mime_type).'</span>'
                            );
                        })
                        ->columnSpan(1),

                    Placeholder::make('file_size_display')
                        ->label('Tamanho')
                        ->content(fn ($record): string => $record?->file_size
                            ? Number::fileSize($record->file_size)
                            : '—')
                        ->columnSpan(1),

                    Placeholder::make('storage_disk_display')
                        ->label('Disco')
                        ->content(fn ($record): string => ucfirst($record?->resolved_storage_disk ?? Document::defaultStorageDisk()))
                        ->columnSpan(1),

                    Placeholder::make('published_at_display')
                        ->label('Publicado em')
                        ->content(fn ($record): string => $record?->published_at?->format('d/m/Y · H:i') ?? 'Não publicado')
                        ->columnSpan(1),

                    Placeholder::make('published_by_display')
                        ->label('Publicado por')
                        ->content(fn ($record): string => $record?->publisher?->name ?? '—')
                        ->columnSpanFull(),

                    Placeholder::make('download_link')
                        ->label('')
                        ->content(fn ($record) => $record?->file_path
                            ? new HtmlString(
                                '<div class="pt-1">
                                    <a href="'.route('admin.documents.download', $record).'" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-xs font-semibold rounded-lg bg-gray-500/15 hover:bg-gray-500/25 text-gray-100 border border-gray-500/25 transition shadow-sm">
                                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        <span>Baixar arquivo</span>
                                    </a>
                                </div>'
                            )
                            : '—')
                        ->columnSpanFull(),
                ])
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                ])
                ->visibleOn('edit'),
        ]);
    }
}
