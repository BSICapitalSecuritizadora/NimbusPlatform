<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Services\MeasurementPhysicalProgressService;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class PlanLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'planLines';

    protected static ?string $title = 'Cronograma (Acompanhamento)';

    /** @var array<int, MeasurementPhysicalProgress>|null */
    private ?array $physicalProgress = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Plano de medições cadastrado')
            ->description('Acompanhe os percentuais previstos e realizados por medição. O realizado vem das medições com a Engenharia vigente e parte do avanço físico inicial do plano; um mês sem medição mantém o último acumulado.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['planSet.construction', 'measurement']))
            // A ordem da conta: competência, e no mesmo mês a sequência.
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('measurement_date')->orderBy('sequence_number'))
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
                // Os valores gravados na linha são o retrato da última aprovação
                // da Engenharia, que pode ter deixado de valer; o que se exibe é
                // o progresso vigente.
                TextColumn::make('realized_monthly_percent')
                    ->label('Real. mensal (%)')
                    ->state(fn (MeasurementPlanLine $record): ?string => $this->percentState($this->realizedMonthlyBasisPoints($record)))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('realized_cumulative_percent')
                    ->label('Real. acum. (%)')
                    ->state(fn (MeasurementPlanLine $record): ?string => $this->percentState($this->realizedCumulativeBasisPoints($record)))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('evolution_diff_percent')
                    ->label('Diferença (%)')
                    ->state(fn (MeasurementPlanLine $record): ?string => $this->percentState($this->evolutionDiffBasisPoints($record)))
                    ->numeric(2)
                    ->placeholder('—')
                    ->color(fn (MeasurementPlanLine $record): string => match (true) {
                        ($this->evolutionDiffBasisPoints($record) ?? 0) > 0 => 'success',
                        ($this->evolutionDiffBasisPoints($record) ?? 0) < 0 => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('evolution_trend')
                    ->label('Tendência')
                    ->badge()
                    ->state(fn (MeasurementPlanLine $record): ?string => $this->evolutionTrend($record))
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
                    ->state(fn (MeasurementPlanLine $record): string => $this->realizedMonthlyBasisPoints($record) !== null
                        ? 'Realizado informado'
                        : 'Pendente')
                    ->color(fn (string $state): string => $state === 'Realizado informado' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('editPlanned')
                    ->label('Editar previsto')
                    ->icon('heroicon-o-calculator')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
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
                            ? Carbon::parse($data['measurement_date'].'-01')->toDateString()
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

    /**
     * Avanço vigente que esta linha recebeu; `null` quando nenhuma medição com
     * Engenharia vigente a mediu -- não medido não é 0%.
     */
    private function realizedMonthlyBasisPoints(MeasurementPlanLine $record): ?int
    {
        $contributions = $this->progressFor($record)->contributionsForLine((int) $record->getKey());

        if ($contributions === []) {
            return null;
        }

        return array_sum(array_map(fn (MeasurementPhysicalProgressContribution $contribution): int => $contribution->basisPoints, $contributions));
    }

    /**
     * Acumulado conhecido nesta posição do cronograma: uma linha sem medição
     * mostra o último acumulado, e não zero -- entre medições e em competências
     * já iniciadas, desde que algo se saiba até ali (o avanço inicial em vigor
     * ou uma medição). Competência futura sem medição fica em branco.
     */
    private function realizedCumulativeBasisPoints(MeasurementPlanLine $record): ?int
    {
        $progress = $this->progressFor($record);
        $sequence = (int) $record->sequence_number;
        $date = $record->measurement_date;
        $known = $progress->isWithinMeasuredRange($date, $sequence)
            || ($date !== null
                && $date->toDateString() <= BusinessTime::dateString()
                && $progress->isKnownThroughDate($date->copy()->endOfMonth()));

        return $known ? $progress->cumulativeThroughPosition($date, $sequence) : null;
    }

    /**
     * Diferença só onde houve medição: comparar o acumulado carregado de um mês
     * anterior com o previsto deste mês acusaria um atraso que ninguém mediu.
     */
    private function evolutionDiffBasisPoints(MeasurementPlanLine $record): ?int
    {
        if ($this->realizedMonthlyBasisPoints($record) === null) {
            return null;
        }

        $realized = $this->realizedCumulativeBasisPoints($record);
        $planned = IntegerMoney::basisPoints($record->planned_cumulative_percent);

        return $realized === null || $planned === null ? null : $realized - $planned;
    }

    private function evolutionTrend(MeasurementPlanLine $record): ?string
    {
        $diff = $this->evolutionDiffBasisPoints($record);

        return $diff === null ? null : MeasurementPlanLine::resolveTrend((float) $diff);
    }

    private function percentState(?int $basisPoints): ?string
    {
        return $basisPoints === null ? null : MeasurementPhysicalProgress::decimal($basisPoints);
    }

    /**
     * Uma leitura por operação e por requisição, compartilhada por todas as
     * linhas e colunas da tabela.
     */
    private function progressFor(MeasurementPlanLine $record): MeasurementPhysicalProgress
    {
        $this->physicalProgress ??= app(MeasurementPhysicalProgressService::class)->forOperation((int) $this->getOwnerRecord()->getKey());

        return $this->physicalProgress[(int) $record->plan_set_id]
            ?? new MeasurementPhysicalProgress((int) $record->plan_set_id, 0, null, []);
    }

    /**
     * O previsto acumulado é da obra inteira: na primeira linha ele parte do
     * avanço físico inicial do plano, não de zero.
     */
    protected static function previousCumulativeFor(MeasurementPlanLine $record): float
    {
        $previous = MeasurementPlanLine::query()
            ->where('plan_set_id', $record->plan_set_id)
            ->where('sequence_number', '<', $record->sequence_number)
            ->orderByDesc('sequence_number')
            ->value('planned_cumulative_percent');

        return (float) ($previous ?? MeasurementPlanSet::query()->whereKey($record->plan_set_id)->value('initial_physical_progress_percent'));
    }
}
