<?php

namespace App\Filament\Pages;

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Filament\Widgets\Obligations\ObligationEvidenceOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Filament\Widgets\Obligations\ObligationOverdueAgingChartWidget;
use App\Filament\Widgets\Obligations\ObligationOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationPriorityDistributionChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByAreaChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByEmissionChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByResponsibleChartWidget;
use App\Filament\Widgets\Obligations\ObligationStatusDistributionChartWidget;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\User;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ObligationDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/obligation-dashboard';

    protected static ?string $title = 'Painel de Obrigações';

    protected static ?string $navigationLabel = 'Painel de Obrigações';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Governança & Risco';

    protected static ?int $navigationSort = 25;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user?->can(AccessPermission::ObligationsViewDashboard->value)
            && (bool) $user?->can(AccessPermission::ObligationsView->value);
    }

    public function getColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 2,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ObligationOverviewStatsWidget::class,
            ObligationEvidenceOverviewStatsWidget::class,
            ObligationStatusDistributionChartWidget::class,
            ObligationPriorityDistributionChartWidget::class,
            ObligationsByEmissionChartWidget::class,
            ObligationOverdueAgingChartWidget::class,
            ObligationsByResponsibleChartWidget::class,
            ObligationsByAreaChartWidget::class,
            ObligationOperationalTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Visão operacional das obrigações de todas as emissões: vencimentos, responsáveis, prioridades, áreas e situação documental.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        $canViewEvidence = (bool) Filament::auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $dashboardData = app(ObligationDashboardData::class);

        $fields = [
            Select::make('emission_id')
                ->label('Emissão')
                ->placeholder('Todas')
                ->searchable()
                ->options(fn (): array => Emission::query()
                    ->whereHas('obligations')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
            Select::make('status')
                ->label('Status da obrigação')
                ->placeholder('Todos')
                ->options(Obligation::STATUS_OPTIONS),
            Select::make('responsible_user_id')
                ->label('Responsável')
                ->placeholder('Todos')
                ->searchable()
                ->options(fn (): array => User::query()
                    ->whereIn('id', Obligation::query()
                        ->whereNotNull('responsible_user_id')
                        ->select('responsible_user_id')
                        ->distinct())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
            Select::make('responsible_area')
                ->label('Área responsável')
                ->placeholder('Todas')
                ->options(fn (): array => collect(Obligation::query()
                    ->whereNotNull('responsible_area')
                    ->orderBy('responsible_area')
                    ->pluck('responsible_area', 'responsible_area')
                    ->all())->union(ObligationFormFields::AREA_OPTIONS)->all()),
            Select::make('priority')
                ->label('Prioridade')
                ->placeholder('Todas')
                ->options(Obligation::PRIORITY_OPTIONS),
            Select::make('due_window')
                ->label('Período de vencimento')
                ->placeholder('Todos')
                ->options(ObligationDashboardData::DUE_WINDOW_OPTIONS),
            Select::make('operational_focus')
                ->label('Visão operacional')
                ->placeholder('Todas')
                ->options($dashboardData->operationalFocusOptions($canViewEvidence)),
            Select::make('source')
                ->label('Origem')
                ->placeholder('Todas')
                ->options(ObligationDashboardData::SOURCE_FILTER_OPTIONS),
        ];

        if ($canViewEvidence) {
            $fields[] = Select::make('evidence_state')
                ->label('Status da evidência')
                ->placeholder('Todos')
                ->options(ObligationDashboardData::EVIDENCE_FILTER_OPTIONS);
        }

        return $schema->schema($fields);
    }
}
