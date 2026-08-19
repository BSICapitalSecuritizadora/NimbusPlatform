<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationSeriesStatus;
use App\Filament\Resources\Emissions\Schemas\ObligationSeriesFormFields;
use App\Models\ObligationSeries;
use App\Services\Obligations\ObligationScheduleCalculator;
use App\Services\Obligations\ObligationSeriesService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ObligationSeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'obligationSeries';

    protected static ?string $title = 'Recorrências de Obrigações';

    protected static ?string $modelLabel = 'Recorrência';

    protected static ?string $pluralModelLabel = 'Recorrências';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema(ObligationSeriesFormFields::make($this->getOwnerRecord()->maturity_date?->toDateString()))
            ->columns(2);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Definição da Recorrência')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('title')
                            ->label('Obrigação jurídica')
                            ->weight('bold')
                            ->columnSpan(2),
                        TextEntry::make('status')
                            ->label('Status da série')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationSeriesStatus
                                ? $state->label()
                                : (ObligationSeriesStatus::tryFrom((string) $state)?->label() ?? (string) $state)),
                        TextEntry::make('frequency')
                            ->label('Recorrência')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationFrequency
                                ? $state->label()
                                : (ObligationFrequency::tryFrom((string) $state)?->label() ?? 'A definir')),
                        TextEntry::make('rule_summary')
                            ->label('Regra executável confirmada')
                            ->columnSpan(2),
                        TextEntry::make('starts_on')
                            ->label('Competência inicial')
                            ->date('m/Y')
                            ->placeholder('A definir'),
                        TextEntry::make('ends_on')
                            ->label('Término')
                            ->date('d/m/Y')
                            ->placeholder('A definir'),
                        TextEntry::make('responsibleUser.name')
                            ->label('Responsável padrão')
                            ->placeholder('Não atribuído'),
                        TextEntry::make('responsible_area')
                            ->label('Área padrão')
                            ->placeholder('—'),
                        TextEntry::make('due_rule')
                            ->label('Regra jurídica original')
                            ->placeholder('Não informada')
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->description('A série guarda a regra recorrente; cada competência materializada aparece como uma obrigação operacional independente.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['responsibleUser', 'rules'])
                ->withCount('occurrences'))
            ->columns([
                TextColumn::make('title')
                    ->label('Série')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('frequency')
                    ->label('Recorrência')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationFrequency
                        ? $state->label()
                        : (ObligationFrequency::tryFrom((string) $state)?->label() ?? 'A definir')),
                TextColumn::make('rule_summary')
                    ->label('Regra')
                    ->wrap(),
                TextColumn::make('starts_on')
                    ->label('Início')
                    ->date('m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label('Término')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('next_occurrence')
                    ->label('Próxima ocorrência')
                    ->state(fn (ObligationSeries $record): string => $this->nextOccurrenceLabel($record))
                    ->wrap(),
                TextColumn::make('occurrences_count')
                    ->label('Competências')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationSeriesStatus
                        ? $state->label()
                        : (ObligationSeriesStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn (mixed $state): string => match ($state instanceof ObligationSeriesStatus ? $state : ObligationSeriesStatus::tryFrom((string) $state)) {
                        ObligationSeriesStatus::Active => 'success',
                        ObligationSeriesStatus::Paused => 'warning',
                        ObligationSeriesStatus::Closed => 'gray',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status da série')
                    ->options(ObligationSeriesStatus::options()),
                SelectFilter::make('frequency')
                    ->label('Recorrência')
                    ->options(ObligationFrequency::seriesOptions()),
                SelectFilter::make('responsible_user_id')
                    ->label('Responsável padrão')
                    ->relationship('responsibleUser', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Criar recorrência')
                    ->modalHeading('Criar recorrência de obrigação')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->authorize(fn (): bool => $this->canCreateSeries())
                    ->using(fn (array $data): ObligationSeries => $this->seriesService()->createConfigured(
                        $this->getOwnerRecord(),
                        auth()->user(),
                        $data,
                    )),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Abrir série')
                    ->color('info'),
                Action::make('occurrences')
                    ->label('Competências')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->modalHeading(fn (ObligationSeries $record): string => 'Competências — '.$record->title)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (ObligationSeries $record) => view('filament.obligations.series-occurrences', [
                        'series' => $record,
                        'occurrences' => $record->occurrences()
                            ->getQuery()
                            ->reorder('competence_date', 'desc')
                            ->with('responsibleUser')
                            ->withCount('evidences')
                            ->get(),
                    ])),
                $this->makeConfigureAction(),
                $this->makeEditDefinitionAction(),
                $this->makeReviseRuleAction(),
                $this->makeOnDemandOccurrenceAction(),
                $this->makePauseAction(),
                $this->makeReactivateAction(),
                $this->makeCloseAction(),
                $this->makeHistoryAction(),
            ])
            ->emptyStateHeading('Nenhuma recorrência configurada')
            ->emptyStateDescription('Obrigações únicas continuam na aba de obrigações. Cadastre aqui apenas obrigações recorrentes ou sob demanda.');
    }

    protected function makeConfigureAction(): Action
    {
        return Action::make('configure_series')
            ->label('Configurar recorrência')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->modalHeading('Confirmar regra executável')
            ->modalDescription('Confirme competência inicial, término e regra. Nenhuma data é inferida automaticamente do texto jurídico.')
            ->modalWidth(Width::FiveExtraLarge)
            ->schema(ObligationSeriesFormFields::make($this->getOwnerRecord()->maturity_date?->toDateString()))
            ->fillForm(fn (ObligationSeries $record): array => $this->seriesFormData($record))
            ->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::AwaitingConfiguration && $this->canUpdateSeries())
            ->authorize(fn (): bool => $this->canUpdateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->configure($record, auth()->user(), $data))
            ->successNotificationTitle('Recorrência configurada e ocorrências materializadas.');
    }

    protected function makeEditDefinitionAction(): Action
    {
        return Action::make('edit_series_definition')
            ->label('Editar definição')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading('Editar definição da série')
            ->modalDescription('A alteração valerá como padrão para novas ocorrências. Competências já materializadas permanecerão inalteradas.')
            ->modalWidth(Width::FourExtraLarge)
            ->schema([
                ...ObligationSeriesFormFields::definitionFields(),
                ...ObligationSeriesFormFields::sourceFields(),
            ])
            ->fillForm(fn (ObligationSeries $record): array => $this->seriesFormData($record))
            ->visible(fn (): bool => $this->canUpdateSeries())
            ->authorize(fn (): bool => $this->canUpdateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->updateDefinition($record, auth()->user(), $data))
            ->successNotificationTitle('Definição da série atualizada.');
    }

    protected function makeReviseRuleAction(): Action
    {
        return Action::make('revise_future_rule')
            ->label('Editar esta e as próximas')
            ->icon('heroicon-o-forward')
            ->color('gray')
            ->modalHeading('Alterar regra desta competência em diante')
            ->modalDescription('Ocorrências concluídas, em análise ou com evidências, comentários ou notificações não serão alteradas. Ocorrências futuras ainda intocadas serão recalculadas após confirmação.')
            ->modalWidth(Width::FourExtraLarge)
            ->schema([
                DatePicker::make('effective_from')
                    ->label('Competência inicial da nova regra')
                    ->required(),
                ...ObligationSeriesFormFields::revisionFields(),
                Textarea::make('change_reason')
                    ->label('Motivo da alteração')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->fillForm(function (ObligationSeries $record): array {
                $next = app(ObligationScheduleCalculator::class)->nextOccurrence($record);

                return array_merge($this->seriesFormData($record), [
                    'effective_from' => $next !== null
                        ? $next['competence_date']->toDateString()
                        : now()->startOfMonth()->toDateString(),
                ]);
            })
            ->visible(fn (ObligationSeries $record): bool => $record->status !== ObligationSeriesStatus::AwaitingConfiguration && $record->status !== ObligationSeriesStatus::Closed && $this->canUpdateSeries())
            ->authorize(fn (): bool => $this->canUpdateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->reviseRuleFrom($record, auth()->user(), $data))
            ->successNotificationTitle('Nova versão da regra registrada e competências futuras recalculadas.');
    }

    protected function makeOnDemandOccurrenceAction(): Action
    {
        return Action::make('create_on_demand_occurrence')
            ->label('Gerar ocorrência')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->modalHeading('Gerar ocorrência sob demanda')
            ->schema([
                DatePicker::make('competence_date')
                    ->label('Competência')
                    ->required(),
                DatePicker::make('due_date')
                    ->label('Vencimento')
                    ->required(),
                Select::make('responsible_user_id')
                    ->label('Responsável')
                    ->relationship('responsibleUser', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->fillForm(fn (ObligationSeries $record): array => [
                'responsible_user_id' => $record->responsible_user_id,
            ])
            ->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::Active && $record->frequency === ObligationFrequency::OnDemand && $this->canCreateSeries())
            ->authorize(fn (): bool => $this->canCreateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->createOnDemandOccurrence($record, auth()->user(), $data))
            ->successNotificationTitle('Ocorrência sob demanda criada.');
    }

    protected function makePauseAction(): Action
    {
        return $this->reasonAction(
            'pause_series',
            'Pausar recorrência',
            'Pausar recorrência',
            'pause_reason',
            fn (ObligationSeries $record, array $data) => $this->seriesService()->pause($record, auth()->user(), $data['pause_reason'] ?? null),
        )->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::Active && $this->canUpdateSeries());
    }

    protected function makeReactivateAction(): Action
    {
        return $this->reasonAction(
            'reactivate_series',
            'Reativar recorrência',
            'Reativar recorrência',
            'reactivation_reason',
            fn (ObligationSeries $record, array $data) => $this->seriesService()->reactivate($record, auth()->user(), $data['reactivation_reason'] ?? null),
        )->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::Paused && $this->canUpdateSeries());
    }

    protected function makeCloseAction(): Action
    {
        return $this->reasonAction(
            'close_series',
            'Encerrar recorrência',
            'Encerrar recorrência',
            'close_reason',
            fn (ObligationSeries $record, array $data) => $this->seriesService()->close($record, auth()->user(), $data['close_reason'] ?? null),
        )
            ->color('danger')
            ->visible(fn (ObligationSeries $record): bool => $record->status !== ObligationSeriesStatus::Closed && $this->canUpdateSeries());
    }

    protected function makeHistoryAction(): Action
    {
        return Action::make('series_history')
            ->label('Histórico da série')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading('Histórico da Recorrência')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (ObligationSeries $record) => view('filament.obligations.series-history', [
                'activities' => $record->activities()->with('causer')->latest()->limit(100)->get(),
            ]));
    }

    protected function reasonAction(
        string $name,
        string $label,
        string $heading,
        string $field,
        callable $callback,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->modalHeading($heading)
            ->schema([
                Textarea::make($field)
                    ->label('Motivo')
                    ->required()
                    ->rows(3),
            ])
            ->authorize(fn (): bool => $this->canUpdateSeries())
            ->action($callback);
    }

    /** @return array<string, mixed> */
    protected function seriesFormData(ObligationSeries $series): array
    {
        return array_merge($series->attributesToArray(), [
            'frequency' => $series->frequency?->value,
            'due_rule_type' => $series->due_rule_type?->value,
            'invalid_day_policy' => $series->invalid_day_policy?->value,
            'starts_on' => $series->starts_on?->toDateString(),
            'ends_on' => $series->ends_on?->toDateString(),
        ]);
    }

    protected function nextOccurrenceLabel(ObligationSeries $series): string
    {
        if ($series->status === ObligationSeriesStatus::AwaitingConfiguration) {
            return 'Aguardando configuração';
        }

        if ($series->frequency === ObligationFrequency::OnDemand) {
            return 'Sob demanda';
        }

        if ($series->status !== ObligationSeriesStatus::Active) {
            return 'Sem nova geração';
        }

        $next = app(ObligationScheduleCalculator::class)->nextOccurrence($series);

        return $next === null
            ? 'Nenhuma dentro da vigência'
            : sprintf('%s · comp. %s', $next['due_date']->format('d/m/Y'), $next['competence_date']->format('m/Y'));
    }

    protected function canCreateSeries(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsCreate->value) ?? false;
    }

    protected function canUpdateSeries(): bool
    {
        return auth()->user()?->can(AccessPermission::ObligationsUpdate->value) ?? false;
    }

    protected function seriesService(): ObligationSeriesService
    {
        return app(ObligationSeriesService::class);
    }
}
