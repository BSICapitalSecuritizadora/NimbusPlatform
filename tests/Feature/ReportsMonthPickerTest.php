<?php

use App\Filament\Pages\Reports;
use App\Models\Emission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('renders the reports page with the institutional month picker instead of native month input', function () {
    Livewire::test(Reports::class)
        ->assertOk()
        ->assertDontSeeHtml('type="month"')
        ->assertSeeHtml('bsi-month-picker')
        ->assertSeeHtml('bsi-month-picker-control')
        ->assertSeeHtml('id="referenceMonth"')
        ->assertSeeHtml('id="referenceMonthEnd"')
        ->assertSeeHtml('placeholder="mm/aaaa"')
        ->assertSee('Competência inicial')
        ->assertSee('Competência final');
});

it('binds referenceMonth and referenceMonthEnd with Livewire state', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Alpha',
        'if_code' => null,
        'isin_code' => null,
    ]);

    Livewire::test(Reports::class)
        ->set('emissionId', $emission->id)
        ->set('referenceMonth', '2026-09')
        ->assertSet('referenceMonth', '2026-09')
        ->assertSee('CRI Alpha · Setembro de 2026')
        ->set('referenceMonthEnd', '2026-12')
        ->assertSet('referenceMonthEnd', '2026-12')
        ->assertSee('Relatório consolidado')
        ->assertSee('CRI Alpha · Setembro de 2026 a Dezembro de 2026')
        ->set('referenceMonthEnd', '')
        ->assertSet('referenceMonthEnd', '')
        ->assertSee('Relatório mensal')
        ->assertSee('CRI Alpha · Setembro de 2026');
});

it('preserves interval validation between initial and final competence', function () {
    $emission = Emission::factory()->create([
        'if_code' => null,
        'isin_code' => null,
    ]);

    Livewire::test(Reports::class)
        ->set('emissionId', $emission->id)
        ->set('referenceMonth', '2026-09')
        ->set('referenceMonthEnd', '2026-08')
        ->assertSee('A competência final deve ser igual ou posterior à competência inicial.')
        ->assertDontSee('target="_blank"', false)
        ->set('referenceMonthEnd', '2026-09')
        ->assertDontSee('A competência final deve ser igual ou posterior à competência inicial.')
        ->assertSee('target="_blank"', false);
});

it('lays out emission, competences, arrow and action as independent columns of a single grid', function () {
    $html = Livewire::test(Reports::class)->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);

    $row = $xpath->query('//select[@id="emissionId"]/ancestor::div[contains(concat(" ", @class, " "), " grid ")][1]')->item(0);

    expect($row)->not->toBeNull()
        ->and($row->getAttribute('class'))->toContain('xl:grid-cols-[minmax(14rem,1.4fr)_minmax(11rem,1fr)_auto_minmax(11rem,1fr)_auto]');

    $columns = $xpath->query('./div', $row);

    expect($columns)->toHaveCount(5)
        ->and($xpath->query('.//select[@id="emissionId"]', $columns->item(0)))->toHaveCount(1)
        ->and($xpath->query('.//input[@id="referenceMonth"]', $columns->item(1)))->toHaveCount(1)
        ->and($columns->item(2)->getAttribute('aria-hidden'))->toBe('true')
        ->and($xpath->query('.//input[@id="referenceMonthEnd"]', $columns->item(3)))->toHaveCount(1)
        ->and($columns->item(4)->textContent)->toContain('Gerar PDF')
        ->and($xpath->query('.//div[contains(concat(" ", @class, " "), " grid ")]', $row))->toHaveCount(0);
});
