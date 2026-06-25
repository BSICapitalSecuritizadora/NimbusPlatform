<?php

namespace App\Filament\Resources\IndexRates\Pages;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Filament\Resources\IndexRates\IndexRateResource;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ListIndexRates extends ListRecords
{
    protected static string $resource = IndexRateResource::class;

    public function getSubheading(): ?string
    {
        $parts = [];

        foreach (['cdi' => 'CDI', 'ipca' => 'IPCA'] as $key => $label) {
            $status = Cache::get(sprintf('pu_index_sync_%s_status', $key));
            $lastFetched = IndexRate::query()
                ->where('source', 'bcb_sgs')
                ->where('indexer', strtoupper($key))
                ->max('fetched_at');

            if (is_array($status) && ($status['status'] ?? null) === 'failed') {
                $parts[] = sprintf('%s: última sincronização FALHOU (%s)', $label, $status['error'] ?? 'erro');
            } elseif ($lastFetched !== null) {
                $parts[] = sprintf('%s: sincronizado em %s', $label, CarbonImmutable::parse($lastFetched)->format('d/m/Y H:i'));
            } else {
                $parts[] = sprintf('%s: nunca sincronizado pelo Banco Central', $label);
            }
        }

        return implode(' • ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildSyncAction('syncCdi', 'Sincronizar CDI (BCB)', PuIndexer::Cdi),
            $this->buildSyncAction('syncIpca', 'Sincronizar IPCA (BCB)', PuIndexer::Ipca),
            Action::make('importPublished')
                ->label('Importar índices publicados')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('pu.index.import') ?? false)
                ->modalHeading('Importar índices publicados (CSV)')
                ->modaldescription('CSV com cabeçalho rate_date,rate_value[,notes]. Datas em YYYY-MM-DD (CDI) ou YYYY-MM (IPCA mensal). Linhas são importadas como PUBLICADAS.')
                ->form([
                    Select::make('indexer')
                        ->label('Indexador')
                        ->options([
                            PuIndexer::Cdi->value => 'CDI',
                            PuIndexer::Ipca->value => 'IPCA',
                        ])
                        ->default(PuIndexer::Ipca->value)
                        ->required(),
                    TextInput::make('source')
                        ->label('Fonte')
                        ->placeholder('Ex.: ANBIMA, B3, manual')
                        ->default('manual_import'),
                    FileUpload::make('csv_file')
                        ->label('Arquivo CSV')
                        ->disk('local')
                        ->directory('imports/index-rates')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path((string) $data['csv_file']);

                    try {
                        $result = app(IndexRateImportService::class)->importPublished(
                            PuIndexer::from((string) $data['indexer']),
                            $path,
                            filled($data['source'] ?? null) ? (string) $data['source'] : null,
                            auth()->id(),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()->title('Falha na importação.')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Índices importados.')
                        ->body(sprintf('%d linha(s) importada(s).%s', $result['imported'], $result['errors'] === [] ? '' : ' '.count($result['errors']).' linha(s) ignorada(s).'))
                        ->success()
                        ->send();
                }),
            Action::make('importProjectedSeries')
                ->label('Importar série projetada')
                ->icon('heroicon-o-presentation-chart-line')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('pu.index.import') ?? false)
                ->modalHeading('Importar série projetada IPCA (CSV)')
                ->modalDescription('Cria uma SÉRIE PROJETADA em estado "importada". A curva só poderá usá-la após aprovação maker/checker em "Séries Projetadas IPCA".')
                ->form([
                    TextInput::make('name')
                        ->label('Nome da série')
                        ->default('IPCA projetado')
                        ->required(),
                    TextInput::make('projection_source')
                        ->label('Fonte da projeção')
                        ->default('ANBIMA')
                        ->required(),
                    TextInput::make('version')
                        ->label('Versão')
                        ->default('v1'),
                    TextInput::make('reference_date')
                        ->label('Data de referência da projeção (YYYY-MM-DD)'),
                    FileUpload::make('csv_file')
                        ->label('Arquivo CSV (rate_date,rate_value)')
                        ->disk('local')
                        ->directory('imports/index-rates')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path((string) $data['csv_file']);

                    try {
                        $result = app(IndexRateImportService::class)->importProjectedSeries(
                            PuIndexer::Ipca,
                            $path,
                            [
                                'name' => $data['name'] ?? 'IPCA projetado',
                                'projection_source' => $data['projection_source'] ?? null,
                                'projection_policy' => 'market',
                                'version' => $data['version'] ?? 'v1',
                                'reference_date' => filled($data['reference_date'] ?? null) ? (string) $data['reference_date'] : null,
                            ],
                            auth()->id(),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()->title('Falha na importação.')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Série projetada importada.')
                        ->body(sprintf('Série #%d criada com %d linha(s). Aguardando aprovação maker/checker.', $result['series']->id, $result['imported']))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function buildSyncAction(string $name, string $label, PuIndexer $indexer): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->visible(fn (): bool => auth()->user()?->can('pu.index.sync') ?? false)
            ->modalHeading($label)
            ->modalDescription('Sincroniza o índice PUBLICADO a partir da API do Banco Central (SGS). Idempotente: não duplica nem sobrescreve dados de outra origem. Projeção IPCA de mercado NÃO vem daqui.')
            ->form([
                DatePicker::make('from')->label('De (opcional)'),
                DatePicker::make('to')->label('Até (opcional)'),
                Toggle::make('dry_run')->label('Dry-run (simular, sem persistir)')->default(false),
            ])
            ->action(function (array $data) use ($indexer): void {
                $to = filled($data['to'] ?? null) ? CarbonImmutable::parse((string) $data['to']) : CarbonImmutable::now();
                $from = filled($data['from'] ?? null)
                    ? CarbonImmutable::parse((string) $data['from'])
                    : $to->subDays((int) config('pu_indexes.bcb.default_window_days', 45));

                try {
                    $result = app(IndexRateSyncService::class)->sync(
                        $indexer,
                        $from,
                        $to,
                        (bool) ($data['dry_run'] ?? false),
                        auth()->id(),
                    );
                } catch (BcbSgsException $exception) {
                    Notification::make()
                        ->title('Falha na sincronização com o Banco Central.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $notification = Notification::make()
                    ->title($result->dryRun ? 'Dry-run concluído (nada persistido).' : 'Sincronização concluída.')
                    ->body(sprintf(
                        'Consultados: %d | Criados: %d | Atualizados: %d | Ignorados: %d',
                        $result->fetched,
                        $result->created,
                        $result->updated,
                        $result->skipped,
                    ));

                if ($result->hasErrors()) {
                    $notification->warning()->body($notification->getBody()."\n".implode("\n", $result->errors))->persistent();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }
}
