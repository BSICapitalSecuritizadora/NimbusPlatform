<?php

declare(strict_types=1);

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceReviewService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PuBaselineEvidencesRelationManager extends RelationManager
{
    protected static string $relationship = 'puBaselineEvidences';

    protected static ?string $title = 'Evidências do baseline de PU';

    protected static ?string $modelLabel = 'Evidência do baseline';

    protected static ?string $pluralModelLabel = 'Evidências do baseline';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Emission
            && app(PuBaselineReadinessService::class)->supports($ownerRecord)
            && (auth()->user()?->can(AccessPermission::PuCurveView->value) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Documentos vinculados provam valores específicos. Criar a evidência não a aprova e não altera parâmetros de PU.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['document', 'createdBy', 'reviewedBy']))
            ->columns([
                TextColumn::make('evidence_type')
                    ->label('Requisito')
                    ->formatStateUsing(fn (PuBaselineEvidenceType $state): string => $state->label())
                    ->wrap()
                    ->weight('semibold'),
                TextColumn::make('evidenced_value')
                    ->label('Valor comprovado')
                    ->copyable(),
                TextColumn::make('document.title')
                    ->label('Documento')
                    ->wrap()
                    ->description(fn (EmissionPuBaselineEvidence $record): string => $record->document_type->label()),
                TextColumn::make('reference')
                    ->label('Página / cláusula')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('confidence')
                    ->label('Confiança')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'high' => 'Alta',
                        'medium' => 'Média',
                        default => 'Baixa',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Revisão')
                    ->formatStateUsing(fn (PuBaselineEvidenceStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (PuBaselineEvidenceStatus $state): string => match ($state) {
                        PuBaselineEvidenceStatus::Approved => 'success',
                        PuBaselineEvidenceStatus::Rejected => 'danger',
                        PuBaselineEvidenceStatus::PendingReview => 'warning',
                    }),
                TextColumn::make('reviewedBy.name')
                    ->label('Revisor')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('Revisado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('evidence_type')
                    ->label('Requisito')
                    ->options(PuBaselineEvidenceType::options()),
                SelectFilter::make('status')
                    ->label('Revisão')
                    ->options(collect(PuBaselineEvidenceStatus::cases())
                        ->mapWithKeys(fn (PuBaselineEvidenceStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Associar evidência')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => auth()->user()?->can(AccessPermission::PuParametersConfigure->value) ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can(AccessPermission::PuParametersConfigure->value) ?? false)
                    ->modalHeading('Associar evidência ao baseline')
                    ->modalDescription('Informe exatamente o valor demonstrado pelo documento. A evidência seguirá pendente até revisão explícita.')
                    ->schema($this->evidenceForm())
                    ->using(function (array $data, RelationManager $livewire): EmissionPuBaselineEvidence {
                        /** @var User $actor */
                        $actor = auth()->user();

                        return app(PuBaselineEvidenceReviewService::class)->create(
                            $livewire->getOwnerRecord(),
                            $data,
                            $actor,
                        );
                    })
                    ->successNotificationTitle('Evidência associada e enviada para revisão.'),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Aprovar evidência')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Só há decisão a tomar sobre proposta pendente, e o maker da
                    // proposta não é checker dela: o serviço recusa a auto-aprovação,
                    // então a ação nem se oferece.
                    ->visible(fn (EmissionPuBaselineEvidence $record): bool => $record->status === PuBaselineEvidenceStatus::PendingReview
                        && $record->created_by !== auth()->id()
                        && (auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Aprovar evidência do baseline')
                    ->modalDescription('A aprovação torna o valor declarado utilizável pelo gate. Ela não cria parâmetros, curvas ou dados operacionais.')
                    ->schema([
                        Textarea::make('review_notes')
                            ->label('Conclusão da revisão')
                            ->rows(3),
                    ])
                    ->action(function (EmissionPuBaselineEvidence $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(PuBaselineEvidenceReviewService::class)->approve(
                            $record,
                            $reviewer,
                            $data['review_notes'] ?? null,
                        );

                        Notification::make()->title('Evidência aprovada.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Rejeitar evidência')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EmissionPuBaselineEvidence $record): bool => $record->status === PuBaselineEvidenceStatus::PendingReview
                        && $record->created_by !== auth()->id()
                        && (auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false))
                    ->schema([
                        Textarea::make('review_notes')
                            ->label('Motivo da rejeição')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (EmissionPuBaselineEvidence $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(PuBaselineEvidenceReviewService::class)->reject(
                            $record,
                            $reviewer,
                            (string) $data['review_notes'],
                        );

                        Notification::make()->title('Evidência rejeitada.')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhuma evidência material associada')
            ->emptyStateDescription('Data inicial da curva, quantidade integralizada e gabarito externo permanecem apenas inferíveis até que um documento idôneo seja associado e aprovado.')
            ->emptyStateIcon('heroicon-o-document-magnifying-glass');
    }

    /** @return array<int, Field> */
    private function evidenceForm(): array
    {
        return [
            Select::make('evidence_type')
                ->label('Valor a comprovar')
                ->options(PuBaselineEvidenceType::options())
                ->required(),
            Select::make('document_id')
                ->label('Documento já vinculado à emissão')
                ->options(fn (): array => $this->getOwnerRecord()->documents()
                    ->orderBy('title')
                    ->pluck('title', 'documents.id')
                    ->all())
                ->searchable()
                ->required(),
            Select::make('document_type')
                ->label('Tipo do documento')
                ->options(PuBaselineEvidenceDocumentType::options())
                ->searchable()
                ->required(),
            TextInput::make('evidenced_value')
                ->label('Valor demonstrado')
                ->helperText('Data: AAAA-MM-DD. Quantidade: número positivo. Gabarito: available_pending_comparison, matched ou divergent.')
                ->required()
                ->maxLength(255),
            TextInput::make('reference')
                ->label('Página, cláusula ou referência da posição')
                ->maxLength(255),
            Select::make('confidence')
                ->label('Confiança da extração')
                ->options([
                    'high' => 'Alta',
                    'medium' => 'Média',
                    'low' => 'Baixa',
                ])
                ->default('high')
                ->required(),
            Textarea::make('notes')
                ->label('Observações')
                ->rows(3)
                ->maxLength(2000),
        ];
    }
}
