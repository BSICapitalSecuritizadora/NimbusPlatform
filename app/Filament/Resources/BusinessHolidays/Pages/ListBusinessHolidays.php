<?php

namespace App\Filament\Resources\BusinessHolidays\Pages;

use App\Domain\PuCalculator\DTOs\AnbimaHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Services\AnbimaHolidayImporter;
use App\Domain\PuCalculator\Services\BusinessCalendarCoverageService;
use App\Filament\Resources\BusinessHolidays\BusinessHolidayResource;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
        $last = BusinessHoliday::query()->max('imported_at');
        $total = BusinessHoliday::query()->count();

        if ($last === null) {
            return 'Nenhum feriado importado ainda. O calendário B3 está derivado apenas de fins de semana (weekend-only).';
        }

        return sprintf(
            '%d feriado(s) cadastrado(s) • última importação em %s',
            $total,
            CarbonImmutable::parse($last)->format('d/m/Y H:i'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildImportFromUrlAction(),
            $this->buildImportFromFileAction(),
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
                TextInput::make('calendar_code')->label('Calendário')->default('B3')->required(),
                TextInput::make('url')
                    ->label('URL do arquivo .xls')
                    ->default(AnbimaHolidayImporter::DEFAULT_URL)
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
                TextInput::make('calendar_code')->label('Calendário')->default('B3')->required(),
                FileUpload::make('holiday_file')
                    ->label('Arquivo de feriados (.xls/.xlsx)')
                    ->disk('local')
                    ->directory('imports/holidays')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/octet-stream',
                    ])
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
            ->label('Completar calendário B3')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('pu.calendar.manage') ?? false)
            ->modalHeading('Completar calendário de dias úteis')
            ->modalDescription('Gera as datas faltantes do período (fim de semana = não útil; dia de semana = útil; feriado importado = não útil), de forma idempotente. Não sobrescreve datas já cadastradas.')
            ->form([
                TextInput::make('calendar_code')->label('Calendário')->default('B3')->required(),
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

    private function notifyResult(AnbimaHolidayImportResult $result): void
    {
        if ($result->dryRun) {
            Notification::make()
                ->title('Dry-run concluído (nada persistido).')
                ->body(sprintf(
                    '%d feriado(s) lido(s): %d seriam criados, %d atualizados, %d já cadastrados, %d inválido(s).',
                    $result->total,
                    $result->imported,
                    $result->updated,
                    $result->skipped,
                    $result->invalid,
                ))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Feriados importados.')
            ->body(sprintf(
                '%d criado(s), %d atualizado(s), %d já cadastrado(s), %d inválido(s). %d data(s) aplicada(s) ao calendário B3.',
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->invalid,
                $result->calendarApplied,
            ))
            ->success()
            ->send();
    }
}
