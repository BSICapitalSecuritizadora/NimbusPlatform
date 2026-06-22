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

class PuCurveHistory extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmissionResource::class;

    protected string $view = 'filament.resources.emissions.pages.pu-curve-history';

    protected static ?string $title = 'Historico da Curva PU';

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
        return 'Historico e Auditoria da Curva PU';
    }

    /**
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
     * @return \Illuminate\Support\Collection<int, \Spatie\Activitylog\Models\Activity>
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
                ->label('Voltar para a Emissao')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => EmissionResource::getUrl('edit', ['record' => $this->getRecord()])),
            Action::make('viewDivergenceReport')
                ->label('Ver Relatorio de Divergencias')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalHeading('Ultimo Relatorio de Validacao')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.emissions.pu-validation-report', [
                    'activity' => app(PuAuditLogService::class)->latestValidationActivity($this->getRecord()),
                ])),
        ];
    }
}
