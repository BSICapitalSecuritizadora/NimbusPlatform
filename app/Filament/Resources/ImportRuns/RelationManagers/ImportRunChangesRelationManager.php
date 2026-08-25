<?php

namespace App\Filament\Resources\ImportRuns\RelationManagers;

use App\Enums\AccessPermission;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ImportRun;
use App\Support\ActivityLog\ActivityChange;
use App\Support\ActivityLog\ActivityPresenter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Activity;

/**
 * The individual changes one confirmed reconciliation produced.
 *
 * The listing is the activity log filtered by the batch this execution opened,
 * which is what makes it exact: a manual edit made on the same contract an hour
 * later belongs to another batch and cannot appear here, and re-importing the
 * file next month writes its own batch instead of adding to this one.
 *
 * Only business subjects are listed. The activity that describes the execution
 * itself shares the batch but carries no subject, so it stays out of the table
 * without needing a rule of its own.
 *
 * Nothing is recalculated against today's records: every value comes from
 * `properties.old` and `properties.attributes` as they were written.
 */
class ImportRunChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Alterações realizadas';

    protected static ?string $modelLabel = 'alteração';

    protected static ?string $pluralModelLabel = 'alterações';

    /**
     * The subjects a reconciliation can touch. Also the whitelist that keeps the
     * execution's own activity out of the listing.
     *
     * @var array<int, class-string>
     */
    private const RECORD_SUBJECTS = [
        Contract::class,
        ContractInstallment::class,
    ];

    /**
     * Reading the changes of an execution is reading the execution: same
     * permission, no second gate. The links out to the modules are the ones that
     * carry their own.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::AuditImportRunsView->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('subject_type', self::RECORD_SUBJECTS)
                ->with(['subject' => fn (MorphTo $morphTo) => $morphTo
                    ->morphWith([ContractInstallment::class => ['contract']])
                    /**
                     * A record deleted after the import keeps its trail. Without
                     * this the subject would come back null and the row would
                     * read as unavailable while the record is merely archived.
                     */
                    ->constrain([
                        Contract::class => fn (Builder $query): Builder => $query->withTrashed(),
                        ContractInstallment::class => fn (Builder $query): Builder => $query->withTrashed(),
                    ])])
                ->orderBy('id'))
            ->columns([
                TextColumn::make('subject_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Contract::class ? 'Contrato' : 'Parcela')
                    ->color(fn (string $state): string => $state === Contract::class ? 'info' : 'primary'),

                TextColumn::make('subject_id')
                    ->label('Registro')
                    ->state(fn (Activity $record): string => ActivityPresenter::subjectLabel($record))
                    ->description(fn (Activity $record): ?string => $record->subject === null
                        ? 'Registro indisponível'
                        : null),

                TextColumn::make('properties')
                    ->label('Alterações')
                    ->state(fn (Activity $record): string => self::changedFieldsLabel($record))
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_type')
                    ->label('Tipo')
                    ->options([
                        Contract::class => 'Contrato',
                        ContractInstallment::class => 'Parcela',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Detalhes')
                    ->modalHeading(fn (Activity $record): string => ActivityPresenter::subjectLabel($record))
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->infolist(fn (Schema $schema): Schema => self::changeInfolist($schema)),

                Action::make('openRecord')
                    ->label('Abrir registro')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Activity $record): ?string => self::recordUrl($record))
                    ->visible(fn (Activity $record): bool => self::recordUrl($record) !== null),
            ])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
            ->emptyStateHeading(fn (): string => $this->ownerRunHasCorrelation()
                ? 'Nenhuma alteração individual'
                : 'Detalhamento individual indisponível')
            ->emptyStateDescription(fn (): string => $this->ownerRunHasCorrelation()
                ? 'Nenhuma alteração individual foi necessária nesta execução: a posição do arquivo já era a posição registrada. Os registros novos desta importação estão no resumo acima.'
                : 'Esta importação foi registrada antes da correlação com o log de alterações. O resumo acima continua válido.');
    }

    /**
     * The reconciliation writes new records in bulk, which never produces an
     * activity. That is deliberate -- see the import actions -- and it is why
     * the empty state points back at the counters.
     */
    private function ownerRunHasCorrelation(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof ImportRun && $owner->hasChangeCorrelation();
    }

    /**
     * The names of the fields this activity actually changed, nothing else. The
     * before/after values live in the detail modal.
     */
    private static function changedFieldsLabel(Activity $activity): string
    {
        $labels = array_map(
            fn (ActivityChange $change): string => $change->label,
            ActivityPresenter::changesFor($activity),
        );

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    private static function changeInfolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registro')
                ->schema([
                    TextEntry::make('subject_label')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): string => ActivityPresenter::subjectLabel($record)),
                    TextEntry::make('created_at')
                        ->label('Registrado em')
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(2),

            Section::make('Alterações')
                ->schema([
                    TextEntry::make('changes')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): array => array_map(
                            fn (ActivityChange $change): string => sprintf(
                                '%s: %s → %s',
                                $change->label,
                                $change->old ?? '—',
                                $change->new ?? '—',
                            ),
                            ActivityPresenter::changesFor($record),
                        ))
                        ->listWithLineBreaks()
                        ->placeholder('Nenhum campo alterado.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function recordUrl(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if (($subject instanceof Contract) && ContractResource::canView($subject)) {
            return ContractResource::getUrl('view', ['record' => $subject]);
        }

        if (($subject instanceof ContractInstallment) && ContractInstallmentResource::canViewAny()) {
            return ContractInstallmentResource::getUrl();
        }

        return null;
    }
}
