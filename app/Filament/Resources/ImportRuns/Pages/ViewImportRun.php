<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewImportRun extends ViewRecord
{
    protected static string $resource = ImportRunResource::class;

    protected static ?string $breadcrumb = 'Visualizar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return 'Visualizar Importação #'.$this->record->getKey();
    }

    /**
     * Only a way back to the module the run touched. Nothing here reprocesses,
     * edits or removes the execution.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewModule')
                ->label(fn (ImportRun $record): string => $record->type === ImportRun::TYPE_CONTRACTS
                    ? 'Ver Contratos'
                    : 'Ver Parcelas')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (ImportRun $record): ?string => static::moduleUrl($record))
                ->visible(fn (ImportRun $record): bool => static::moduleUrl($record) !== null),
        ];
    }

    private static function moduleUrl(ImportRun $record): ?string
    {
        if ($record->type === ImportRun::TYPE_CONTRACTS) {
            return ContractResource::canViewAny() ? ContractResource::getUrl() : null;
        }

        return ContractInstallmentResource::canViewAny() ? ContractInstallmentResource::getUrl() : null;
    }
}
