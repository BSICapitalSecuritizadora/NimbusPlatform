<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Enums\AccessPermission;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationSeriesStatus;
use App\Filament\Resources\Emissions\Schemas\ObligationSeriesFormFields;
use App\Models\Obligation;
use App\Models\ObligationSeries;
use App\Services\Obligations\ObligationCalendarReproducibilityService;
use App\Services\Obligations\ObligationScheduleCalculator;
use App\Services\Obligations\ObligationSeriesService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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
        return $schema
            ->columns(1)
            ->components([
                Section::make('Definição da Recorrência')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2, 'md' => 3])->schema([
                            TextEntry::make('title')
                                ->label('Obrigação jurídica')
                                ->weight('bold')
                                ->size('lg')
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                            TextEntry::make('status')
                                ->label('Status da série')
                                ->badge()
                                ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationSeriesStatus
                                    ? $state->label()
                                    : (ObligationSeriesStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                                ->color(fn (mixed $state): string => match ($state instanceof ObligationSeriesStatus ? $state : ObligationSeriesStatus::tryFrom((string) $state)) {
                                    ObligationSeriesStatus::Active => 'success',
                                    ObligationSeriesStatus::AwaitingConfiguration => 'warning',
                                    ObligationSeriesStatus::Paused => 'gray',
                                    ObligationSeriesStatus::Closed => 'gray',
                                    default => 'warning',
                                })
                                ->columnSpan(['default' => 1, 'sm' => 1]),
                            TextEntry::make('frequency')
                                ->label('Recorrência')
                                ->badge()
                                ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationFrequency
                                    ? $state->label()
                                    : (ObligationFrequency::tryFrom((string) $state)?->label() ?? 'A definir'))
                                ->color(fn (mixed $state): string => $state ? 'info' : 'gray'),
                            TextEntry::make('starts_on')
                                ->label('Competência inicial')
                                ->date('m/Y')
                                ->placeholder('A definir'),
                            TextEntry::make('ends_on')
                                ->label('Término')
                                ->date('d/m/Y')
                                ->placeholder('Sem término definido'),
                            TextEntry::make('responsibleUser.name')
                                ->label('Responsável padrão')
                                ->placeholder('Não atribuído')
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                            TextEntry::make('responsible_area')
                                ->label('Área padrão')
                                ->placeholder('—')
                                ->columnSpan(['default' => 1, 'sm' => 1]),
                            TextEntry::make('rule_summary')
                                ->label('Regra executável confirmada')
                                ->placeholder('Regra executável ainda não configurada.')
                                ->columnSpanFull(),
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
            ->description(fn (): Htmlable => $this->seriesDescription())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['responsibleUser', 'rules'])
                ->withCount('occurrences'))
            ->columns([
                TextColumn::make('title')
                    ->label('Série de obrigação')
                    ->description(fn (ObligationSeries $record): ?string => filled($record->obligation_category) ? $record->obligation_category : ($record->obligation_type ?? 'Obrigação recorrente'))
                    ->searchable()
                    ->weight('bold')
                    ->tooltip(fn (ObligationSeries $record): string => $record->title)
                    ->wrap(),
                TextColumn::make('rule_summary')
                    ->label('Recorrência & Regra')
                    ->state(fn (ObligationSeries $record): string => $record->frequency?->label() ?? 'A definir')
                    ->description(fn (ObligationSeries $record): string => $record->rule_summary)
                    ->tooltip(fn (ObligationSeries $record): ?string => $record->due_rule ?? $record->rule_summary)
                    ->wrap(),
                TextColumn::make('validity')
                    ->label('Vigência')
                    ->state(fn (ObligationSeries $record): string => $record->starts_on ? 'Desde '.$record->starts_on->format('m/Y') : 'Início a definir')
                    ->description(fn (ObligationSeries $record): string => $record->ends_on ? 'Até '.$record->ends_on->format('d/m/Y') : 'Sem término definido')
                    ->tooltip(fn (ObligationSeries $record): string => sprintf(
                        'Competência inicial: %s · Término: %s',
                        $record->starts_on ? $record->starts_on->format('m/Y') : 'Não definido',
                        $record->ends_on ? $record->ends_on->format('d/m/Y') : 'Indeterminado',
                    )),
                TextColumn::make('next_occurrence')
                    ->label('Próxima ocorrência')
                    ->state(fn (ObligationSeries $record): string => $this->nextOccurrenceLabel($record))
                    ->badge()
                    ->color(fn (ObligationSeries $record): string => match (true) {
                        $record->status === ObligationSeriesStatus::AwaitingConfiguration => 'warning',
                        $record->status === ObligationSeriesStatus::Active => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('occurrences_count')
                    ->label('Competências')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => "{$state} comp.")
                    ->color('gray')
                    ->tooltip(fn (int $state): string => "{$state} competência(s) materializada(s) nesta série"),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof ObligationSeriesStatus
                        ? $state->label()
                        : (ObligationSeriesStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn (mixed $state): string => match ($state instanceof ObligationSeriesStatus ? $state : ObligationSeriesStatus::tryFrom((string) $state)) {
                        ObligationSeriesStatus::Active => 'success',
                        ObligationSeriesStatus::AwaitingConfiguration => 'warning',
                        ObligationSeriesStatus::Paused => 'gray',
                        ObligationSeriesStatus::Closed => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável padrão')
                    ->placeholder('—')
                    ->description(fn (ObligationSeries $record): ?string => $record->responsible_area ?? $record->responsible_party)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('frequency')
                    ->label('Recorrência (isolada)')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('starts_on')
                    ->label('Início')
                    ->date('m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ends_on')
                    ->label('Término')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->searchPlaceholder('Buscar por título ou regra da série...')
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
                    ->icon('heroicon-o-plus')
                    ->color('warning')
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
                $this->makeConfigureAction(),
                $this->makeAnchorEventAction(),
                $this->makeOnDemandOccurrenceAction(),
                Action::make('occurrences')
                    ->label('Competências')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->modalHeading(fn (ObligationSeries $record): string => 'Competências — '.$record->title)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(function (ObligationSeries $record) {
                        $occurrences = $record->occurrences()
                            ->getQuery()
                            ->reorder('competence_date', 'desc')
                            ->with('responsibleUser')
                            ->withCount('evidences')
                            ->get();

                        return view('filament.obligations.series-occurrences', [
                            'series' => $record,
                            'occurrences' => $occurrences,
                            'calendarAssessments' => $occurrences->mapWithKeys(
                                fn (Obligation $occurrence): array => [
                                    $occurrence->getKey() => app(ObligationCalendarReproducibilityService::class)->assess($occurrence),
                                ]
                            ),
                        ]);
                    }),
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Abrir série')
                        ->modalHeading(fn (ObligationSeries $record): string => 'Ficha da Recorrência')
                        ->modalDescription(fn (ObligationSeries $record): string => $record->title)
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalCancelActionLabel('Fechar'),
                    $this->makeEditDefinitionAction(),
                    $this->makeReviseRuleAction(),
                    $this->makePauseAction(),
                    $this->makeReactivateAction(),
                    $this->makeCloseAction(),
                    $this->makeHistoryAction(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Mais ações'),
            ])
            ->emptyStateHeading('Nenhuma recorrência cadastrada')
            ->emptyStateDescription('Crie uma série recorrente para automatizar o acompanhamento de obrigações periódicas.')
            ->emptyStateIcon('heroicon-o-arrow-path');
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
            ->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::Active
                && $record->frequency === ObligationFrequency::OnDemand
                && ! $record->due_rule_type?->dependsOnAnchorEvent()
                && $this->canCreateSeries())
            ->authorize(fn (): bool => $this->canCreateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->createOnDemandOccurrence($record, auth()->user(), $data))
            ->successNotificationTitle('Ocorrência sob demanda criada.');
    }

    protected function makeAnchorEventAction(): Action
    {
        return Action::make('record_anchor_event')
            ->label('Registrar evento')
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->modalHeading('Registrar evento contratual')
            ->modalDescription('Informe quando a âncora contratual ocorreu. O vencimento será calculado com a versão da regra vigente nessa data.')
            ->schema([
                TextInput::make('event_name')
                    ->label('Evento ocorrido')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('occurred_on')
                    ->label('Data do evento')
                    ->required(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->fillForm(fn (ObligationSeries $record): array => [
                'event_name' => $record->anchor_description,
                'occurred_on' => now()->toDateString(),
            ])
            ->visible(fn (ObligationSeries $record): bool => $record->status === ObligationSeriesStatus::Active
                && $record->due_rule_type?->dependsOnAnchorEvent()
                && $this->canCreateSeries())
            ->authorize(fn (): bool => $this->canCreateSeries())
            ->action(fn (ObligationSeries $record, array $data) => $this->seriesService()->recordAnchorEvent($record, auth()->user(), $data))
            ->successNotificationTitle('Evento registrado e prazo materializado.');
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
            'relative_offset_direction' => $series->relative_offset_direction?->value,
            'initial_date_inclusion' => $series->initial_date_inclusion?->value,
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

    protected function seriesDescription(): Htmlable
    {
        $emission = $this->getOwnerRecord();
        $total = $emission->obligationSeries()->count();
        $active = $emission->obligationSeries()->where('status', ObligationSeriesStatus::Active)->count();
        $awaiting = $emission->obligationSeries()->where('status', ObligationSeriesStatus::AwaitingConfiguration)->count();
        $paused = $emission->obligationSeries()->where('status', ObligationSeriesStatus::Paused)->count();

        $summaryHtml = '';
        if ($total > 0) {
            $summaryHtml = sprintf(
                '<div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-md bg-[#0c232e] px-2.5 py-1 text-slate-300 border border-[#1d4554]/50 font-medium">%d série(s) cadastrada(s)</span>
                    <span class="rounded-md bg-emerald-950/60 px-2.5 py-1 text-emerald-300 border border-emerald-500/30 font-medium">%d ativa(s)</span>
                    %s
                    %s
                </div>',
                $total,
                $active,
                $awaiting > 0
                    ? sprintf('<span class="rounded-md bg-amber-950/60 px-2.5 py-1 text-amber-300 border border-amber-500/30 font-medium">%d aguardando configuração</span>', $awaiting)
                    : '',
                $paused > 0
                    ? sprintf('<span class="rounded-md bg-slate-800/80 px-2.5 py-1 text-slate-400 border border-slate-700 font-medium">%d pausada(s)</span>', $paused)
                    : ''
            );
        }

        return new HtmlString(
            '<span class="block text-slate-300">A série guarda a regra recorrente; cada competência materializada aparece como uma obrigação operacional independente.</span>'
            .$summaryHtml
        );
    }

    protected function seriesService(): ObligationSeriesService
    {
        return app(ObligationSeriesService::class);
    }
}
