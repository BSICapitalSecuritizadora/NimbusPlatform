<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReceivable extends ViewRecord
{
    protected static string $resource = ReceivableResource::class;

    protected static ?string $title = 'Visualizar Resumo de Recebíveis';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-receivable-view-page',
    ];

    public function getSubheading(): ?string
    {
        /** @var Receivable $record */
        $record = $this->getRecord();

        $development = $record->emission?->constructions?->pluck('development_name')->filter()->unique()->implode(', ');
        $operation = $record->emission?->name;
        $month = Receivable::formatReferenceMonthForDisplay($record->reference_month);

        return collect([
            $development ?: $operation,
            filled($month) ? "Competência {$month}" : null,
            $record->emission?->bsi_code,
            filled($record->portfolio_id) ? "Carteira {$record->portfolio_id}" : null,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->color('primary')
                ->outlined(),
        ];
    }
}
