<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Services\MeasurementPhysicalProgressService;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlanLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'planLines';

    protected static ?string $title = 'Cronograma (Acompanhamento)';

    /** @var array<int, MeasurementPhysicalProgress>|null */
    private ?array $physicalProgress = null;

    /** @var array<string, int>|null medição que ocupa cada linhagem da operação */
    private ?array $claimHolders = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Plano de medições cadastrado')
            ->description('Acompanhe os percentuais previstos e realizados por medição. O cronograma é o da versão vigente de cada plano; o realizado vem das medições com a Engenharia vigente e parte do avanço físico inicial do plano; um mês sem medição mantém o último acumulado.')
            // Só a versão vigente: ela traz, igual, o previsto das competências
            // anteriores à vigência (a ativação recusa reescrevê-lo). O
            // previsto muda por revisão, na aba Versões dos Planos.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->ofActiveVersions()->with(['planSet.construction', 'version']))
            // A ordem da conta: competência, e no mesmo mês a sequência.
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('measurement_date')->orderBy('sequence_number'))
            ->columns([
                TextColumn::make('planSet.construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('version.version_number')
                    ->label('Versão')
                    ->formatStateUsing(fn (mixed $state): string => 'V'.(int) $state),
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
                // A medição da linha é a da linhagem: a que a Engenharia aprovou
                // nela -- talvez numa versão anterior do plano --, ou a que a
                // ocupa agora.
                Action::make('openMeasurement')
                    ->label('Arquivo')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (MeasurementPlanLine $record): bool => $this->lineageMeasurementId($record) !== null)
                    ->url(fn (MeasurementPlanLine $record): ?string => ($measurementId = $this->lineageMeasurementId($record)) === null
                        ? null
                        : MeasurementResource::getUrl('view', ['record' => $measurementId])),
            ]);
    }

    /**
     * Avanço vigente que esta linha recebeu; `null` quando nenhuma medição com
     * Engenharia vigente a mediu -- não medido não é 0%.
     */
    private function realizedMonthlyBasisPoints(MeasurementPlanLine $record): ?int
    {
        $contributions = $this->progressFor($record)->contributionsForLineage((string) $record->lineage_key);

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
     * A medição da linhagem: a que a Engenharia aprovou nela, em qualquer
     * versão do plano, ou a que a ocupa agora. A linha copiada numa revisão
     * não guarda `measurement_id`.
     */
    private function lineageMeasurementId(MeasurementPlanLine $record): ?int
    {
        $approved = $this->progressFor($record)->lineageClaimant((string) $record->lineage_key);

        if ($approved !== null) {
            return $approved;
        }

        $this->claimHolders ??= MeasurementAsset::query()
            ->whereNotNull('line_claim_key')
            ->whereHas('measurement', fn (Builder $measurements): Builder => $measurements->where('operation_id', $this->getOwnerRecord()->getKey()))
            ->pluck('measurement_id', 'line_claim_key')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $this->claimHolders[(string) $record->lineage_key] ?? null;
    }
}
