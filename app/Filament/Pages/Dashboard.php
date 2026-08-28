<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Dashboard\DeadlinesWidget;
use App\Filament\Widgets\Dashboard\ExecutiveIndicatorsWidget;
use App\Filament\Widgets\Dashboard\MeasurementCockpit;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Filament\Widgets\Dashboard\OperationalAlertsWidget;
use App\Filament\Widgets\Dashboard\RecentActivitiesWidget;
use App\Filament\Widgets\Dashboard\ShortcutsWidget;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Cockpit Operacional';

    protected ?string $subheading = 'Prioridades, indicadores e prazos reunidos para uma leitura operacional mais rápida.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'xl' => 12,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ShortcutsWidget::class,
            MeasurementCockpit::class,
            ExecutiveIndicatorsWidget::class,
            OperationalAlertsWidget::class,
            MyPendingsWidget::class,
            RecentActivitiesWidget::class,
            DeadlinesWidget::class,
        ];
    }

    public function resetMeasurementFilters(): void
    {
        $this->filters = [];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $readModel = app(MeasurementOperationalReadModel::class);
        $hasActiveFilters = filled(array_filter($this->filters ?? []));

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Recorte de medições')
                    ->description('Os filtros abaixo afetam apenas o cockpit de medições e pagamentos; Minhas Pendências mantém sua fonte pessoal própria.')
                    ->visible(fn (): bool => $viewer instanceof User && $viewer->can('measurements.view'))
                    ->icon(Heroicon::OutlinedFunnel)
                    ->compact()
                    ->collapsed(! $hasActiveFilters)
                    ->collapsible()
                    ->headerActions([
                        Action::make('clearMeasurementFilters')
                            ->label('Limpar filtros')
                            ->icon(Heroicon::OutlinedXMark)
                            ->color($hasActiveFilters ? 'warning' : 'gray')
                            ->size(Size::Small)
                            ->action(fn () => $this->resetMeasurementFilters()),
                    ])
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 3,
                            '2xl' => 4,
                        ])->schema([
                            DatePicker::make('competence_from')
                                ->label('Competência desde')
                                ->native(false),
                            DatePicker::make('competence_to')
                                ->label('Competência até')
                                ->native(false)
                                ->afterOrEqual('competence_from'),
                            Select::make('operation_id')
                                ->label('Operação')
                                ->placeholder('Todas')
                                ->searchable()
                                ->options(fn (): array => $viewer instanceof User ? $readModel->operationOptions($viewer) : []),
                            Select::make('emission_id')
                                ->label('Emissão')
                                ->placeholder('Todas')
                                ->searchable()
                                ->options(fn (): array => $viewer instanceof User ? $readModel->emissionOptions($viewer) : []),
                            Select::make('responsible_user_id')
                                ->label('Responsável')
                                ->placeholder('Todos')
                                ->searchable()
                                ->options(fn (): array => $viewer instanceof User ? $readModel->responsibleOptions($viewer) : []),
                            Select::make('stage')
                                ->label('Etapa')
                                ->placeholder('Todas')
                                ->options(MeasurementWorkflow::STAGE_LABELS),
                            Select::make('status')
                                ->label('Status')
                                ->placeholder('Todos')
                                ->options(Measurement::STATUS_OPTIONS),
                            Select::make('sla_status')
                                ->label('SLA')
                                ->placeholder('Todos')
                                ->options([
                                    MeasurementSlaService::STATUS_ON_TIME => 'No prazo',
                                    MeasurementSlaService::STATUS_APPROACHING => 'Em atenção',
                                    MeasurementSlaService::STATUS_OVERDUE => 'Vencido',
                                    MeasurementSlaService::STATUS_PAUSED => 'Pausado',
                                    MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 'Calendário indisponível',
                                ]),
                            Select::make('assignment')
                                ->label('Atuação')
                                ->placeholder('Direta e delegada')
                                ->options([
                                    'direct' => 'Participação direta',
                                    'delegated' => 'Visível por delegação',
                                ]),
                        ]),
                    ]),
            ]);
    }
}
