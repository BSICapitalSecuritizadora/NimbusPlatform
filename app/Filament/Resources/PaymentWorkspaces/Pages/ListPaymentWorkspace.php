<?php

namespace App\Filament\Resources\PaymentWorkspaces\Pages;

use App\Filament\Resources\PaymentWorkspaces\PaymentWorkspaceResource;
use App\Models\User;
use App\Services\MeasurementPaymentExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListPaymentWorkspace extends ListRecords
{
    protected static string $resource = PaymentWorkspaceResource::class;

    protected static ?string $title = 'Workspace de Pagamentos';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-payment-workspace-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Fila operacional derivada do workflow de medições. A exportação sempre reflete a visão filtrada e autorizada atual.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_payments')
                ->label('Exportar visão atual')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('measurements.export') ?? false)
                    && (auth()->user()?->can('measurements.view') ?? false))
                ->authorize(fn (): bool => (auth()->user()?->can('measurements.export') ?? false)
                    && (auth()->user()?->can('measurements.view') ?? false))
                ->schema([
                    Select::make('format')
                        ->label('Formato')
                        ->options([
                            'xlsx' => 'Excel (.xlsx)',
                            'csv' => 'CSV (.csv)',
                        ])
                        ->default('xlsx')
                        ->required()
                        ->native(false),
                ])
                ->modalHeading('Exportar pagamentos operacionais')
                ->modalDescription('O arquivo conterá somente os registros da visão filtrada atual que continuam visíveis no seu escopo.')
                ->action(function (array $data) {
                    $actor = auth()->user();
                    $query = $this->getFilteredTableQuery();
                    abort_unless($actor instanceof User, 403);
                    abort_unless($query instanceof Builder, 404);

                    return app(MeasurementPaymentExportService::class)->download(
                        $query,
                        $actor,
                        (string) $data['format'],
                        [
                            'search' => $this->tableSearch,
                            'filters' => $this->tableFilters,
                        ],
                    );
                }),
        ];
    }
}
