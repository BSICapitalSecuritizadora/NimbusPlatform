<?php

use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\Expense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function finishingModalActivity(): Activity
{
    Expense::factory()->create(['amount' => 100.00]);

    return Activity::query()->latest()->firstOrFail();
}

it('opens the audit view modal with heading, content and close action intact', function () {
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    $activity = finishingModalActivity();

    $component = Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->mountTableAction('view', $activity)
        ->assertSuccessful();

    $action = $component->instance()->getMountedAction();

    expect($action)->not->toBeNull()
        ->and($action->getModalHeading())->toBe('Visualizar registro de auditoria')
        ->and($action->getModalCancelAction()->getLabel())->toBe('Fechar');
});

it('clips modal header and footer to the window radius', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.fi-modal-window-ctn > .fi-modal-window > .fi-modal-header')
        ->toContain('border-top-left-radius: 0.75rem')
        ->toContain('border-top-right-radius: 0.75rem')
        ->toContain('.fi-modal-window-ctn > .fi-modal-window > .fi-modal-footer')
        ->toContain('border-bottom-left-radius: 0.75rem')
        ->toContain('border-bottom-right-radius: 0.75rem');
});

it('gives the non-sticky modal footer breathing room above its actions', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain(':not(.fi-modal-has-sticky-footer)')
        ->toContain('padding-top: 1rem');
});

it('keeps the institutional modal identity untouched', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.fi-modal-window')
        ->toContain('.fi-modal-footer')
        ->toContain('border-top: 1px solid')
        ->toContain('.fi-modal-footer .fi-btn-color-gray');
});
