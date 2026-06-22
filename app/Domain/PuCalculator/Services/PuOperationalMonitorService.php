<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
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

        return $issues;
    }

    /**
     * Assinatura estavel do conjunto de problemas, para throttling de alertas.
     */
    public function criticalSignature(): string
    {
        return md5(implode('|', $this->criticalSummary()));
    }
}
