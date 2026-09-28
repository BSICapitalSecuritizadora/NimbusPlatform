<?php

use App\Enums\AccessPermission;
use App\Enums\BusinessArea;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Areas\AreaResource;
use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Models\Area;
use App\Models\User;
use App\Services\AreaResponsibilityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function operationalUser(array $attributes = []): User
{
    return User::factory()->withTwoFactor()->create(['is_active' => true, 'approved_at' => now(), ...$attributes]);
}

it('has exactly one catalog row per business area', function () {
    $codes = Area::query()->orderBy('id')->get()->map(fn (Area $area): string => $area->code->value)->all();

    expect($codes)->toBe(array_map(fn (BusinessArea $area): string => $area->value, BusinessArea::cases()));
});

it('places every permission of the system in exactly one area', function () {
    $unclassified = [];
    $ambiguous = [];

    foreach (AccessPermission::values() as $permission) {
        $areas = array_filter(BusinessArea::cases(), fn (BusinessArea $area): bool => $area->coversPermission($permission));

        if ($areas === []) {
            $unclassified[] = $permission;
        } elseif (count($areas) > 1) {
            $ambiguous[] = $permission;
        }
    }

    expect($unclassified)->toBe([])->and($ambiguous)->toBe([]);
});

it('adds and removes responsibles and records who changed what', function () {
    $manager = makeAdminUser();
    $first = operationalUser(['name' => 'Ana']);
    $second = operationalUser(['name' => 'Bruno']);
    $area = Area::for(BusinessArea::PuCurve);
    $service = app(AreaResponsibilityService::class);

    $service->syncResponsibles($area, [$first->id, $second->id], $manager);
    $result = $service->syncResponsibles($area, [$second->id], $manager);

    $activity = Activity::query()->where('log_name', 'areas')->latest('id')->first();

    expect($result)->toBe(['added' => [], 'removed' => [$first->id]])
        ->and($service->isResponsible($second->id, BusinessArea::PuCurve))->toBeTrue()
        ->and($service->isResponsible($first->id, BusinessArea::PuCurve))->toBeFalse()
        ->and($service->isResponsible($second->id, BusinessArea::Guarantees))->toBeFalse()
        ->and($area->responsibles()->first()->pivot->assigned_by)->toBe($manager->id)
        ->and(Activity::query()->where('log_name', 'areas')->count())->toBe(2)
        ->and($activity->causer_id)->toBe($manager->id)
        ->and($activity->properties['removed'])->toBe([['id' => $first->id, 'name' => 'Ana']])
        ->and($activity->properties['responsibles'])->toBe([['id' => $second->id, 'name' => 'Bruno']]);
});

it('writes nothing when the responsibles did not change', function () {
    $manager = makeAdminUser();
    $user = operationalUser();
    $area = Area::for(BusinessArea::PuCurve);
    $service = app(AreaResponsibilityService::class);
    $service->syncResponsibles($area, [$user->id], $manager);

    $result = $service->syncResponsibles($area, [(string) $user->id], $manager);

    expect($result)->toBe(['added' => [], 'removed' => []])
        ->and(Activity::query()->where('log_name', 'areas')->count())->toBe(1);
});

it('refuses to make an inactive or unapproved user responsible, but lets them be removed', function () {
    $manager = makeAdminUser();
    $area = Area::for(BusinessArea::PuCurve);
    $service = app(AreaResponsibilityService::class);
    $responsible = operationalUser();
    $service->syncResponsibles($area, [$responsible->id], $manager);
    $responsible->update(['is_active' => false]);

    expect(fn () => $service->syncResponsibles($area, [$responsible->id, operationalUser(['is_active' => false])->id], $manager))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->syncResponsibles($area, [operationalUser(['approved_at' => null])->id], $manager))
        ->toThrow(ValidationException::class);

    $service->syncResponsibles($area, [], $manager);

    expect($area->responsibles()->count())->toBe(0);
});

it('requires the manage permission to change responsibles', function () {
    $viewer = operationalUser();
    $viewer->givePermissionTo(AccessPermission::AreasView->value);

    expect(fn () => app(AreaResponsibilityService::class)->syncResponsibles(Area::for(BusinessArea::PuCurve), [$viewer->id], $viewer))
        ->toThrow(AuthorizationException::class)
        ->and(Area::for(BusinessArea::PuCurve)->responsibles()->count())->toBe(0);
});

it('defines the responsibles of an area from the settings screen', function () {
    $this->actingAs(makeAdminUser());
    $responsible = operationalUser(['name' => 'Carla']);
    $area = Area::for(BusinessArea::PuCurve);

    Livewire::test(ManageAreas::class)
        ->assertCanSeeTableRecords(Area::query()->get())
        ->callAction(TestAction::make('defineResponsibles')->table($area), data: ['responsibles' => [$responsible->id]])
        ->assertHasNoActionErrors()
        ->assertSee('Carla');

    expect(app(AreaResponsibilityService::class)->isResponsible($responsible->id, BusinessArea::PuCurve))->toBeTrue();
});

it('shows the areas read-only to viewers and hides them from everyone else', function () {
    $viewer = operationalUser();
    $this->actingAs($viewer)->get(AreaResource::getUrl())->assertForbidden();

    $viewer->givePermissionTo(AccessPermission::AreasView->value);

    $this->get(AreaResource::getUrl())->assertSuccessful();
    Livewire::test(ManageAreas::class)
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('defineResponsibles')->table(Area::for(BusinessArea::PuCurve)));
    $this->get(Settings::getUrl())->assertSuccessful()->assertSee('Áreas e responsáveis');
});
