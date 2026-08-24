<?php

namespace App\Filament\Resources\BusinessHolidays\Pages;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarDiffService;
use App\Filament\Resources\BusinessHolidays\BusinessHolidayResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class CompareBusinessCalendars extends Page
{
    protected static string $resource = BusinessHolidayResource::class;

    protected string $view = 'filament.resources.business-holidays.pages.compare-business-calendars';

    /** @var array<string, mixed>|null */
    public ?array $comparison = null;

    public function getTitle(): string
    {
        return 'Comparar calendários de dias úteis';
    }

    public function getSubheading(): ?string
    {
        return 'A comparação é somente leitura e destaca divergências de decisão, fonte, revisão e cobertura anual.';
    }

    protected function getHeaderActions(): array
    {
        $year = now()->year;

        return [
            Action::make('compare')
                ->label('Executar comparação')
                ->icon('heroicon-o-arrows-right-left')
                ->modalHeading('Comparar calendários')
                ->modalDescription('Datas com a mesma decisão e os mesmos metadados não são listadas individualmente.')
                ->form([
                    Select::make('calendar_a')
                        ->label('Calendário A')
                        ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions())
                        ->required(),
                    Select::make('calendar_b')
                        ->label('Calendário B')
                        ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions())
                        ->different('calendar_a')
                        ->required(),
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
                        $this->comparison = app(BusinessCalendarDiffService::class)->compare(
                            (string) $data['calendar_a'],
                            (string) $data['calendar_b'],
                            CarbonImmutable::parse((string) $data['from']),
                            CarbonImmutable::parse((string) $data['to']),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível comparar os calendários.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
