<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Models\ObligationEvidence;
use App\Services\Obligations\ObligationEvidenceReviewService;
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

    public function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\Section::make('Revisão da Evidência')
                ->schema([
                    \Filament\Infolists\Components\Grid::make(3)->schema([
                        \Filament\Infolists\Components\TextEntry::make('original_name')
                            ->label('Arquivo Anexado')
                            ->weight('bold')
                            ->size('lg')
                            ->columnSpan(2),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Status da Revisão')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => \App\Models\ObligationEvidence::STATUS_OPTIONS[$state] ?? (string) $state)
                            ->color(fn (?string $state): string => match ($state) {
                                \App\Models\ObligationEvidence::STATUS_APPROVED => 'success',
                                \App\Models\ObligationEvidence::STATUS_REJECTED => 'danger',
                                default => 'warning',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('uploader.name')
                            ->label('Enviado por')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('uploaded_at')
                            ->label('Data de Envio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('size')
                            ->label('Tamanho do Arquivo')
                            ->formatStateUsing(fn (?int $state): string => $state ? \Illuminate\Support\Number::fileSize($state) : '—'),
                        \Filament\Infolists\Components\TextEntry::make('next_action')
                            ->label('Próxima Ação Recomendada')
                            ->state(fn (\App\Models\ObligationEvidence $record): string => match ($record->status) {
                                \App\Models\ObligationEvidence::STATUS_PENDING => 'Revisar a evidência anexada e aprovar ou rejeitar o documento.',
                                \App\Models\ObligationEvidence::STATUS_APPROVED => 'Nenhuma ação. Evidência aprovada.',
                                \App\Models\ObligationEvidence::STATUS_REJECTED => 'Anexar um novo documento corrigindo o motivo da rejeição.',
                                default => 'Aguardando ação.',
                            })
                            ->color('primary')
                            ->weight('bold')
                            ->columnSpan(2),
                    ]),
                    \Filament\Infolists\Components\TextEntry::make('description')
                        ->label('Descrição do Upload')
                        ->columnSpanFull()
                        ->placeholder('Sem descrição informada.'),
                ]),
            \Filament\Infolists\Components\Section::make('Notas de Revisão')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('reviewer.name')
                        ->label('Revisado por')
                        ->placeholder('Ainda não revisado.'),
                    \Filament\Infolists\Components\TextEntry::make('reviewed_at')
                        ->label('Data da Revisão')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('review_notes')
                        ->label('Observações de Aprovação')
                        ->visible(fn (\App\Models\ObligationEvidence $record) => $record->status === \App\Models\ObligationEvidence::STATUS_APPROVED)
                        ->placeholder('Nenhuma observação informada.'),
                    \Filament\Infolists\Components\TextEntry::make('rejection_reason')
                        ->label('Motivo da Rejeição')
                        ->color('danger')
                        ->visible(fn (\App\Models\ObligationEvidence $record) => $record->status === \App\Models\ObligationEvidence::STATUS_REJECTED)
                        ->placeholder('—'),
                ])->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->description('Evidências e comprovantes anexados às obrigações desta emissão.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['uploader', 'obligation', 'reviewer']))
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
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ObligationEvidence::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ObligationEvidence::STATUS_APPROVED => 'success',
                        ObligationEvidence::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
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
                TextColumn::make('reviewer.name')
                    ->label('Revisado por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('Revisado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('review_notes')
                    ->label('Observação da aprovação')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rejection_reason')
                    ->label('Motivo da rejeição')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->filters([
                SelectFilter::make('obligation_id')
                    ->label('Obrigação')
                    ->options(fn (): array => $this->getOwnerRecord()->obligations()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ObligationEvidence::STATUS_OPTIONS),
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
                \Filament\Actions\ViewAction::make()
                    ->label('Revisar Evidência')
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can(\App\Enums\AccessPermission::ObligationsViewEvidence->value) ?? false)
                    ->extraModalFooterActions(fn (\App\Models\ObligationEvidence $record) => [
                        $this->makeApproveAction()->record($record)->visible(fn () => $this->canReviewEvidence($record, \App\Services\Obligations\ObligationEvidenceReviewService::TRANSITION_APPROVE)),
                        $this->makeRejectAction()->record($record)->visible(fn () => $this->canReviewEvidence($record, \App\Services\Obligations\ObligationEvidenceReviewService::TRANSITION_REJECT)),
                    ]),
                $this->makeApproveAction(),
                $this->makeRejectAction(),
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

    protected function makeApproveAction(): Action
    {
        return Action::make('approve_evidence')
            ->label('Aprovar evidência')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalHeading('Aprovar evidência')
            ->modalSubmitActionLabel('Aprovar evidência')
            ->form([
                Textarea::make('review_notes')
                    ->label('Observação da aprovação')
                    ->rows(4)
                    ->maxLength(2000)
                    ->placeholder('Observação opcional sobre a aprovação da evidência.'),
            ])
            ->visible(fn (ObligationEvidence $record): bool => $this->canReviewEvidence($record, ObligationEvidenceReviewService::TRANSITION_APPROVE))
            ->authorize(fn (ObligationEvidence $record): bool => $this->canReviewEvidence($record, ObligationEvidenceReviewService::TRANSITION_APPROVE))
            ->action(function (ObligationEvidence $record, array $data): void {
                $this->reviewService()->approve($record, auth()->user(), $data['review_notes'] ?? null);
            })
            ->successNotificationTitle('Evidência aprovada com sucesso.');
    }

    protected function makeRejectAction(): Action
    {
        return Action::make('reject_evidence')
            ->label('Rejeitar evidência')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Rejeitar evidência')
            ->modalSubmitActionLabel('Rejeitar evidência')
            ->form([
                Textarea::make('rejection_reason')
                    ->label('Motivo da rejeição')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->placeholder('Informe por que a evidência foi rejeitada.'),
            ])
            ->visible(fn (ObligationEvidence $record): bool => $this->canReviewEvidence($record, ObligationEvidenceReviewService::TRANSITION_REJECT))
            ->authorize(fn (ObligationEvidence $record): bool => $this->canReviewEvidence($record, ObligationEvidenceReviewService::TRANSITION_REJECT))
            ->action(function (ObligationEvidence $record, array $data): void {
                $this->reviewService()->reject($record, auth()->user(), $data['rejection_reason'] ?? null);
            })
            ->successNotificationTitle('Evidência rejeitada com sucesso.');
    }

    protected function canUploadEvidence(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsUploadEvidence->value) ?? false;
    }

    protected function canReviewEvidence(ObligationEvidence $record, string $transition): bool
    {
        return $this->reviewService()->canRunTransition(auth()->user(), $record, $transition);
    }

    protected function reviewService(): ObligationEvidenceReviewService
    {
        return app(ObligationEvidenceReviewService::class);
    }
}
