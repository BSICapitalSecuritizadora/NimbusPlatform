<?php

namespace App\Filament\Resources\MeasurementFinancialRules\Pages;

use App\Filament\Resources\MeasurementFinancialRules\MeasurementFinancialRuleResource;
use App\Models\MeasurementFinancialRule;
use App\Services\MeasurementFinancialRuleService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMeasurementFinancialRules extends ManageRecords
{
    protected static string $resource = MeasurementFinancialRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Cadastrar regra')
                ->visible(fn (): bool => MeasurementFinancialRuleResource::canCreate())
                ->authorize(fn (): bool => MeasurementFinancialRuleResource::canCreate())
                ->using(fn (array $data): MeasurementFinancialRule => MeasurementFinancialRuleResource::withValidationFeedback(fn () => app(MeasurementFinancialRuleService::class)->register($data, auth()->user()))),
        ];
    }
}
