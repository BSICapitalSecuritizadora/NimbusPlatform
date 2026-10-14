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
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type ReanalysisEntry array{measurement_id: int, revision_label: string, reference_month: string|null, basis_points: int, plan_line_lineage_key: string|null, invalidated_at: CarbonImmutable|null}
 */
class PlanLinesRelationManager extends RelationManager
{
    private const REALIZED_STATE = 'Realizado informado';

    private const REANALYSIS_STATE = 'Em reanálise';

    protected static string $relationship = 'planLines';

    protected static ?string $title = 'Cronograma (Acompanhamento)';

    /** @var array<int, MeasurementPhysicalProgress>|null */
    private ?array $physicalProgress = null;

    /** @var array<string, int>|null medição que ocupa cada linhagem da operação */
    private ?array $claimHolders = null;

    /**
     * A contribuição aprovada antes e fora do avanço reconhecido enquanto a
     * Engenharia reanalisa a medição, por plano: uma leitura por operação e
     * por requisição, como o progresso.
     *
     * @var array<int, list<ReanalysisEntry>>|null
     */
    private ?array $reanalysisContributions = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Plano de medições cadastrado')
            ->description('Acompanhe os percentuais previstos e realizados por medição. Cada competência aparece pela versão do plano que vale para ela — a mesma das medições daquela competência; o realizado vem das medições com a Engenharia vigente e parte do avanço físico inicial do plano; um mês sem medição mantém o último acumulado.')
            // Cada competência pela versão que a rege: junho pela V1 mesmo com a
            // V2 vigente desde julho. O previsto é o mesmo da cópia que a
            // vigente traz (a ativação recusa reescrever o passado), e a coluna
            // Versão passa a dizer o mesmo que a medição da competência. O
            // previsto muda por revisão, na aba Versões dos Planos.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where(fn (Builder $schedule): Builder => $schedule
                    ->governingTheirCompetence()
                    // Linha sem mês não tem competência; a da vigente continua à vista.
                    ->orWhere(fn (Builder $undated): Builder => $undated->whereNull('measurement_date')->ofActiveVersions()))
                ->with(['planSet.construction', 'version']))
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
                // A linha cuja medição a Engenharia reanalisa não está
                // pendente: a contribuição aprovada antes saiu do avanço
                // reconhecido e espera a nova decisão. Só pontos percentuais e
                // data -- quem vê a operação nem sempre vê a medição. A linha
                // que já tem contribuição reconhecida (outra medição a mediu
                // depois que a reanalisada mudou de linha) mostra o realizado.
                TextColumn::make('status')
                    ->label('Realizado')
                    ->badge()
                    ->state(fn (MeasurementPlanLine $record): string => $this->lineStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        self::REALIZED_STATE => 'success',
                        self::REANALYSIS_STATE => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (MeasurementPlanLine $record): ?string => $this->reanalysisTooltip($record)),
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
     * Contribuições em reanálise medidas nesta linha -- pela linhagem, que
     * segue a medição prevista nas cópias das versões do plano. A linhagem
     * que o registro invalidado não guardou não casa com linha nenhuma.
     *
     * @return list<ReanalysisEntry>
     */
    private function reanalysisFor(MeasurementPlanLine $record): array
    {
        $lineage = (string) $record->lineage_key;

        if ($lineage === '') {
            return [];
        }

        $this->reanalysisContributions ??= app(MeasurementPhysicalProgressService::class)->reanalysisContributions((int) $this->getOwnerRecord()->getKey());

        return array_values(array_filter(
            $this->reanalysisContributions[(int) $record->plan_set_id] ?? [],
            fn (array $entry): bool => $entry['plan_line_lineage_key'] === $lineage,
        ));
    }

    private function lineStatus(MeasurementPlanLine $record): string
    {
        return match (true) {
            $this->realizedMonthlyBasisPoints($record) !== null => self::REALIZED_STATE,
            $this->reanalysisFor($record) !== [] => self::REANALYSIS_STATE,
            default => 'Pendente',
        };
    }

    private function reanalysisTooltip(MeasurementPlanLine $record): ?string
    {
        $reanalysis = $this->lineStatus($record) === self::REANALYSIS_STATE ? $this->reanalysisFor($record) : [];

        if ($reanalysis === []) {
            return null;
        }

        $invalidatedOn = collect($reanalysis)
            ->pluck('invalidated_at')
            ->filter()
            ->map(fn (CarbonImmutable $invalidatedAt): string => BusinessTime::at($invalidatedAt)->format('d/m/Y'))
            ->unique()
            ->implode(', ');

        return sprintf(
            '%s aprovados antes ficam fora do avanço reconhecido até a nova aprovação da Engenharia%s.',
            $this->percentagePoints(array_sum(array_column($reanalysis, 'basis_points'))),
            $invalidatedOn === '' ? '' : " (registro invalidado em {$invalidatedOn})",
        );
    }

    /**
     * Basis points em pontos percentuais: 1200 → '12,00 p.p.'.
     */
    private function percentagePoints(int $basisPoints): string
    {
        return str_replace('%', '', MeasurementPhysicalProgress::format($basisPoints)).' p.p.';
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
