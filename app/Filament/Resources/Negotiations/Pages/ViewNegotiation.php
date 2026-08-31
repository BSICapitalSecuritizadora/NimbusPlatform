<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Models\Negotiation;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNegotiation extends ViewRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-negotiation-view-page bsi-sales-board-view-page',
    ];

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $month = $record instanceof Negotiation
            ? ($record->formatted_reference_month ?: Negotiation::formatReferenceMonthForDisplay($record->reference_month))
            : null;

        return filled($month) ? "Negociação — {$month}" : 'Visualizar Negociação';
    }

    public function getSubheading(): ?string
    {
        return 'Resumo das movimentações comerciais registradas para a competência.';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->color('primary')
                ->visible(fn (Negotiation $record): bool => NegotiationResource::canEdit($record)),
        ];
    }
}
