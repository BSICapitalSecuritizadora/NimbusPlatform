<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\GuaranteeEvidenceLevel;
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
use Filament\Tables\Grouping\Group;
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
            ->description('Alterações identificadas automaticamente nos documentos da operação e aguardando conferência humana.')
            ->searchPlaceholder('Buscar por campo, valor ou cláusula...')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['instrument', 'instrumentDocument.document', 'guarantee', 'reviewer'])
                ->latest('id'))
            ->groups([
                Group::make('legal_instrument_id')
                    ->label('Instrumento')
                    ->getTitleFromRecordUsing(fn (LegalInstrumentField $record): string => $record->instrument?->display_name ?? 'Instrumento não identificado')
                    ->collapsible(),
            ])
            ->defaultGroup('legal_instrument_id')
            ->recordAction('inspect')
            ->columns([
                TextColumn::make('field_key')
                    ->label('Campo Alterado')
                    ->weight('semibold')
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => $record->field_key?->label() ?? '—')
                    ->description(function (LegalInstrumentField $record): ?string {
                        $parts = array_filter([
                            $record->guarantee?->display_name,
                            $record->field_key?->isMaterial() === true ? 'Alteração material' : null,
                        ]);

                        return $parts === [] ? null : implode(' · ', $parts);
                    })
                    ->wrap(),
                TextColumn::make('diff')
                    ->label('Alteração (De → Para)')
                    ->formatStateUsing(function (LegalInstrumentField $record): string {
                        $prev = htmlspecialchars($this->previousValue($record));
                        $new = htmlspecialchars($record->formatted_value);
                        $date = $record->effective_date
                            ? "<span class=\"text-[11px] text-gray-400 dark:text-gray-500 block mt-0.5\">Vigência: {$record->effective_date->format('d/m/Y')}</span>"
                            : '';

                        return '<div class="flex flex-col gap-0.5">'
                            .'<div class="flex items-center gap-1.5 flex-wrap">'
                            ."<span class=\"text-xs text-gray-400 line-through\">{$prev}</span>"
                            .'<span class="text-xs text-gray-500 font-bold">→</span>'
                            ."<span class=\"text-xs font-semibold text-emerald-600 dark:text-emerald-400\">{$new}</span>"
                            .'</div>'
                            ."{$date}"
                            .'</div>';
                    })
                    ->html()
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Origem / Fonte')
                    ->state(fn (LegalInstrumentField $record): string => $this->sourceLabel($record))
                    ->description(fn (LegalInstrumentField $record): ?string => $record->instrumentDocument?->role_label)
                    ->wrap(),
                TextColumn::make('evidence_level')
                    ->label('Evidência')
                    ->badge()
                    ->formatStateUsing(fn (LegalInstrumentField $record): string => match ($record->evidence_level) {
                        GuaranteeEvidenceLevel::Explicit => 'Explícita',
                        GuaranteeEvidenceLevel::Inferred => 'Inferida',
                        GuaranteeEvidenceLevel::NotFound => 'Não localizada',
                        GuaranteeEvidenceLevel::Conflicting => 'Conflitante',
                        null => '—',
                    })
                    ->color(fn (LegalInstrumentField $record): string => $record->evidence_level?->color() ?? 'gray')
                    ->tooltip(fn (LegalInstrumentField $record): ?string => $record->confidence_score !== null
                        ? $record->evidence_level?->label().' (Confiança: '.round($record->confidence_score * 100).'%)'
                        : $record->evidence_level?->label()),
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
                SelectFilter::make('evidence_level')
                    ->label('Evidência')
                    ->options([
                        GuaranteeEvidenceLevel::Explicit->value => 'Explícita',
                        GuaranteeEvidenceLevel::Inferred->value => 'Inferida',
                        GuaranteeEvidenceLevel::Conflicting->value => 'Conflitante',
                        GuaranteeEvidenceLevel::NotFound->value => 'Não localizada',
                    ]),
            ])
            ->actions([
                $this->makeInspectAction(),
                $this->makeConfirmAction(),
                $this->makeRejectAction(),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100])
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
            ->emptyStateHeading('Nenhuma alteração pendente de revisão')
            ->emptyStateDescription('Todas as alterações identificadas nos documentos desta emissão já foram conferidas e homologadas.');
    }

    protected function makeInspectAction(): Action
    {
        return Action::make('inspect')
            ->label('Ver trecho')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->tooltip('Consultar trecho e evidência documental')
            ->modalHeading(fn (LegalInstrumentField $record): string => 'Evidência: '.($record->field_key?->label() ?? 'Alteração'))
            ->modalDescription(fn (LegalInstrumentField $record): ?string => $record->instrument?->display_name
                ? ($record->instrument->display_name.' · '.($record->source_label ?? 'Documento da Operação'))
                : null)
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
            ->tooltip('Homologar alteração como posição vigente')
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
            ->tooltip('Rejeitar proposta de alteração')
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
        $parts = [];

        if (filled($record->clause)) {
            $parts[] = 'Cláusula '.$record->clause;
        }

        if (filled($record->page)) {
            $parts[] = 'pág. '.$record->page;
        }

        return $parts === [] ? ($record->source_label ?? '—') : implode(' · ', $parts);
    }
}
