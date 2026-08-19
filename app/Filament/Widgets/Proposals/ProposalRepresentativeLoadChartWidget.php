<?php

namespace App\Filament\Widgets\Proposals;

use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\Widget;

class ProposalRepresentativeLoadChartWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Carga Operacional da Fila Comercial';

    protected ?string $description = 'Processos em andamento distribuídos por representante comercial.';

    protected string $view = 'filament.widgets.proposals.proposal-representative-load-widget';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super-admin', 'admin']);
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array{
     *     total_representatives: int,
     *     total_active_proposals: int,
     *     average_load: float,
     *     max_load: int,
     *     min_load: int,
     *     balance_status: array{
     *         status: string,
     *         label: string,
     *         badge_color: string,
     *         icon: string
     *     },
     *     has_activity: bool,
     *     items: array<int, array{
     *         id: int,
     *         name: string,
     *         email: string,
     *         queue_position: ?int,
     *         count: int,
     *         count_label: string,
     *         percentage: float,
     *         is_highest: bool,
     *         is_available: bool
     *     }>
     * }
     */
    public function getDetails(): array
    {
        return app(ProposalDashboardData::class)->representativeLoadDetails();
    }

    public function getRepresentativesUrl(): string
    {
        return ProposalRepresentativeResource::getUrl('index');
    }
}
