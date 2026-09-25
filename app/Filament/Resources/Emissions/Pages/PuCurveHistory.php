<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\EmissionPuCurveVersion;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class PuCurveHistory extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmissionResource::class;

    protected string $view = 'filament.resources.emissions.pages.pu-curve-history';

    protected static ?string $title = 'Histórico da Curva PU';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-pu-curve-history-page',
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('pu.curve.view') ?? false;
    }

    public function getTitle(): string
    {
        return 'Histórico e Auditoria da Curva PU';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe as versões geradas, o status de processamento e o histórico de alterações da curva de PU.';
    }

    /**
     * Histórico e auditoria: lista deliberadamente TODOS os papéis, inclusive as
     * candidates, porque é aqui que a governança inspeciona o inventário completo.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EmissionPuCurveVersion>
     */
    public function getVersions(): Collection
    {
        return EmissionPuCurveVersion::query()
            ->where('emission_id', $this->getRecord()->id)
            ->with(['generatedBy', 'validatedBy', 'homologatedBy'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getActivities(): Collection
    {
        return app(PuAuditLogService::class)->activitiesFor($this->getRecord());
    }

    public function describeEvent(string $description): string
    {
        return app(PuAuditLogService::class)->describeEvent($description);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEmission')
                ->label('Voltar para a Emissão')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->outlined()
                ->extraAttributes(['class' => 'pua-btn-back'])
                ->url(fn (): string => EmissionResource::getUrl('edit', ['record' => $this->getRecord()])),
            Action::make('viewDivergenceReport')
                ->label('Ver Relatório de Divergências')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->outlined()
                ->extraAttributes(['class' => 'pua-btn-divergence'])
                ->modalWidth(Width::SevenExtraLarge)
                ->modalHeading('Último Relatório de Validação')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.emissions.pu-validation-report', [
                    'activity' => app(PuAuditLogService::class)->latestValidationActivity($this->getRecord()),
                ])),
        ];
    }
}
