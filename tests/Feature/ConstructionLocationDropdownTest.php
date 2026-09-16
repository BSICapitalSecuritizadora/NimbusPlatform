<?php

use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

function constructionFormSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return ConstructionForm::configure(Schema::make($livewire));
}

function constructionLocationSection(): Section
{
    $section = collect(constructionFormSchema()->getComponents())
        ->first(fn (mixed $component): bool => $component instanceof Section && $component->getHeading() === 'Localização');

    expect($section)->not->toBeNull();

    return $section;
}

/**
 * O select "Estado" já abriu um dropdown da largura da viewport: carregava a
 * classe `fi-fixed-positioning-context`, que força `position: fixed` no painel,
 * e com bloco de contenção na viewport o `min-width: 100%` do tema resolvia
 * para 100vw. O Floating UI então colava o painel gigante na borda esquerda.
 */
it('keeps the Estado dropdown anchored to its trigger instead of the viewport', function () {
    $stateSelect = collect(constructionLocationSection()->getChildComponents())
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'state');

    expect($stateSelect)->not->toBeNull()
        ->and(json_encode($stateSelect->getExtraAttributes()))->not->toContain('fi-fixed-positioning-context');
});

it('scopes the Estado dropdown height cap to the location section', function () {
    $sectionAttributes = constructionLocationSection()->getExtraAttributes();

    expect(json_encode($sectionAttributes))->toContain('bsi-construction-location-section');

    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($theme)->toContain('.bsi-construction-location-section .fi-select-input .fi-dropdown-panel {')
        ->and($theme)->toContain('max-height: min(20rem, calc(100vh - 2rem)) !important;');
});
