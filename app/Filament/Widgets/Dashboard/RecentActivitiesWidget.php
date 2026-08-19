<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Activities\ActivityResource;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class RecentActivitiesWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.recent-activities-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'xl' => 5,
    ];

    protected static ?int $sort = 6;

    /**
     * @return array{
     *     activityGroups: Collection<string, Collection<int, array{
     *         id: int,
     *         period: string,
     *         actorName: string,
     *         isSystem: bool,
     *         typeLabel: string,
     *         markerIcon: string,
     *         markerClasses: string,
     *         action: string,
     *         isExpandable: bool,
     *         subjectLabel: ?string,
     *         subjectId: int|string|null,
     *         occurredAt: string,
     *         occurredAtExact: string,
     *         occurredAtRelative: string
     *     }>>,
     *     canViewActivities: bool,
     *     viewAllUrl: ?string
     * }
     */
    protected function getViewData(): array
    {
        if (! ActivityResource::canViewAny()) {
            return [
                'activityGroups' => collect(),
                'canViewActivities' => false,
                'viewAllUrl' => null,
            ];
        }

        $activities = Activity::with('causer')
            ->latest()
            ->limit(10)
            ->get();

        return [
            'activityGroups' => $activities
                ->map(fn (Activity $activity): array => $this->presentActivity($activity))
                ->groupBy('period'),
            'canViewActivities' => true,
            'viewAllUrl' => ActivityResource::getUrl('index', panel: 'admin'),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     period: string,
     *     actorName: string,
     *     isSystem: bool,
     *     typeLabel: string,
     *     markerIcon: string,
     *     markerClasses: string,
     *     action: string,
     *     isExpandable: bool,
     *     subjectLabel: ?string,
     *     subjectId: int|string|null,
     *     occurredAt: string,
     *     occurredAtExact: string,
     *     occurredAtRelative: string
     * }
     */
    private function presentActivity(Activity $activity): array
    {
        $description = Str::of($activity->description)->squish()->toString();
        $subjectLabel = $activity->subject_type
            ? ActivityResource::friendlySubjectType($activity->subject_type)
            : null;
        $type = $this->activityType($activity, $description);
        $action = $this->actionDescription($description, $subjectLabel);

        if (in_array($description, ['login', 'logout'], true)) {
            $subjectLabel = null;
        }

        return [
            'id' => $activity->id,
            'period' => $this->periodLabel($activity->created_at),
            'actorName' => filled($activity->causer?->name) ? $activity->causer->name : 'Sistema',
            'isSystem' => $activity->causer === null,
            'typeLabel' => $type['label'],
            'markerIcon' => $type['icon'],
            'markerClasses' => $type['classes'],
            'action' => $action,
            'isExpandable' => Str::length($action) > 78,
            'subjectLabel' => $subjectLabel,
            'subjectId' => $activity->subject_id,
            'occurredAt' => $activity->created_at->toIso8601String(),
            'occurredAtExact' => $activity->created_at->format('d/m/Y \à\s H:i'),
            'occurredAtRelative' => $activity->created_at->diffForHumans(),
        ];
    }

    /**
     * @return array{label: string, icon: string, classes: string}
     */
    private function activityType(Activity $activity, string $description): array
    {
        $event = Str::lower((string) ($activity->event ?: $description));
        $searchableActivity = $event.' '.Str::lower($description);

        if ($activity->causer === null) {
            return [
                'label' => 'Sistema',
                'icon' => 'heroicon-o-cog-6-tooth',
                'classes' => 'bg-gray-100 text-gray-700 dark:bg-white/8 dark:text-gray-200',
            ];
        }

        if (Str::contains($searchableActivity, ['login', 'logout'])) {
            return [
                'label' => 'Acesso',
                'icon' => 'heroicon-o-arrow-right-end-on-rectangle',
                'classes' => 'bg-gray-100 text-gray-700 dark:bg-white/8 dark:text-gray-200',
            ];
        }

        if (Str::contains($searchableActivity, ['approved', 'aprovad', 'homologat', 'validated', 'validad', 'confirmed', 'confirmad'])) {
            return [
                'label' => 'Aprovação',
                'icon' => 'heroicon-o-check-badge',
                'classes' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
            ];
        }

        if (Str::contains($searchableActivity, ['created', 'criad', 'generated', 'gerad'])) {
            return [
                'label' => 'Criação',
                'icon' => 'heroicon-o-plus',
                'classes' => 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300',
            ];
        }

        if (Str::contains($searchableActivity, ['deleted', 'remov', 'exclu'])) {
            return [
                'label' => 'Exclusão',
                'icon' => 'heroicon-o-trash',
                'classes' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
            ];
        }

        if (Str::contains($searchableActivity, ['rejected', 'rejeitad', 'failed', 'falha', 'invalidated', 'cancelad'])) {
            return [
                'label' => 'Revisão',
                'icon' => 'heroicon-o-exclamation-triangle',
                'classes' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300',
            ];
        }

        if (Str::contains($searchableActivity, ['updated', 'alterad', 'changed', 'revised', 'restored', 'paused', 'reopened', 'reactivated', 'complemented', 'reprocessed', 'attached', 'synced'])) {
            return [
                'label' => 'Alteração',
                'icon' => 'heroicon-o-pencil-square',
                'classes' => 'bg-info-100 text-info-700 dark:bg-info-500/15 dark:text-info-300',
            ];
        }

        return [
            'label' => 'Movimentação',
            'icon' => 'heroicon-o-arrow-path',
            'classes' => 'bg-gray-100 text-gray-700 dark:bg-white/8 dark:text-gray-200',
        ];
    }

    private function actionDescription(string $description, ?string $subjectLabel): string
    {
        $subject = Str::lower($subjectLabel ?? 'registro');

        return match ($description) {
            'created' => 'Criou '.$subject,
            'updated' => 'Atualizou '.$subject,
            'deleted' => 'Removeu '.$subject,
            'restored' => 'Restaurou '.$subject,
            'login' => 'Acessou o sistema',
            'logout' => 'Saiu do sistema',
            default => $description,
        };
    }

    private function periodLabel(CarbonInterface $occurredAt): string
    {
        if ($occurredAt->isToday()) {
            return 'Hoje';
        }

        if ($occurredAt->isYesterday()) {
            return 'Ontem';
        }

        if ($occurredAt->greaterThanOrEqualTo(now()->startOfWeek())) {
            return 'Esta semana';
        }

        return 'Anteriores';
    }
}
