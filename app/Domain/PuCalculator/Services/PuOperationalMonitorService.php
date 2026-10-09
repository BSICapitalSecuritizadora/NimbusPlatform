<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuSettlementConflict;
use App\Models\PuMonitorRun;
use App\Models\PuObligationRefreshRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PuOperationalMonitorService
{
    public function __construct(
        private readonly PuIndexCoverageService $indexCoverageService,
        private readonly PuOfficialCurveFreshnessService $officialFreshness,
        private readonly PuIndexerCapabilityPolicy $indexers,
    ) {}

    /**
     * Contagem de emissoes (com parametros de PU) pela situacao da versao mais recente.
     *
     * @return array<string, int> status->value => total, mais "sem_curva" e "total"
     */
    public function statusCounts(): array
    {
        $emissionIdsWithPu = Emission::query()->whereHas('puParameter')->pluck('id');
        $total = $emissionIdsWithPu->count();

        $latestIds = EmissionPuCurveVersion::query()
            ->whereIn('emission_id', $emissionIdsWithPu)
            ->operational()
            ->selectRaw('MAX(id) as id')
            ->groupBy('emission_id')
            ->pluck('id');

        $byStatus = EmissionPuCurveVersion::query()
            ->whereIn('id', $latestIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (PuCurveStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $counts['sem_curva'] = max(0, $total - (int) $byStatus->sum());
        $counts['total'] = $total;

        return $counts;
    }

    /**
     * IDs das emissoes com parametros de PU cuja cobertura de CDI tem lacunas bloqueantes.
     *
     * @return list<int>
     */
    public function missingCdiEmissionIds(): array
    {
        return Cache::remember(
            'pu_monitor_missing_cdi_ids',
            (int) config('pu_calculator.missing_cdi_cache_seconds', 300),
            fn (): array => Emission::query()
                ->whereHas('puParameter')
                ->with('puParameter')
                ->get()
                ->filter(fn (Emission $emission): bool => $this->indexCoverageService->report($emission)->hasBlockingGaps())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
        );
    }

    public function missingCdiCount(): int
    {
        return count($this->missingCdiEmissionIds());
    }

    /**
     * @return array{pending_jobs: int, failed_pu_jobs: int, failed_jobs_total: int, stuck_versions: int}
     */
    public function queueMetrics(): array
    {
        $staleMinutes = (int) config('pu_calculator.stale_processing_minutes', 30);

        $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;

        $failedTotal = 0;
        $failedPu = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedTotal = (int) DB::table('failed_jobs')->count();
            $failedPu = (int) DB::table('failed_jobs')
                ->where(function ($query): void {
                    $query
                        ->where('payload', 'like', '%GeneratePuDailyCurveJob%')
                        ->orWhere('payload', 'like', '%ValidatePuCurveJob%');
                })
                ->count();
        }

        $stuck = (int) EmissionPuCurveVersion::query()
            ->operational()
            ->where('status', PuCurveStatus::Processing->value)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->count();

        return [
            'pending_jobs' => $pending,
            'failed_pu_jobs' => $failedPu,
            'failed_jobs_total' => $failedTotal,
            'stuck_versions' => $stuck,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EmissionPuCurveVersion>
     */
    public function recentValidations(int $limit = 5): Collection
    {
        return EmissionPuCurveVersion::query()
            ->operational()
            ->whereNotNull('validated_at')
            ->with(['emission', 'validatedBy'])
            ->latest('validated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EmissionPuCurveVersion>
     */
    public function recentHomologations(int $limit = 5): Collection
    {
        return EmissionPuCurveVersion::query()
            ->operational()
            ->where('status', PuCurveStatus::Homologated->value)
            ->whereNotNull('homologated_at')
            ->with(['emission', 'homologatedBy'])
            ->latest('homologated_at')
            ->limit($limit)
            ->get();
    }

    public function hasCriticalIssues(): bool
    {
        return $this->criticalSummary() !== [];
    }

    /**
     * Lista de problemas operacionais criticos, em texto amigavel.
     *
     * @return list<string>
     */
    public function criticalSummary(): array
    {
        $issues = [];
        $queue = $this->queueMetrics();
        $counts = $this->statusCounts();

        if ($queue['stuck_versions'] > 0) {
            $issues[] = sprintf('%d curva(s) travada(s) em processamento.', $queue['stuck_versions']);
        }

        if ($queue['failed_pu_jobs'] > 0) {
            $issues[] = sprintf('%d job(s) de PU com falha (geracao/validacao).', $queue['failed_pu_jobs']);
        }

        if (($counts[PuCurveStatus::Error->value] ?? 0) > 0) {
            $issues[] = sprintf('%d curva(s) no estado de erro.', $counts[PuCurveStatus::Error->value]);
        }

        if (($missing = $this->missingCdiCount()) > 0) {
            $issues[] = sprintf('%d emissao(oes) com CDI obrigatorio faltante.', $missing);
        }

        if (($diverged = $this->divergedGovernedCurveCount()) > 0) {
            $issues[] = sprintf(
                '%d curva(s) homologada(s) ou promovida(s) com o passado divergente: a extensao diaria esta suspensa ate o reprocessamento manual.',
                $diverged,
            );
        }

        if (($missingIndex = count($this->officialCurvesMissingIndexEmissionIds())) > 0) {
            $issues[] = sprintf(
                '%d curva(s) oficial(is) parada(s) por CDI exigido ausente ou com divulgacao atrasada.',
                $missingIndex,
            );
        }

        if (($pending = $this->pendingContractualChangeCount()) > 0) {
            $issues[] = sprintf(
                '%d curva(s) oficial(is) com mudanca contratual nao homologada: o PU oficial para na vespera da data afetada ate uma nova versao ser homologada.',
                $pending,
            );
        }

        if (($failed = $this->failedOfficialExtensionCount()) > 0) {
            $issues[] = sprintf(
                '%d curva(s) oficial(is) cuja extensao diaria falhou: o PU oficial nao avanca alem da ultima data realizada gravada.',
                $failed,
            );
        }

        if (($unsupported = $this->unsupportedIndexerOfficialCurveCount()) > 0) {
            $issues[] = sprintf(
                '%d curva(s) oficial(is) com indexador sem homologacao operacional: nenhuma obrigacao e calculada a partir dela; invalide a versao.',
                $unsupported,
            );
        }

        if (($stuckRefresh = $this->stuckObligationRefreshEmissionCount()) > 0) {
            $issues[] = sprintf(
                '%d emissao(oes) com atualizacao das obrigacoes esgotada ou bloqueada: retome com pu:obligations:recover --retry depois de tratar a causa.',
                $stuckRefresh,
            );
        }

        if (($conflicts = EmissionPuSettlementConflict::query()->open()->count()) > 0) {
            $issues[] = sprintf(
                '%d conflito(s) de liquidacao aguardando decisao: a liquidacao existente nao e sobrescrita.',
                $conflicts,
            );
        }

        if (($monitor = $this->monitorHealthIssue()) !== null) {
            $issues[] = $monitor;
        }

        return $issues;
    }

    /**
     * Curvas oficiais cujo indexador não tem homologação operacional (Fase 6):
     * só existem se gravadas antes do portão; não deveriam ser oficiais.
     */
    public function unsupportedIndexerOfficialCurveCount(): int
    {
        return EmissionPuCurveVersion::query()
            ->whereIn('id', $this->officialVersionIds())
            ->get()
            ->reject(fn (EmissionPuCurveVersion $version): bool => $this->indexers->allows(
                $this->indexers->versionIndexer($version),
                PuIndexerCapability::Homologation,
            ))
            ->count();
    }

    /**
     * Emissões com pedido de atualização das obrigações que a recuperação
     * automática não resolve mais (esgotado) ou não deve repetir (bloqueado).
     */
    public function stuckObligationRefreshEmissionCount(): int
    {
        return PuObligationRefreshRequest::query()
            ->whereIn('status', [PuObligationRefreshStatus::Exhausted->value, PuObligationRefreshStatus::Blocked->value])
            ->distinct()
            ->count('emission_id');
    }

    /**
     * O próprio monitor (Fase 6): com curva oficial em operação, a última rodada
     * que não concluiu, ou nenhuma rodada completa dentro do prazo, é problema --
     * "o monitor não rodou" nunca parece "tudo certo". Sem curva oficial nenhuma
     * (produção antes do lançamento), não há o que cobrar.
     */
    public function monitorHealthIssue(): ?string
    {
        if ($this->officialVersionIds()->isEmpty()) {
            return null;
        }

        $last = PuMonitorRun::query()->latest('id')->first();
        $staleMinutes = max(1, (int) config('pu_calculator.monitoring.monitor_stale_after_minutes', 60));
        $lastSucceeded = PuMonitorRun::query()->where('status', PuMonitorRunStatus::Succeeded->value)->latest('id')->first();

        if ($last instanceof PuMonitorRun && in_array($last->status, [PuMonitorRunStatus::Failed, PuMonitorRunStatus::Partial], true)) {
            return sprintf('O monitor operacional do PU nao concluiu a ultima verificacao (%s): incidentes podem estar desatualizados.', mb_strtolower($last->status->label()));
        }

        if (! $lastSucceeded instanceof PuMonitorRun || $lastSucceeded->finished_at === null || $lastSucceeded->finished_at->lt(now()->subMinutes($staleMinutes))) {
            return sprintf('O monitor operacional do PU nao concluiu uma verificacao completa nos ultimos %d minutos: verifique o agendador (pu:operations:monitor).', $staleMinutes);
        }

        return null;
    }

    /**
     * @return Collection<int, int>
     */
    private function officialVersionIds(): Collection
    {
        return EmissionPuCurveVersion::query()
            ->operational()
            ->homologated()
            ->selectRaw('MAX(id) as id')
            ->groupBy('emission_id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }

    /**
     * Emissões cuja curva oficial não avança porque falta uma observação de CDI
     * exigida: buraco no histórico ou divulgação que já deveria ter chegado. A
     * curva apenas atrasada (índice disponível, rotina ainda não rodou) não entra:
     * a extensão diária a resolve sozinha.
     *
     * @return list<int>
     */
    public function officialCurvesMissingIndexEmissionIds(): array
    {
        return Cache::remember(
            'pu_monitor_official_missing_index_ids',
            (int) config('pu_calculator.missing_cdi_cache_seconds', 300),
            fn (): array => Emission::query()
                ->whereHas('puCurveVersions', fn ($query) => $query->official())
                ->get()
                ->filter(fn (Emission $emission): bool => $this->officialFreshness->status($emission)->freshness
                    === PuOfficialCurveFreshness::MissingIndex)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
        );
    }

    /**
     * Curvas homologadas cuja última extensão diária tentou e não conseguiu rodar
     * (pré-requisito bloqueado ou erro de cálculo). A próxima extensão que rodar
     * limpa a marca.
     */
    public function failedOfficialExtensionCount(): int
    {
        return EmissionPuCurveVersion::query()
            ->operational()
            ->homologated()
            ->whereNotNull('extension_failed_at')
            ->count();
    }

    /**
     * Curvas OFICIAIS com mudança contratual ainda não homologada só no futuro (o
     * passado divergente já conta em {@see self::divergedGovernedCurveCount()}).
     * Uma homologada substituída por outra mais nova não conta: a pendência foi
     * resolvida pela versão nova.
     *
     * Fase 6 (dívida D4): conta também a oficial cuja ATUALIDADE é "nova versão
     * necessária" -- a que já completou o horizonte aprovado quando a mudança
     * (prorrogação do vencimento, pagamento novo) foi registrada. Essa a extensão
     * diária nunca revisita (está completa), então a marca que só a extensão
     * grava não aparece; a atualidade é a fonte.
     */
    public function pendingContractualChangeCount(): int
    {
        $officialIds = $this->officialVersionIds();

        $flagged = EmissionPuCurveVersion::query()
            ->whereIn('id', $officialIds)
            ->whereNotNull('contractual_change_detected_at')
            ->whereNull('extension_diverged_at')
            ->pluck('emission_id')
            ->map(fn ($id): int => (int) $id);

        $newVersionRequired = Emission::query()
            ->whereIn('id', EmissionPuCurveVersion::query()->whereIn('id', $officialIds)->pluck('emission_id'))
            ->get()
            ->filter(fn (Emission $emission): bool => $this->officialFreshness->status($emission)->freshness
                === PuOfficialCurveFreshness::NewVersionRequired)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        return $flagged->merge($newVersionRequired)->unique()->count();
    }

    /**
     * Curvas vigentes governadas cujo recálculo deixou de reproduzir o trecho
     * gravado: a rotina não as troca sozinha, então alguém precisa decidir.
     */
    public function divergedGovernedCurveCount(): int
    {
        return EmissionPuCurveVersion::query()
            ->operational()
            ->whereNotNull('extension_diverged_at')
            ->where('status', '!=', PuCurveStatus::Obsolete->value)
            ->count();
    }

    /**
     * Assinatura estavel do conjunto de problemas, para throttling de alertas.
     */
    public function criticalSignature(): string
    {
        return md5(implode('|', $this->criticalSummary()));
    }
}
