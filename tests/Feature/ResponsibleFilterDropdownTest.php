<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Filament\Widgets\Proposals\ProposalAttentionTableWidget;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

it('assigns the bsi-responsible-filter class to the assigned_representative_id filter in proposal attention table widget', function () {
    $widget = new ProposalAttentionTableWidget;
    $table = $widget->table(Table::make($widget));

    $filter = collect($table->getFilters())
        ->first(fn ($filter) => $filter instanceof SelectFilter && $filter->getName() === 'assigned_representative_id');

    expect($filter)->not->toBeNull();

    $components = $filter->getSchemaComponents();
    expect($components)->not->toBeEmpty();
    $formField = $components[0];
    expect(json_encode($formField->getExtraAttributes()))->toContain('bsi-responsible-filter');
});

it('assigns the bsi-responsible-filter class to the assigned_representative_id filter in proposals table', function () {
    $table = ProposalsTable::configure(Table::make(new ProposalAttentionTableWidget));

    $filter = collect($table->getFilters())
        ->first(fn ($filter) => $filter instanceof SelectFilter && $filter->getName() === 'assigned_representative_id');

    expect($filter)->not->toBeNull();

    $components = $filter->getSchemaComponents();
    expect($components)->not->toBeEmpty();
    $formField = $components[0];
    expect(json_encode($formField->getExtraAttributes()))->toContain('bsi-responsible-filter');
});

it('assigns the bsi-responsible-filter class to the responsible_user_id filter in obligation operational table widget', function () {
    $widget = new ObligationOperationalTableWidget;
    $table = $widget->table(Table::make($widget));

    $filter = collect($table->getFilters())
        ->first(fn ($filter) => $filter instanceof SelectFilter && $filter->getName() === 'responsible_user_id');

    expect($filter)->not->toBeNull();

    $components = $filter->getSchemaComponents();
    expect($components)->not->toBeEmpty();
    $formField = $components[0];
    expect(json_encode($formField->getExtraAttributes()))->toContain('bsi-responsible-filter');
});

it('assigns the bsi-responsible-filter class to responsible_user_id in obligation dashboard filters form', function () {
    $dashboard = new ObligationDashboard;
    $schema = $dashboard->filtersForm(Schema::make($dashboard));

    $responsibleField = collect($schema->getFlatComponents())
        ->first(fn ($c) => $c instanceof Select && $c->getName() === 'responsible_user_id');

    expect($responsibleField)->not->toBeNull()
        ->and(json_encode($responsibleField->getExtraAttributes()))->toContain('bsi-responsible-filter');
});

it('assigns the bsi-responsible-filter class to responsible_user_id in cockpit dashboard filters form', function () {
    $user = new User;
    $this->actingAs($user);

    $dashboard = new Dashboard;
    $schema = $dashboard->filtersForm(Schema::make($dashboard));

    $responsibleField = collect($schema->getFlatComponents(withHidden: true))
        ->first(fn ($c) => $c instanceof Select && $c->getName() === 'responsible_user_id');

    expect($responsibleField)->not->toBeNull()
        ->and(json_encode($responsibleField->getExtraAttributes()))->toContain('bsi-responsible-filter');
});

it('lets the responsible select panel fall back to the trigger width and caps max-width/max-height', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-responsible-filter .fi-dropdown-panel')
        ->toContain('min-width: 0 !important')
        ->toContain('max-width: min(26.25rem, calc(100vw - 2rem)) !important')
        ->toContain('max-height: min(20rem, calc(100vh - 2rem)) !important');
});

it('pins search input as sticky and isolates internal scrolling to options list', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-responsible-filter .fi-dropdown-panel .fi-select-input-search-ctn')
        ->toContain('position: sticky !important')
        ->toContain('top: 0 !important')
        ->toContain('z-index: 10 !important')
        ->toContain('.bsi-responsible-filter .fi-dropdown-panel .fi-dropdown-list')
        ->toContain('overflow-y: auto !important')
        ->toContain('max-height: calc(20rem - 3.25rem) !important');
});

it('keeps responsible select options compact with elegant ellipsis truncation', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-responsible-filter .fi-dropdown-panel .fi-select-input-option')
        ->toContain('min-height: 2.375rem !important')
        ->toContain('max-height: 2.625rem !important')
        ->toContain('padding: 0.4375rem 0.75rem !important')
        ->toContain('text-overflow: ellipsis !important')
        ->toContain('white-space: nowrap !important');
});

it('preserves global dropdown panel identity without leaking into general selects', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.fi-dropdown-panel:has(.fi-select-input-options-ctn)')
        ->toContain('z-index: 10050 !important');
});
