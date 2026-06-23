<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Models\ObligationEvidence;
use App\Services\Obligations\ObligationEvidenceService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class ObligationEvidencesRelationManager extends RelationManager
{
    protected static string $relationship = 'obligationEvidences';

    protected static ?string $recordTitleAttribute = 'original_name';

    protected static ?string $title = 'Evidências';

    protected static ?string $modelLabel = 'Evidência';

    protected static ?string $pluralModelLabel = 'Evidências';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->description('Evidências e comprovantes anexados às obrigações desta emissão.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['uploader', 'obligation']))
            ->columns([
                TextColumn::make('obligation.title')
                    ->label('Obrigação')
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                TextColumn::make('original_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('mime_type')
                    ->label('Tipo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '—'),
                TextColumn::make('uploader.name')
                    ->label('Enviado por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('uploaded_at')
                    ->label('Data de envio')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->filters([
                SelectFilter::make('obligation_id')
                    ->label('Obrigação')
                    ->options(fn (): array => $this->getOwnerRecord()->obligations()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Anexar Evidência')
                    ->modalHeading('Anexar evidência')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => $this->canUploadEvidence())
                    ->authorize(fn (): bool => $this->canUploadEvidence())
                    ->schema([
                        Select::make('obligation_id')
                            ->label('Obrigação')
                            ->options(fn (): array => $this->getOwnerRecord()->obligations()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required(),
                        FileUpload::make('file')
                            ->label('Arquivo')
                            ->required()
                            ->disk('local')
                            ->visibility('private')
                            ->directory(ObligationEvidenceService::STORAGE_DIRECTORY)
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('uploads.obligation_evidence.allowed_mimes', []))
                            ->maxSize((int) config('uploads.obligation_evidence.max_kb', 20480))
                            ->helperText('Tipos aceitos: PDF, DOC, DOCX, XLS, XLSX, CSV, PNG, JPG. Tamanho máximo: '.(int) ceil(config('uploads.obligation_evidence.max_kb', 20480) / 1024).' MB.'),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->maxLength(1000)
                            ->rows(3)
                            ->placeholder('Observação opcional sobre a evidência (ex.: comprovante de envio à CVM).'),
                    ])
                    ->using(function (array $data, RelationManager $livewire): ObligationEvidence {
                        $obligation = $livewire->getOwnerRecord()->obligations()->whereKey($data['obligation_id'])->firstOrFail();

                        return app(ObligationEvidenceService::class)->store(
                            $obligation,
                            $data['file'],
                            $data['description'] ?? null,
                            auth()->id(),
                        );
                    })
                    ->successNotificationTitle('Evidência anexada com sucesso.'),
            ])
            ->actions([
                Action::make('download')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (ObligationEvidence $record): string => route('admin.obligations.evidences.download', $record))
                    ->openUrlInNewTab()
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsDownloadEvidence->value) ?? false),
                DeleteAction::make()
                    ->label('Remover')
                    ->modalHeading('Remover evidência')
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::ObligationsDeleteEvidence->value) ?? false)
                    ->action(function (ObligationEvidence $record): void {
                        app(ObligationEvidenceService::class)->delete($record);

                        Notification::make()
                            ->title('Evidência removida.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma evidência anexada')
            ->emptyStateDescription('Anexe comprovantes e documentos de suporte às obrigações desta emissão.');
    }

    protected function canUploadEvidence(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsUploadEvidence->value) ?? false;
    }
}
