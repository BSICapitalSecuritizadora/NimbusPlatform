<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SalesBoardAutomationServiceProvider;
use App\Providers\SpreadsheetTemplateServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    SalesBoardAutomationServiceProvider::class,
    SpreadsheetTemplateServiceProvider::class,
];
