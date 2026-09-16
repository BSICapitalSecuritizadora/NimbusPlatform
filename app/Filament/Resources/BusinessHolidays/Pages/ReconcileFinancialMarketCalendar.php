<?php

namespace App\Filament\Resources\BusinessHolidays\Pages;

use App\Domain\PuCalculator\Services\BusinessCalendarSourceReconciliationService;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use App\Filament\Resources\BusinessHolidays\BusinessHolidayResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * Visão somente leitura da reconciliação ANBIMA × FEBRABAN que sustenta o calendário financeiro
 * consolidado.
 *
 * Fica sob o mesmo resource do comparador de calendários, mas é uma tela distinta por um motivo
 * concreto: o comparador confronta dois CÓDIGOS de calendário, enquanto aqui o eixo são as FONTES
 * dentro de um mesmo calendário. A página não decide nada — conflitos são apresentados, nunca
 * resolvidos automaticamente.
 */
class ReconcileFinancialMarketCalendar extends Page
{
    protected static string $resource = BusinessHolidayResource::class;

    protected string $view = 'filament.resources.business-holidays.pages.reconcile-financial-market-calendar';

    /** @var array<string, mixed>|null */
    public ?array $reconciliation = null;

    public function getTitle(): string
    {
        return 'Reconciliar calendário financeiro (ANBIMA × FEBRABAN)';
    }

    public function getSubheading(): ?string
    {
        return sprintf(
            'Somente leitura. As fontes são confrontadas nos termos da %s; nenhuma decisão é gravada e nenhum conflito é resolvido automaticamente.',
            FebrabanHolidayImporter::NORM_REFERENCE,
        );
    }

    protected function getHeaderActions(): array
    {
        $year = now()->year;

        return [
            Action::make('reconcile')
                ->label('Executar reconciliação')
                ->icon('heroicon-o-scale')
                ->modalHeading('Reconciliar fontes do calendário financeiro')
                ->modalDescription('O período é limitado a dez anos por execução. Nada é persistido.')
                ->form([
                    DatePicker::make('from')
                        ->label('De')
                        ->default(CarbonImmutable::create($year, 1, 1))
                        ->required(),
                    DatePicker::make('to')
                        ->label('Até')
                        ->default(CarbonImmutable::create($year, 12, 31))
                        ->afterOrEqual('from')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->reconciliation = app(BusinessCalendarSourceReconciliationService::class)->reconcile(
                            CarbonImmutable::parse((string) $data['from']),
                            CarbonImmutable::parse((string) $data['to']),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível reconciliar as fontes.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
