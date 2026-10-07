<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PuOperationalMonitorService
{
    public function __construct(
        private readonly PuIndexCoverageService $indexCoverageService,
        private readonly PuOfficialCurveFreshnessService $officialFreshness,
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

        return $issues;
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
     * Curvas OFICIAIS cuja extensão percebeu insumos contratuais diferentes dos
     * aprovados só no futuro (o passado divergente já conta em
     * {@see self::divergedGovernedCurveCount()}). Uma homologada substituída por
     * outra mais nova não conta: a pendência foi resolvida pela versão nova.
     */
    public function pendingContractualChangeCount(): int
    {
        $officialIds = EmissionPuCurveVersion::query()
            ->operational()
            ->homologated()
            ->selectRaw('MAX(id) as id')
            ->groupBy('emission_id')
            ->pluck('id');

        return EmissionPuCurveVersion::query()
            ->whereIn('id', $officialIds)
            ->whereNotNull('contractual_change_detected_at')
            ->whereNull('extension_diverged_at')
            ->count();
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
