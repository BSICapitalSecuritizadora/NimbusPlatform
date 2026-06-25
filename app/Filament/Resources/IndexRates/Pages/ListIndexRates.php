<?php

namespace App\Filament\Resources\IndexRates\Pages;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Filament\Resources\IndexRates\IndexRateResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListIndexRates extends ListRecords
{
    protected static string $resource = IndexRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
}
