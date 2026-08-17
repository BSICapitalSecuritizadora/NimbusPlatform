<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldStatus;
use App\Models\Emission;
use App\Models\LegalInstrumentField;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de revisão das alterações detectadas nos documentos (§21 do escopo).
 *
 * Mostra "de → para" por campo, com a cláusula e a página que sustentam a
 * proposta — muito mais seguro do que pedir ao usuário que releia o aditamento
 * inteiro. Nenhuma linha aqui alterou a posição vigente ainda.
 */
class InstrumentChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'legalInstrumentFields';

    protected static ?string $title = 'Alterações Detectadas';

    protected static ?string $modelLabel = 'Alteração';

    protected static ?string $pluralModelLabel = 'Alterações Detectadas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::LegalInstrumentsReviewChanges->value) ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        $pending = $ownerRecord->legalInstrumentFields()->pendingReview()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field_key')
            ->description('Alterações identificadas nos documentos dos instrumentos. Nada altera a posição vigente até ser confirmado.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['instrument', 'instrumentDocument.document', 'guarantee', 'reviewer'])
                ->latest('id'))
            ->columns([
                TextColumn::make('instrument.name')
                    ->label('Instrumento')
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->instrument?->display_name ?? '—')
                    ->description(fn (LegalInstrumentField $record): ?string => $record->guarantee?->display_name)
                    ->wrap(),
                TextColumn::make('field_key')
                    ->label('Campo')
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->field_key?->label() ?? '—')
                    ->description(fn (LegalInstrumentField $record): ?string => $record->field_key?->isMaterial() === true
                        ? 'Alteração material'
                        : null)
                    ->wrap(),
                TextColumn::make('previous_value')
                    ->label('Anterior')
                    ->state(fn (LegalInstrumentField $record): string => $this->previousValue($record))
                    ->wrap(),
                TextColumn::make('value')
                    ->label('Novo')
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->formatted_value)
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Fonte')
                    ->state(fn (LegalInstrumentField $record): string => $this->sourceLabel($record))
                    ->wrap(),
                TextColumn::make('evidence_level')
                    ->label('Evidência')
                    ->badge()
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->evidence_level?->label() ?? '—')
                    ->color(fn (LegalInstrumentField $record): string => $record->evidence_level?->color() ?? 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->status?->label() ?? '—')
                    ->color(fn (LegalInstrumentField $record): string => $record->status?->color() ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->attribute('legal_instrument_fields.status')
                    ->options(LegalInstrumentFieldStatus::options())
                    ->default(LegalInstrumentFieldStatus::PendingReview->value),
            ])
            ->actions([
                $this->makeInspectAction(),
                $this->makeConfirmAction(),
                $this->makeRejectAction(),
            ])
            ->emptyStateHeading('Nenhuma alteração pendente')
            ->emptyStateDescription('Anexe documentos ao dossiê de um instrumento para que o sistema compare o conteúdo com a posição vigente.');
    }

    protected function makeInspectAction(): Action
    {
        return Action::make('inspect')
            ->label('Ver trecho')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (LegalInstrumentField $record): string => $record->field_key?->label() ?? 'Alteração')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (LegalInstrumentField $record): View => view(
                'filament.resources.emissions.relation-managers.legal-instrument-change',
                [
                    'change' => app(InstrumentChangeReviewService::class)->describeChange($record),
                ],
            ));
    }

    protected function makeConfirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirmar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Confirmar alteração')
            ->modalDescription('O valor passa a ser a posição vigente a partir da data de vigência. A versão anterior é preservada no histórico.')
            ->modalSubmitActionLabel('Confirmar alteração')
            ->form([
                Textarea::make('review_notes')
                    ->label('Observação da revisão')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (LegalInstrumentField $record): bool => $record->status === LegalInstrumentFieldStatus::PendingReview)
            ->authorize(fn (): bool => app(InstrumentChangeReviewService::class)->canConfirm(auth()->user()))
            ->action(function (LegalInstrumentField $record, array $data): void {
                app(InstrumentChangeReviewService::class)
                    ->confirm($record, auth()->user(), $data['review_notes'] ?? null);

                Notification::make()->title('Alteração confirmada.')->success()->send();
            });
    }

    protected function makeRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading('Rejeitar alteração')
            ->modalDescription('A posição vigente permanece como está. A rejeição exige motivo e fica registrada.')
            ->modalSubmitActionLabel('Rejeitar')
            ->form([
                Textarea::make('review_notes')
                    ->label('Motivo da rejeição')
                    ->rows(3)
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (LegalInstrumentField $record): bool => $record->status === LegalInstrumentFieldStatus::PendingReview)
            ->authorize(fn (): bool => app(InstrumentChangeReviewService::class)->canReject(auth()->user()))
            ->action(function (LegalInstrumentField $record, array $data): void {
                app(InstrumentChangeReviewService::class)
                    ->reject($record, auth()->user(), $data['review_notes'] ?? null);

                Notification::make()->title('Alteração rejeitada.')->success()->send();
            });
    }

    protected function previousValue(LegalInstrumentField $record): string
    {
        $previous = app(InstrumentChangeReviewService::class)->describeChange($record)['previous'];

        return $previous?->formatted_value ?? 'Sem valor anterior';
    }

    protected function sourceLabel(LegalInstrumentField $record): string
    {
        $document = $record->instrumentDocument;

        $parts = array_filter([
            $document?->role_label,
            $record->source_label,
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
