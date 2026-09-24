<?php

namespace App\Filament\Pages;

use App\DTOs\Measurements\MeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementCycleReportResult;
use App\Enums\AccessPermission;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementStageExitReason;
use App\Models\User;
use App\Services\MeasurementCycleReportingService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class MeasurementCycleReport extends Page
{
    use WithPagination;

    #[Url(as: 'period_from', except: '')]
    public string $periodFrom = '';

    #[Url(as: 'period_to', except: '')]
    public string $periodTo = '';

    #[Url(as: 'operation_id')]
    public ?int $operationId = null;

    #[Url(as: 'emission_id')]
    public ?int $emissionId = null;

    #[Url(as: 'measurement_id')]
    public ?int $measurementId = null;

    #[Url]
    public ?int $stage = null;

    #[Url(as: 'decision_type', except: '')]
    public string $decisionType = '';

    #[Url(as: 'actor_id')]
    public ?int $actorId = null;

    #[Url(as: 'expected_responsible_id')]
    public ?int $expectedResponsibleId = null;

    #[Url(except: '')]
    public string $completeness = '';

    #[Url(as: 'detail_id')]
    public ?int $detailId = null;

    public int $visitsPerPage = 5;

    protected string $view = 'filament.pages.measurement-cycle-report';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 14;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Relatório do Ciclo';

    protected static ?string $title = 'Relatório do Ciclo de Medições';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-measurement-cycle-report',
    ];

    private ?MeasurementCycleReportResult $resolvedResult = null;

    private ?MeasurementCycleHistory $resolvedDetail = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user?->can(AccessPermission::MeasurementsView->value)
            && (bool) $user?->can(AccessPermission::MeasurementsCycleReportsView->value);
    }

    public function getSubheading(): ?string
    {
        return 'Métricas históricas derivadas exclusivamente do motor canônico P3B.1, com cobertura explícita e carga operacional atual separada.';
    }

    public function updated(string $property): void
    {
        if ($property === 'detailId') {
            $this->resolvedDetail = null;

            return;
        }

        $this->resetPage();
        $this->resolvedResult = null;
        $this->resolvedDetail = null;
        $this->detailId = null;
    }

    public function clearFilters(): void
    {
        $this->reset(
            'periodFrom',
            'periodTo',
            'operationId',
            'emissionId',
            'measurementId',
            'stage',
            'decisionType',
            'actorId',
            'expectedResponsibleId',
            'completeness',
            'detailId',
        );
        $this->resetPage();
        $this->resolvedResult = null;
        $this->resolvedDetail = null;
    }

    public function applyMetricDrilldown(string $decision): void
    {
        if (! in_array($decision, [
            MeasurementStageExitReason::Approved->value,
            MeasurementStageExitReason::ReturnedFromFinalization->value,
        ], true)) {
            return;
        }

        if (($this->decisionType !== '' && $this->decisionType !== $decision)
            || ($this->completeness !== ''
                && $this->completeness !== MeasurementHistoryCompleteness::Complete->value)) {
            return;
        }

        $this->decisionType = $decision;
        $this->completeness = MeasurementHistoryCompleteness::Complete->value;

        $this->resetPage();
        $this->resolvedResult = null;
        $this->resolvedDetail = null;
        $this->detailId = null;
    }

    public function showDetail(int $measurementId): void
    {
        $this->detailId = $measurementId;
        $this->resolvedDetail = null;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
        $this->resolvedDetail = null;
    }

    public function result(): MeasurementCycleReportResult
    {
        return $this->resolvedResult ??= app(MeasurementCycleReportingService::class)->report(
            $this->actor(),
            $this->filters(),
            $this->getPage(),
            $this->resolvedVisitsPerPage(),
        );
    }

    public function rows(): LengthAwarePaginator
    {
        return $this->result()->paginator(
            request()->url(),
            $this->filters()->toQuery(),
        );
    }

    public function detail(): ?MeasurementCycleHistory
    {
        if ($this->detailId === null) {
            return null;
        }

        return $this->resolvedDetail ??= app(MeasurementCycleReportingService::class)->detail(
            $this->actor(),
            $this->detailId,
            $this->filters(),
        );
    }

    /** @return array<int, string> */
    public function detailUserNames(): array
    {
        $history = $this->detail();

        if (! $history instanceof MeasurementCycleHistory) {
            return [];
        }

        $ids = collect($history->events)
            ->flatMap(fn ($event): array => [
                $event->actorId,
                $event->expectedResponsibleId,
                $event->delegatorId,
            ])
            ->merge(collect($history->stageVisits)->flatMap(fn ($visit): array => [
                $visit->exitActorId,
                $visit->expectedResponsibleId,
                $visit->delegatorId,
            ]))
            ->merge(collect($history->pauses)->flatMap(fn ($pause): array => [
                $pause->pausedById,
                $pause->resumedById,
            ]))
            ->merge(collect($history->payments)->flatMap(fn ($payment): array => [
                $payment->createdById,
            ]))
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? []
            : User::query()->whereKey($ids)->pluck('name', 'id')->all();
    }

    public function exportUrl(string $format): ?string
    {
        if (! $this->actor()->can(AccessPermission::MeasurementsCycleReportsExport->value)) {
            return null;
        }

        return route('admin.measurements.cycle-report.export', [
            ...$this->filters()->toQuery(),
            'format' => in_array($format, ['csv', 'xlsx'], true) ? $format : 'xlsx',
        ]);
    }

    public function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return 'N/D';
        }

        $rounded = (int) round($seconds);
        $days = intdiv($rounded, 86400);
        $hours = intdiv($rounded % 86400, 3600);
        $minutes = intdiv($rounded % 3600, 60);

        return $days > 0
            ? sprintf('%dd %02dh %02dmin', $days, $hours, $minutes)
            : sprintf('%02dh %02dmin', $hours, $minutes);
    }

    public function rate(?float $rate): string
    {
        return $rate === null ? 'N/D' : number_format($rate * 100, 1, ',', '.').'%';
    }

    public function triState(?bool $value): string
    {
        return match ($value) {
            true => 'Sim',
            false => 'Não',
            null => 'N/D',
        };
    }

    public function exitReason(MeasurementStageExitReason $reason): string
    {
        return match ($reason) {
            MeasurementStageExitReason::Approved => 'Aprovada',
            MeasurementStageExitReason::RejectedTerminal => 'Rejeição terminal',
            MeasurementStageExitReason::ReturnedByRejection => 'Retorno por rejeição',
            MeasurementStageExitReason::ReturnedFromFinalization => 'Retorno da finalização',
            MeasurementStageExitReason::Finalized => 'Finalizada',
            MeasurementStageExitReason::Open => 'Aberta',
            MeasurementStageExitReason::Unknown => 'Desconhecida',
        };
    }

    public function eventType(MeasurementCycleEventType $type): string
    {
        return match ($type) {
            MeasurementCycleEventType::MeasurementCreated => 'Medição criada',
            MeasurementCycleEventType::Submitted => 'Medição enviada',
            MeasurementCycleEventType::StageApproved => 'Etapa aprovada',
            MeasurementCycleEventType::StageRejected => 'Etapa rejeitada',
            MeasurementCycleEventType::FinalizationReturned => 'Finalização devolvida',
            MeasurementCycleEventType::StagePaused => 'Medição pausada',
            MeasurementCycleEventType::StageResumed => 'Medição retomada',
            MeasurementCycleEventType::PaymentRegistered => 'Pagamento registrado',
            MeasurementCycleEventType::ReceiptAttached => 'Comprovante anexado',
            MeasurementCycleEventType::ReceiptDeleted => 'Comprovante removido',
            MeasurementCycleEventType::Finalized => 'Medição finalizada',
            MeasurementCycleEventType::EngineeringSnapshotCreated => 'Snapshot criado',
            MeasurementCycleEventType::EngineeringSnapshotInvalidated => 'Snapshot invalidado',
        };
    }

    public function sourceType(MeasurementHistorySourceType $type): string
    {
        return match ($type) {
            MeasurementHistorySourceType::WorkflowActivity => 'Activity de workflow',
            MeasurementHistorySourceType::ModelActivity => 'Activity de modelo',
            MeasurementHistorySourceType::TableFallback => 'Registro durável correlacionado',
        };
    }

    public function missingReason(string $reason): string
    {
        if (str_starts_with($reason, 'payment_row_missing:')) {
            return 'O registro financeiro relacionado não está disponível.';
        }

        if (str_starts_with($reason, 'insufficient_transition_ignored:')) {
            return 'Uma transição sem evidência suficiente foi preservada na timeline e ignorada nas visitas.';
        }

        return match ($reason) {
            'actor_unknown' => 'O actor da época não pôde ser comprovado.',
            'expected_responsible_unknown' => 'O responsável esperado da época não foi registrado.',
            'responsibility_unknown', 'responsibility_shape_unknown' => 'A responsabilidade histórica não pôde ser comprovada.',
            'delegation_metadata_unknown', 'delegation_snapshot_incomplete' => 'A informação histórica de delegação está indisponível.',
            'admin_override_unknown' => 'A informação histórica de override administrativo está indisponível.',
            'workflow_revision_unknown' => 'A revisão técnica do workflow não foi registrada.',
            'status_transition_incomplete' => 'A transição de status está incompleta.',
            'invalid_transition_shape' => 'A forma da transição é incompatível com o workflow.',
            'deduplication_ambiguous' => 'Activities próximas foram preservadas porque a correlação era ambígua.',
            'cycle_start_unknown' => 'O início do ciclo não pôde ser comprovado.',
            'cycle_end_unknown' => 'O encerramento do ciclo não pôde ser comprovado.',
            'multiple_submission_events' => 'Há mais de um envio historicamente plausível.',
            'contradictory_terminal_events' => 'Há eventos terminais históricos contraditórios.',
            'duplicate_terminal_events' => 'Há eventos terminais históricos duplicados.',
            'cycle_end_from_current_state_fallback' => 'O encerramento foi correlacionado ao estado durável atual.',
            'stage_visit_exit_from_current_state_fallback' => 'A saída da visita foi correlacionada ao estado durável atual.',
            'stage_visit_chain_conflicts_with_current_state',
            'stage_visit_chain_conflicts_with_terminal_current_state' => 'A cadeia de visitas conflita com o estado durável da medição.',
            'pause_start_unknown' => 'O início da pausa não foi registrado.',
            'pause_interval_negative' => 'O intervalo histórico da pausa é inconsistente.',
            'pause_activity_without_table_row',
            'resume_activity_without_table_row' => 'A Activity de pausa não possui registro durável correlacionável.',
            'new_stage_opened_before_previous_exit' => 'Uma nova etapa começou antes de haver saída comprovada da anterior.',
            'decision_for_different_open_stage' => 'A decisão não corresponde à etapa que estava aberta.',
            'stage_entry_time_unknown' => 'O horário de entrada na etapa não pôde ser comprovado.',
            'stage_exit_time_unknown' => 'O horário de saída da etapa não pôde ser comprovado.',
            'legacy_model_activity_fallback' => 'O trecho foi reconstruído por fallback legado restrito.',
            default => 'Limitação histórica identificada: '.str_replace(['_', ':'], [' ', ' · '], $reason).'.',
        };
    }

    /** @return array<string, string> */
    public function decisionOptions(): array
    {
        return collect(MeasurementStageExitReason::cases())
            ->reject(fn (MeasurementStageExitReason $reason): bool => in_array($reason, [
                MeasurementStageExitReason::Open,
                MeasurementStageExitReason::Unknown,
            ], true))
            ->mapWithKeys(fn (MeasurementStageExitReason $reason): array => [
                $reason->value => $this->exitReason($reason),
            ])
            ->all();
    }

    /** @return list<int> */
    public function visitsPerPageOptions(): array
    {
        return [5, 10, 25, 50];
    }

    public function resolvedVisitsPerPage(): int
    {
        return in_array($this->visitsPerPage, $this->visitsPerPageOptions(), true)
            ? $this->visitsPerPage
            : 5;
    }

    /** @return array<string, string> */
    public function completenessOptions(): array
    {
        return [
            MeasurementHistoryCompleteness::Complete->value => 'Completa',
            MeasurementHistoryCompleteness::Partial->value => 'Parcial',
            MeasurementHistoryCompleteness::Insufficient->value => 'Insuficiente',
        ];
    }

    public function hasFilters(): bool
    {
        return $this->filters()->toQuery() !== [];
    }

    private function filters(): MeasurementCycleReportFilters
    {
        return MeasurementCycleReportFilters::fromArray([
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'operation_id' => $this->operationId,
            'emission_id' => $this->emissionId,
            'measurement_id' => $this->measurementId,
            'stage' => $this->stage,
            'decision_type' => $this->decisionType,
            'actor_id' => $this->actorId,
            'expected_responsible_id' => $this->expectedResponsibleId,
            'completeness' => $this->completeness,
        ]);
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
