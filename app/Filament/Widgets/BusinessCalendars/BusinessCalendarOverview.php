<?php

namespace App\Filament\Widgets\BusinessCalendars;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessHoliday;
use Filament\Widgets\Widget;

class BusinessCalendarOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.business-calendars.business-calendar-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $definitions = app(BusinessCalendarCatalogService::class)->definitions();
        $summariesCollection = collect(app(BusinessCalendarYearService::class)->administrativeSummary());
        $summaries = $summariesCollection->groupBy('calendar_code');

        $latestAttemptIds = BusinessCalendarImportRun::query()
            ->selectRaw('MAX(id) AS id')
            ->groupBy('calendar_code')
            ->pluck('id');

        $latestAttempts = BusinessCalendarImportRun::query()
            ->whereKey($latestAttemptIds)
            ->get()
            ->keyBy('calendar_code');

        $totalHolidays = BusinessHoliday::query()->count();
        $activeCalendarsCount = count($definitions);

        $configuredSources = collect($definitions)
            ->pluck('source')
            ->filter()
            ->unique()
            ->values();

        $totalConflicts = (int) $summariesCollection->sum('conflicts');
        $totalOverrides = (int) $summariesCollection->sum('overrides');
        $totalMissingYears = (int) $summariesCollection->where('missing_days', '>', 0)->count();
        $totalStaleYears = (int) $summariesCollection->where('state', 'stale')->count();

        $lastSyncRun = BusinessCalendarImportRun::query()
            ->where('dry_run', false)
            ->latest('finished_at')
            ->first();

        $attentions = [];

        if ($totalConflicts > 0) {
            $attentions[] = [
                'type' => 'danger',
                'message' => sprintf('%d conflito(s) de feriados detectado(s) na sincronização das fontes.', $totalConflicts),
            ];
        }

        if ($totalMissingYears > 0 || $totalStaleYears > 0) {
            $attentions[] = [
                'type' => 'warning',
                'message' => sprintf('%d ano(s) com cobertura parcial ou pendente de confirmação oficial.', $totalMissingYears + $totalStaleYears),
            ];
        }

        if ($totalOverrides > 0) {
            $attentions[] = [
                'type' => 'info',
                'message' => sprintf('%d override(s) manual(is) auditado(s) em aplicação nas curvas financeiras.', $totalOverrides),
            ];
        }

        $failedAttempt = $latestAttempts->first(fn ($run) => in_array($run->result, ['error', 'failed'], true));
        if ($failedAttempt) {
            $attentions[] = [
                'type' => 'danger',
                'message' => sprintf('Falha na última tentativa de sincronização do calendário %s.', $failedAttempt->calendar_code),
            ];
        }

        $rawResult = $lastSyncRun?->result ?? ($latestAttempts->isNotEmpty() ? 'succeeded' : null);
        $syncStatusLabel = match ($rawResult) {
            'succeeded' => 'Sincronizado',
            'conflicts' => 'Conflitos',
            'completed_with_errors' => 'Com avisos',
            'error', 'failed' => 'Falha',
            default => 'Sem histórico',
        };

        return [
            'definitions' => $definitions,
            'summaries' => $summaries,
            'latestAttempts' => $latestAttempts,
            'kpis' => [
                'active_calendars' => $activeCalendarsCount,
                'total_holidays' => $totalHolidays,
                'configured_sources_count' => $configuredSources->count(),
                'configured_sources' => $configuredSources->all(),
                'total_conflicts' => $totalConflicts,
                'total_overrides' => $totalOverrides,
                'last_sync_at' => $lastSyncRun?->finished_at ?? $latestAttempts->max('started_at'),
                'last_sync_result' => $lastSyncRun?->result ?? ($latestAttempts->isNotEmpty() ? 'Concluído' : 'Sem histórico'),
                'last_sync_status_label' => $syncStatusLabel,
            ],
            'attentions' => $attentions,
        ];
    }
}
