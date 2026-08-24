<?php

namespace App\Filament\Resources\BusinessHolidays\Pages;

use App\Domain\PuCalculator\DTOs\AnbimaHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Services\AnbimaHolidayImporter;
use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarCoverageService;
use App\Domain\PuCalculator\Services\BusinessCalendarOverrideService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\BusinessHolidays\BusinessHolidayResource;
use App\Filament\Widgets\BusinessCalendars\BusinessCalendarOverview;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListBusinessHolidays extends ListRecords
{
    protected static string $resource = BusinessHolidayResource::class;

    public function getSubheading(): ?string
    {
        return 'ANBIMA (bancário) e B3 (sessões de negociação) são calendários distintos. O código B3 abaixo é um alias legado sem redirecionamento automático.';
    }

    protected function getHeaderWidgets(): array
    {
        return [BusinessCalendarOverview::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compareCalendars')
                ->label('Comparar calendários')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('pu.dashboard.view') ?? false)
                ->url(BusinessHolidayResource::getUrl('compare')),
            $this->buildImportFromUrlAction(),
            $this->buildImportFromFileAction(),
            $this->buildManualOverrideAction(),
            $this->buildConfirmYearAction(),
            $this->buildSeedCalendarAction(),
        ];
    }

    private function buildImportFromUrlAction(): Action
    {
        return Action::make('importAnbimaUrl')
            ->label('Importar feriados ANBIMA')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('pu.holiday.import') ?? false)
            ->modalHeading('Importar feriados nacionais da ANBIMA (URL)')
            ->modalDescription('Baixa o arquivo .xls publicado pela ANBIMA e aplica os feriados ao calendário (não úteis). Idempotente. Se a URL falhar, use "Importar feriados de arquivo".')
            ->form([
                Select::make('calendar_code')
                    ->label('Calendário bancário de destino')
                    ->options($this->anbimaCalendarOptions())
                    ->default(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
                    ->helperText('Novas cargas ANBIMA alimentam somente BR_BANKING_ANBIMA. B3 legado e B3_LISTED_TRADING não são destinos desta ação.')
                    ->required(),
                TextInput::make('url')
                    ->label('URL do arquivo .xls')
                    ->default(AnbimaHolidayImporter::DEFAULT_URL)
                    ->url()
                    ->required(),
                Toggle::make('dry_run')->label('Dry-run (simular, sem persistir)')->default(false),
                Toggle::make('force')->label('Forçar atualização de nomes já cadastrados')->default(false),
            ])
            ->action(function (array $data): void {
                try {
                    $result = app(AnbimaHolidayImporter::class)->importFromUrl(
                        (string) $data['url'],
                        (string) $data['calendar_code'],
                        (bool) ($data['dry_run'] ?? false),
                        (bool) ($data['force'] ?? false),
                        auth()->id(),
                    );
                } catch (AnbimaHolidayImportException $exception) {
                    Notification::make()
                        ->title('Falha ao importar feriados da ANBIMA.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->notifyResult($result);
            });
    }

    private function buildImportFromFileAction(): Action
    {
        return Action::make('importAnbimaFile')
            ->label('Importar feriados de arquivo')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('pu.holiday.import') ?? false)
            ->modalHeading('Importar feriados da ANBIMA (arquivo)')
            ->modalDescription('Fallback de upload manual quando a URL da ANBIMA está indisponível. Aceita o .xls oficial ou .xlsx equivalente.')
            ->form([
                Select::make('calendar_code')
                    ->label('Calendário bancário de destino')
                    ->options($this->anbimaCalendarOptions())
                    ->default(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
                    ->helperText('O arquivo ANBIMA não representa sessões de negociação da B3.')
                    ->required(),
                FileUpload::make('holiday_file')
                    ->label('Arquivo de feriados (.xls/.xlsx)')
                    ->disk('local')
                    ->directory('imports/holidays')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/octet-stream',
                    ])
                    ->maxSize(10240)
                    ->required(),
                Toggle::make('dry_run')->label('Dry-run (simular, sem persistir)')->default(false),
                Toggle::make('force')->label('Forçar atualização de nomes já cadastrados')->default(false),
            ])
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path((string) $data['holiday_file']);

                try {
                    $result = app(AnbimaHolidayImporter::class)->importFromFile(
                        $path,
                        (string) $data['calendar_code'],
                        (bool) ($data['dry_run'] ?? false),
                        (bool) ($data['force'] ?? false),
                        auth()->id(),
                    );
                } catch (AnbimaHolidayImportException $exception) {
                    Notification::make()
                        ->title('Falha ao importar feriados.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->notifyResult($result);
            });
    }

    private function buildSeedCalendarAction(): Action
    {
        $now = CarbonImmutable::now();

        return Action::make('seedBusinessCalendar')
            ->label('Completar cobertura')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('pu.calendar.manage') ?? false)
            ->modalHeading('Completar calendário de dias úteis')
            ->modalDescription('Gera datas faltantes por inferência (final de semana = não útil; dia de semana = útil; feriado importado = não útil). Isso não confirma oficialmente o ano e não sobrescreve datas existentes.')
            ->form([
                Select::make('calendar_code')
                    ->label('Calendário')
                    ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions())
                    ->default(BusinessCalendarRegistry::LEGACY_B3)
                    ->required(),
                DatePicker::make('from')->label('De')->default($now->subYears(5)->startOfYear())->required(),
                DatePicker::make('to')->label('Até')->default($now->addYears(3)->endOfYear())->required(),
                Toggle::make('dry_run')->label('Dry-run (simular, sem persistir)')->default(false),
            ])
            ->action(function (array $data): void {
                $from = CarbonImmutable::parse((string) $data['from'])->startOfDay();
                $to = CarbonImmutable::parse((string) $data['to'])->startOfDay();

                if ($to->lt($from)) {
                    Notification::make()->title('Período inválido.')->body('A data final não pode ser anterior à data inicial.')->danger()->send();

                    return;
                }

                $summary = app(BusinessCalendarCoverageService::class)->backfill(
                    (string) $data['calendar_code'],
                    $from,
                    $to,
                    (bool) ($data['dry_run'] ?? false),
                );

                if ($summary['dry_run']) {
                    Notification::make()
                        ->title('Dry-run concluído (nada persistido).')
                        ->body(sprintf('%d data(s) seriam criadas (%d úteis, %d não úteis).', $summary['would_create'], $summary['business_days'], $summary['non_business_days']))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Calendário completado.')
                    ->body(sprintf('%d data(s) criada(s) (%d úteis, %d não úteis). Datas existentes preservadas.', $summary['created'], $summary['business_days'], $summary['non_business_days']))
                    ->success()
                    ->send();
            });
    }

    private function buildManualOverrideAction(): Action
    {
        return Action::make('manualCalendarOverride')
            ->label('Override manual')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can('pu.calendar.manage') ?? false)
            ->modalHeading('Aplicar override manual auditável')
            ->modalDescription('O override prevalece sobre importações futuras. Se o ano estava confirmado, ele será marcado como desatualizado até nova revisão.')
            ->form([
                Select::make('calendar_code')
                    ->label('Calendário')
                    ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions())
                    ->required(),
                DatePicker::make('calendar_date')->label('Data')->required(),
                Select::make('is_business_day')
                    ->label('Nova decisão')
                    ->options([
                        '1' => 'Dia útil',
                        '0' => 'Dia não útil',
                    ])
                    ->required(),
                Textarea::make('reason')
                    ->label('Motivo')
                    ->helperText('Descreva a evidência e por que a decisão oficial/importada precisa ser substituída.')
                    ->rows(4)
                    ->minLength(10)
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $override = app(BusinessCalendarOverrideService::class)->apply(
                        (string) $data['calendar_code'],
                        CarbonImmutable::parse((string) $data['calendar_date']),
                        (string) $data['is_business_day'] === '1',
                        (string) $data['reason'],
                        (int) auth()->id(),
                    );
                } catch (\Throwable $exception) {
                    Notification::make()->title('Override não aplicado.')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Override manual aplicado e auditado.')
                    ->body(sprintf(
                        '%s/%s: %s → %s (revisão %d).',
                        $override->calendar_code,
                        $override->calendar_date?->format('d/m/Y'),
                        $override->previous_is_business_day ? 'útil' : 'não útil',
                        $override->new_is_business_day ? 'útil' : 'não útil',
                        $override->revision,
                    ))
                    ->warning()
                    ->send();
            });
    }

    private function buildConfirmYearAction(): Action
    {
        return Action::make('confirmCalendarYear')
            ->label('Confirmar ano')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->can('pu.calendar.manage') ?? false)
            ->modalHeading('Confirmar cobertura anual')
            ->modalDescription('A confirmação exige cobertura de todas as datas do ano e ausência de conflitos na última execução.')
            ->form([
                Select::make('calendar_code')
                    ->label('Calendário')
                    ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions())
                    ->required(),
                TextInput::make('year')->label('Ano')->integer()->minValue(1990)->maxValue(2200)->required(),
                TextInput::make('source')->label('Fonte')->required(),
                Textarea::make('source_document')->label('Documento/referência oficial')->rows(3)->required(),
                TextInput::make('source_revision')->label('Revisão do documento'),
                TextInput::make('checksum')->label('Checksum SHA-256')->length(64),
            ])
            ->action(function (array $data): void {
                try {
                    $calendarYear = app(BusinessCalendarYearService::class)->confirm(
                        (string) $data['calendar_code'],
                        (int) $data['year'],
                        (string) $data['source'],
                        (string) $data['source_document'],
                        filled($data['source_revision'] ?? null) ? (string) $data['source_revision'] : null,
                        filled($data['checksum'] ?? null) ? (string) $data['checksum'] : null,
                        (int) auth()->id(),
                    );
                } catch (\Throwable $exception) {
                    Notification::make()->title('Ano não confirmado.')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Ano confirmado.')
                    ->body(sprintf('%s/%d confirmado na revisão %d.', $calendarYear->calendar_code, $calendarYear->year, $calendarYear->revision))
                    ->success()
                    ->send();
            });
    }

    private function notifyResult(AnbimaHolidayImportResult $result): void
    {
        if ($result->dryRun) {
            Notification::make()
                ->title('Dry-run concluído (nada persistido).')
                ->body(sprintf(
                    '%d feriado(s) lido(s): %d seriam criados, %d atualizados, %d remoções e %d conflitos detectados. O calendário não foi alterado; %d execução(ões) de auditoria foram registradas.',
                    $result->total,
                    $result->imported,
                    $result->updated,
                    $result->removalsDetected,
                    $result->conflictsDetected,
                    $result->importRuns,
                ))
                ->warning()
                ->send();

            return;
        }

        $notification = Notification::make()
            ->title('Feriados importados.')
            ->body(sprintf(
                '%s: %d criado(s), %d atualizado(s), %d já cadastrado(s), %d remoção(ões), %d conflito(s), %d inválido(s). %d data(s) aplicada(s).',
                $result->calendarCode,
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->removalsDetected,
                $result->conflictsDetected,
                $result->invalid,
                $result->calendarApplied,
            ));

        if ($result->conflictsDetected > 0 || $result->removalsDetected > 0 || $result->hasErrors()) {
            $notification->warning()->persistent();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /** @return array<string, string> */
    private function anbimaCalendarOptions(): array
    {
        return app(BusinessCalendarCatalogService::class)->anbimaImportOptions();
    }
}
