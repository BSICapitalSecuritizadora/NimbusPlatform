<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Schemas;

use App\Enums\MalwareScanStatus;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Services\GeminiService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmissionMonthlyReportNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('reference_document_id', null))
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Competência')
                        ->placeholder('MM/AAAA')
                        ->mask('99/9999')
                        ->required()
                        ->live(debounce: 500)
                        ->afterStateUpdated(fn (Set $set) => $set('reference_document_id', null))
                        ->formatStateUsing(fn (mixed $state): string => EmissionMonthlyReportNote::formatReferenceMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => EmissionMonthlyReportNote::normalizeReferenceMonth($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => EmissionMonthlyReportNote::normalizeReferenceMonth($state))
                        ->helperText('Mês de referência do relatório em que a nota deve aparecer.')
                        ->validationMessages([
                            'required' => 'Informe a competência no formato MM/AAAA.',
                        ]),

                    Select::make('category')
                        ->label('Categoria')
                        ->options(EmissionMonthlyReportNote::CATEGORY_OPTIONS)
                        ->default('Geral')
                        ->native(false),
                ])
                ->columns(3),

            Section::make('Conteúdo da Nota')
                ->columnSpanFull()
                ->schema([
                    Section::make('Gerar a partir de documento')
                        ->description('Documento + IA = assistente opcional. Selecione um documento relacionado à emissão e competência para gerar uma sugestão de título e nota com IA. O preenchimento manual continua disponível.')
                        ->collapsible(false)
                        ->columnSpanFull()
                        ->schema([
                            Select::make('reference_document_id')
                                ->label('Documento de Referência')
                                ->placeholder('Selecione um documento')
                                ->helperText(function (Get $get): string {
                                    $emissionId = $get('emission_id');
                                    $referenceMonth = $get('reference_month');

                                    if (blank($emissionId) || blank($referenceMonth)) {
                                        return 'Selecione uma emissão e informe a competência para ver os documentos disponíveis.';
                                    }

                                    $normalized = EmissionMonthlyReportNote::normalizeReferenceMonth($referenceMonth);

                                    if ($normalized === null) {
                                        return 'Informe uma competência válida (MM/AAAA) para filtrar os documentos.';
                                    }

                                    $options = self::documentOptions($emissionId, $referenceMonth);

                                    if ($options === []) {
                                        return 'Nenhum documento encontrado para esta emissão e competência.';
                                    }

                                    return 'Lista apenas documentos vinculados à emissão selecionada. A competência prioriza documentos com data dentro do mês informado.';
                                })
                                ->options(fn (Get $get): array => self::documentOptions($get('emission_id'), $get('reference_month')))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->dehydrated(false)
                                ->disabled(fn (Get $get): bool => blank($get('emission_id')) || blank($get('reference_month')) || EmissionMonthlyReportNote::normalizeReferenceMonth($get('reference_month')) === null)
                                ->columnSpanFull(),

                            SchemaActions::make([
                                Action::make('generateWithAi')
                                    ->label(fn (Get $get): string => filled($get('title')) || filled($get('content')) ? 'Gerar novamente com IA' : 'Gerar nota com IA')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    ->disabled(fn (Get $get): bool => blank($get('reference_document_id')))
                                    ->requiresConfirmation(fn (Get $get): bool => filled($get('title')) || filled($get('content')))
                                    ->modalHeading(fn (Get $get): string => filled($get('title')) || filled($get('content')) ? 'Gerar novamente?' : 'Gerar nota com IA')
                                    ->modalDescription(function (Get $get): string {
                                        if (filled($get('title')) || filled($get('content'))) {
                                            return 'O conteúdo atual dos campos Título e Nota Explicativa será substituído pela nova sugestão. Você poderá revisar e editar antes de salvar.';
                                        }

                                        return 'A IA analisará o documento selecionado para sugerir um título e uma nota explicativa. O conteúdo gerado é apenas uma sugestão e poderá ser editado livremente antes de salvar.';
                                    })
                                    ->modalSubmitActionLabel('Gerar')
                                    ->action(function (Get $get, Set $set, GeminiService $gemini): void {
                                        $emissionId = $get('emission_id');
                                        $referenceMonthRaw = $get('reference_month');
                                        $documentId = $get('reference_document_id');
                                        $category = $get('category') ?? 'Geral';

                                        if (blank($emissionId) || blank($referenceMonthRaw) || blank($documentId)) {
                                            Notification::make()
                                                ->title('Selecione a emissão, a competência e um documento de referência antes de gerar.')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $normalized = EmissionMonthlyReportNote::normalizeReferenceMonth($referenceMonthRaw);
                                        $referenceLabel = $normalized !== null
                                            ? EmissionMonthlyReportNote::formatReferenceMonthForDisplay($normalized)
                                            : (string) $referenceMonthRaw;

                                        $emission = Emission::find($emissionId);

                                        if (! $emission) {
                                            Notification::make()
                                                ->title('Emissão não encontrada.')
                                                ->danger()
                                                ->send();

                                            return;
                                        }

                                        $document = Document::query()
                                            ->whereHas('emissions', fn ($q) => $q->where('emissions.id', $emissionId))
                                            ->whereKey($documentId)
                                            ->whereNotIn('scan_status', [MalwareScanStatus::Infected->value, MalwareScanStatus::Rejected->value])
                                            ->first();

                                        if (! $document) {
                                            Notification::make()
                                                ->title('Documento não encontrado para esta emissão.')
                                                ->danger()
                                                ->send();

                                            return;
                                        }

                                        $disk = $document->resolved_storage_disk;
                                        $path = $document->file_path;

                                        if (! Storage::disk($disk)->exists($path)) {
                                            $defaultDisk = config('filesystems.default', 'local');
                                            if ($defaultDisk === $disk || ! Storage::disk($defaultDisk)->exists($path)) {
                                                Notification::make()
                                                    ->title('Arquivo do documento não encontrado.')
                                                    ->body('Não foi possível localizar o arquivo para análise. Verifique o documento ou selecione outro.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }
                                        }

                                        try {
                                            $result = $gemini->generateExplanatoryNote($document, [
                                                'issuanceName' => $emission->name ?? 'Não informada',
                                                'referenceMonth' => $referenceLabel,
                                                'category' => $category,
                                                'documentName' => $document->title ?? 'Documento',
                                                'documentType' => $document->category_label ?? $document->category ?? 'Não informado',
                                            ]);
                                        } catch (\Throwable $e) {
                                            Log::warning('Falha ao gerar nota explicativa com IA', [
                                                'emission_id' => $emissionId,
                                                'document_id' => $document->id,
                                                'reference_month' => $referenceLabel,
                                                'error' => $e->getMessage(),
                                            ]);

                                            $message = $e->getMessage();

                                            // Nunca expor caminho interno; mapear erros de arquivo vazio para mensagem amigável
                                            if (str_contains($message, 'Arquivo vazio') || str_contains($message, 'Arquivo não encontrado')) {
                                                Notification::make()
                                                    ->title('O documento selecionado não contém texto extraível para análise.')
                                                    ->body('Não foi possível gerar a Nota Explicativa com este documento. Tente novamente ou continue preenchendo manualmente.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            Notification::make()
                                                ->title('Não foi possível gerar a Nota Explicativa com este documento. Tente novamente ou continue preenchendo manualmente.')
                                                ->danger()
                                                ->send();

                                            return;
                                        }

                                        $set('title', $result['title']);
                                        $set('content', $result['note']);

                                        Notification::make()
                                            ->title('Sugestão gerada com sucesso. Revise e edite antes de salvar.')
                                            ->success()
                                            ->send();
                                    }),
                            ]),
                        ]),

                    TextInput::make('title')
                        ->label('Título')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('content')
                        ->label('Nota Explicativa')
                        ->required()
                        ->rows(5)
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Escreva a nota explicativa.',
                        ]),
                ])
                ->columns(1),

            Section::make('Publicação')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('is_visible_on_report')
                        ->label('Exibir no relatório mensal')
                        ->default(true)
                        ->live()
                        ->helperText(fn (Get $get): string => $get('is_visible_on_report')
                            ? 'A nota será incluída no relatório da competência selecionada.'
                            : 'A nota permanecerá apenas como registro interno, sem exibição no PDF.'),
                ])
                ->columns(1),
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function documentOptions(mixed $emissionId, mixed $referenceMonth): array
    {
        if (blank($emissionId)) {
            return [];
        }

        $normalized = EmissionMonthlyReportNote::normalizeReferenceMonth($referenceMonth);

        if ($normalized === null && filled($referenceMonth)) {
            return [];
        }

        $emission = Emission::find($emissionId);

        if (! $emission) {
            return [];
        }

        $documents = $emission->documents()
            ->whereNotIn('documents.scan_status', [MalwareScanStatus::Infected->value, MalwareScanStatus::Rejected->value])
            ->orderBy('documents.title')
            ->get();

        if ($documents->isEmpty()) {
            return [];
        }

        // Prioriza documentos com document_date dentro da competência
        if ($normalized !== null) {
            $monthStart = Carbon::parse($normalized)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $inMonth = $documents->filter(function (Document $doc) use ($monthStart, $monthEnd): bool {
                $date = $doc->pivot->document_date ?? null;

                if ($date === null) {
                    return false;
                }

                try {
                    $parsed = Carbon::parse($date);
                } catch (\Throwable) {
                    return false;
                }

                return $parsed->between($monthStart, $monthEnd);
            })->sortBy(fn (Document $doc) => $doc->pivot->document_date);

            $outMonth = $documents->reject(function (Document $doc) use ($monthStart, $monthEnd): bool {
                $date = $doc->pivot->document_date ?? null;

                if ($date === null) {
                    return false;
                }

                try {
                    $parsed = Carbon::parse($date);
                } catch (\Throwable) {
                    return false;
                }

                return $parsed->between($monthStart, $monthEnd);
            })->sortBy(function (Document $doc) {
                // Documentos sem data vão para o fim; com data ordenados cronologicamente
                return $doc->pivot->document_date ?? '9999-12-31';
            });

            // Mantém documentos dentro do mês primeiro, depois os demais (sem data por último)
            $ordered = $inMonth->values()->concat($outMonth->values());
        } else {
            $ordered = $documents->sortBy(fn (Document $doc) => $doc->pivot->document_date ?? '9999-12-31')->values();
        }

        return $ordered
            ->take(50)
            ->mapWithKeys(function (Document $doc): array {
                $label = $doc->title ?? 'Documento sem título';
                $category = $doc->category_label ?? $doc->category ?? '';

                if (filled($category)) {
                    $label .= " — {$category}";
                }

                $pivotDate = $doc->pivot->document_date ?? null;

                if ($pivotDate) {
                    try {
                        $label .= ' — '.Carbon::parse($pivotDate)->format('m/Y');
                    } catch (\Throwable) {
                        // ignora data inválida
                    }
                }

                $pivotType = $doc->pivot->legal_document_type ?? null;

                if (filled($pivotType)) {
                    $label .= " ({$pivotType})";
                }

                return [$doc->id => $label];
            })
            ->all();
    }
}
