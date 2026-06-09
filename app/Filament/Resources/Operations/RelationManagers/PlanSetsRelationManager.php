<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Models\MeasurementPlanSet;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class PlanSetsRelationManager extends RelationManager
{
    protected static string $relationship = 'planSets';

    protected static ?string $title = 'Planos de Medição (Evolução da Obra)';

    public function form(Schema $schema): Schema
    {
        $emissionId = $this->getOwnerRecord()->emission_id;

        return $schema->components([
            Section::make('Plano de Medição')
                ->columns(3)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome do Plano')
                        ->required()
                        ->maxLength(120),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (EloquentBuilder $query): EloquentBuilder => $query->where('emission_id', $emissionId),
                        )
                        ->searchable()
                        ->preload()
                        ->helperText('Um plano por empreendimento. A mesma medição cobre todos os planos da operação.'),

                    Toggle::make('is_default')
                        ->label('Plano padrão')
                        ->inline(false),

                    static::moneyField('construction_fund_amount', 'Fundo de Obra'),
                    static::moneyField('initial_incurred_amount', 'Incorrido Inicial'),
                ]),

            Section::make('Cronograma físico — Previsto')
                ->description('Pré-cadastre as medições com o previsto. O Realizado é lançado depois, na aba "Cronograma (Acompanhamento)".')
                ->schema([
                    Repeater::make('lines')
                        ->relationship()
                        ->label('Medições do plano')
                        ->orderColumn('sequence_number')
                        ->defaultItems(0)
                        ->addActionLabel('Adicionar medição')
                        ->columns(5)
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
                                ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? \Illuminate\Support\Carbon::parse($state)->format('Y-m') : null)
                                ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? \Illuminate\Support\Carbon::parse($state.'-01')->toDateString() : null),
                        ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Plano')
                    ->searchable(),
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—'),
                IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean(),
                TextColumn::make('lines_count')
                    ->label('Linhas')
                    ->counts('lines'),
                TextColumn::make('construction_fund_amount')
                    ->label('Fundo de Obra')
                    ->money('BRL')
                    ->placeholder('—'),
                TextColumn::make('incurred_amount')
                    ->label('Custo Incorrido')
                    ->money('BRL')
                    ->state(fn (MeasurementPlanSet $record): float => $record->incurred_amount),
                TextColumn::make('available_balance')
                    ->label('Saldo Disponível')
                    ->money('BRL')
                    ->state(fn (MeasurementPlanSet $record): float => $record->available_balance)
                    ->color(fn (float $state): string => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('used_percentage')
                    ->label('% Utilizada')
                    ->suffix('%')
                    ->numeric(2)
                    ->state(fn (MeasurementPlanSet $record): float => $record->used_percentage),
            ])
            ->headerActions([
                CreateAction::make()->label('Novo Plano'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('addLines')
                    ->label('Adicionar medições')
                    ->icon('heroicon-o-plus-circle')
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
                        $startDate = \Illuminate\Support\Carbon::parse($data['start_date'].'-01');

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
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            ->maxValue(100);
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('1.000,00');
    }
}
