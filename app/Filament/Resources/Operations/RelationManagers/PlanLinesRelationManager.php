<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\MeasurementPlanLine;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlanLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'planLines';

    protected static ?string $title = 'Cronograma (Acompanhamento)';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Plano de medições cadastrado')
            ->description('Acompanhe os percentuais previstos e realizados por medição. O realizado é preenchido automaticamente na validação da medição (etapa de Engenharia).')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['planSet.construction', 'measurement']))
            ->defaultSort('sequence_number')
            ->columns([
                TextColumn::make('planSet.construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('sequence_number')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('planned_monthly_percent')
                    ->label('Prev. mensal (%)')
                    ->numeric(2),
                TextColumn::make('planned_cumulative_percent')
                    ->label('Prev. acum. (%)')
                    ->numeric(2),
                TextColumn::make('realized_monthly_percent')
                    ->label('Real. mensal (%)')
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('realized_cumulative_percent')
                    ->label('Real. acum. (%)')
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('evolution_diff_percent')
                    ->label('Diferença (%)')
                    ->numeric(2)
                    ->color(fn (MeasurementPlanLine $record): string => match (true) {
                        (float) $record->evolution_diff_percent > 0 => 'success',
                        (float) $record->evolution_diff_percent < 0 => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('evolution_trend')
                    ->label('Tendência')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        MeasurementPlanLine::TREND_AHEAD => 'success',
                        MeasurementPlanLine::TREND_BEHIND => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('measurement_date')
                    ->label('Data prevista')
                    ->date('m/Y')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Realizado')
                    ->badge()
                    ->state(fn (MeasurementPlanLine $record): string => (float) $record->realized_monthly_percent > 0 || (float) $record->realized_cumulative_percent > 0
                        ? 'Realizado informado'
                        : 'Pendente')
                    ->color(fn (string $state): string => $state === 'Realizado informado' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('editPlanned')
                    ->label('Editar previsto')
                    ->icon('heroicon-o-calculator')
                    ->fillForm(fn (MeasurementPlanLine $record): array => [
                        'planned_monthly_percent' => $record->planned_monthly_percent,
                        'planned_cumulative_percent' => $record->planned_cumulative_percent,
                        'measurement_date' => $record->measurement_date?->format('Y-m'),
                    ])
                    ->schema([
                        TextInput::make('planned_monthly_percent')
                            ->label('Previsto mensal (%)')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (mixed $state, Set $set, MeasurementPlanLine $record): void {
                                $set('planned_cumulative_percent', round(static::previousCumulativeFor($record) + (float) $state, 2));
                            }),
                        TextInput::make('planned_cumulative_percent')
                            ->label('Previsto acum. (%)')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->helperText('Sugerido a partir do mensal; ajuste se necessário.'),
                        TextInput::make('measurement_date')
                            ->label('Data prevista (mês/ano)')
                            ->type('month'),
                    ])
                    ->action(function (array $data, MeasurementPlanLine $record): void {
                        $data['measurement_date'] = filled($data['measurement_date'] ?? null)
                            ? \Illuminate\Support\Carbon::parse($data['measurement_date'].'-01')->toDateString()
                            : null;

                        $record->update($data);

                        Notification::make()
                            ->success()
                            ->title('Previsto atualizado.')
                            ->send();
                    }),

                Action::make('openMeasurement')
                    ->label('Arquivo')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (MeasurementPlanLine $record): bool => filled($record->measurement_id))
                    ->url(fn (MeasurementPlanLine $record): ?string => $record->measurement_id
                        ? MeasurementResource::getUrl('view', ['record' => $record->measurement_id])
                        : null),
            ]);
    }

    protected static function previousCumulativeFor(MeasurementPlanLine $record): float
    {
        return (float) MeasurementPlanLine::query()
            ->where('plan_set_id', $record->plan_set_id)
            ->where('sequence_number', '<', $record->sequence_number)
            ->orderByDesc('sequence_number')
            ->value('planned_cumulative_percent');
    }
}
