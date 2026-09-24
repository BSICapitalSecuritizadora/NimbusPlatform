<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Models\MeasurementPlanSet;
use App\Models\User;
use App\Services\OperationContextVisibilityService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class PlanSetsRelationManager extends RelationManager
{
    protected static string $relationship = 'planSets';

    protected static ?string $title = 'Planos de Medição (Evolução da Obra)';

    public function form(Schema $schema): Schema
    {
        $emissionId = $this->getOwnerRecord()->emission_id;

        return $schema
            ->columns(['default' => 1, 'lg' => 12])
            ->components([
                Section::make('Plano de Medição')
                    ->contained(false)
                    ->columnSpan(['lg' => 5])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'bsi-plan-set-data'])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Plano')
                            ->required()
                            ->maxLength(120)
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-name-field'])
                            ->columnSpanFull(),

                        Select::make('construction_id')
                            ->label('Empreendimento')
                            ->placeholder('Selecione o empreendimento...')
                            ->options(fn (): array => $this->constructionOptions($emissionId))
                            ->getSearchResultsUsing(fn (string $search): array => $this->constructionOptions($emissionId, $search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->constructionLabel($value, $emissionId))
                            ->searchable()
                            ->preload()
                            ->helperText('Um plano por empreendimento. A mesma medição pode atender todos os planos da operação.')
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-development-field'])
                            ->columnSpanFull(),

                        Toggle::make('is_default')
                            ->label('Plano padrão')
                            ->helperText('Definir como plano padrão desta operação.')
                            ->inline()
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-default-toggle-wrp'])
                            ->columnSpanFull(),

                        static::moneyField('construction_fund_amount', 'Fundo de Obra'),
                        static::moneyField('initial_incurred_amount', 'Incorrido Inicial'),
                    ]),

                Section::make('Cronograma físico — Previsto')
                    ->description('Pré-cadastre as medições previstas para este plano. As realizadas serão lançadas posteriormente em Cronograma (Acompanhamento).')
                    ->contained(false)
                    ->columnSpan(['lg' => 7])
                    ->extraAttributes(['class' => 'bsi-plan-set-schedule'])
                    ->schema([
                        Placeholder::make('lines_empty_state')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<div class="bsi-plan-lines-empty" role="region" aria-label="Cronograma vazio"><div class="bsi-plan-lines-empty-icon" aria-hidden="true"><svg class="bsi-plan-lines-empty-svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" /></svg></div><div class="bsi-plan-lines-empty-body"><p class="bsi-plan-lines-empty-title">Nenhuma medição prevista cadastrada.</p><p class="bsi-plan-lines-empty-subtitle">Adicione a primeira medição para estruturar o cronograma físico.</p></div></div>'
                            ))
                            ->visible(fn (Get $get): bool => blank($get('lines'))),

                        Repeater::make('lines')
                            ->relationship()
                            ->label('Medições do plano')
                            ->orderColumn('sequence_number')
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar medição')
                            ->addActionAlignment(Alignment::End)
                            ->addAction(fn (Action $action): Action => $action->outlined()->icon('heroicon-o-plus')->extraAttributes(['class' => 'bsi-plan-add-line-btn']))
                            ->compact()
                            ->extraAttributes(['class' => 'bsi-plan-lines'])
                            ->table([
                                TableColumn::make('Medição #')->width(88)->markAsRequired(),
                                TableColumn::make('Mensal (%)')->width(104),
                                TableColumn::make('Acum. (%)')->width(104),
                                TableColumn::make('Realiz. inicial (%)')->width(136),
                                TableColumn::make('Mês/Ano')->width(124),
                            ])
                            ->schema([
                                TextInput::make('sequence_number')
                                    ->label('Medição #')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),
                                static::percentField('planned_monthly_percent', 'Previsto mensal (%)'),
                                static::percentField('planned_cumulative_percent', 'Previsto acum. (%)'),
                                static::percentField('initial_realized_cumulative_percent', 'Realizado acum. inicial (%)')
                                    ->helperText('Opcional — para obras já em andamento.'),
                                TextInput::make('measurement_date')
                                    ->label('Data prevista (mês/ano)')
                                    ->type('month')
                                    ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? Carbon::parse($state)->format('Y-m') : null)
                                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? Carbon::parse($state.'-01')->toDateString() : null),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->searchPlaceholder('Buscar plano...')
            ->columns([
                TextColumn::make('name')
                    ->label('Plano')
                    ->weight('semiBold')
                    ->searchable()
                    ->extraCellAttributes(['class' => 'bsi-plan-name-cell']),
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->label('Estrutura')
                    ->counts('lines')
                    ->formatStateUsing(fn (mixed $state): string => ((int) $state).' '.((int) $state === 1 ? 'linha' : 'linhas'))
                    ->icon(fn (MeasurementPlanSet $record): string => $record->is_default ? 'heroicon-o-check-circle' : 'heroicon-o-x-mark')
                    ->iconColor(fn (MeasurementPlanSet $record): string => $record->is_default ? 'success' : 'gray')
                    ->description(fn (MeasurementPlanSet $record): string => $record->is_default ? 'Padrão' : 'Não padrão'),
                ViewColumn::make('financials')
                    ->label('Financeiro')
                    ->view('filament.tables.plan-set-financials')
                    ->extraCellAttributes(['class' => 'bsi-plan-financials-cell']),
                ViewColumn::make('used_percentage')
                    ->label('% Utilizada')
                    ->view('filament.tables.plan-set-utilization')
                    ->extraCellAttributes(['class' => 'bsi-plan-utilization-cell']),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Novo Plano')
                    ->modalHeading('Criar Plano de Medição')
                    ->modalDescription('Defina os dados do plano e cadastre o cronograma físico previsto.')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitActionLabel('Criar')
                    ->modalCancelActionLabel('Cancelar')
                    ->createAnotherAction(fn (Action $action): Action => $action->outlined())
                    ->modalCancelAction(fn (Action $action): Action => $action->outlined())
                    ->stickyModalFooter()
                    ->extraModalWindowAttributes(['class' => 'bsi-plan-set-modal-window'])
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord())),
            ])
            ->recordActions([
                EditAction::make()
                    // Mesma largura do modal de criação: o form() é compartilhado entre os dois.
                    ->modalWidth(Width::SevenExtraLarge)
                    ->stickyModalFooter()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord())),
                Action::make('addLines')
                    ->label('Adicionar medições')
                    ->icon('heroicon-o-plus-circle')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->modalHeading('Gerar medições para o plano')
                    ->modalDescription('Crie múltiplas linhas de medição previstas para este plano de uma só vez.')
                    ->form([
                        TextInput::make('count')
                            ->label('Quantidade de medições')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->default(12)
                            ->required(),
                        TextInput::make('start_date')
                            ->label('Mês da primeira medição')
                            ->type('month')
                            ->default(now()->format('Y-m')),
                    ])
                    ->action(function (array $data, MeasurementPlanSet $record): void {
                        $count = (int) $data['count'];
                        $startDate = Carbon::parse($data['start_date'].'-01');

                        $lastSequence = $record->lines()->max('sequence_number') ?? 0;

                        for ($i = 1; $i <= $count; $i++) {
                            $record->lines()->create([
                                'operation_id' => $record->operation_id,
                                'sequence_number' => $lastSequence + $i,
                                'measurement_date' => $startDate->copy()->addMonths($i - 1),
                                'planned_monthly_percent' => 0,
                                'planned_cumulative_percent' => 0,
                            ]);
                        }

                        Notification::make()
                            ->success()
                            ->title("{$count} medições adicionadas ao plano.")
                            ->send();
                    }),
                DeleteAction::make()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord())),
                ]),
            ]);
    }

    protected static function percentField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->suffix('%')
            ->default(0)
            ->minValue(0)
            ->maxValue(100)
            ->extraInputAttributes(['class' => 'tabular-nums']);
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-money-field'])
            ->extraInputAttributes(['class' => 'tabular-nums font-mono'])
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('1.000,00');
    }

    /**
     * @return array<int, string>
     */
    protected function constructionOptions(mixed $emissionId, ?string $search = null): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $query = app(OperationContextVisibilityService::class)
            ->visibleConstructions($user, $emissionId)
            ->orderBy('development_name');

        if (filled($search)) {
            $query->where('development_name', 'like', '%'.trim((string) $search).'%');
        }

        return $query->limit(50)->pluck('development_name', 'id')->all();
    }

    protected function constructionLabel(mixed $constructionId, mixed $emissionId): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OperationContextVisibilityService::class)
                ->findVisibleConstruction($user, $constructionId, $emissionId)?->development_name
            : null;
    }
}
