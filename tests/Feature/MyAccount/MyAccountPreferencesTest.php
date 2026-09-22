<?php

use App\Filament\Pages\MyAccount;
use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\User;
use App\Models\UserPreference;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = makeAdminUser();
});

it('persists every personalization preference', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('preferencesData.theme', 'dark')
        ->set('preferencesData.sidebar_behavior', 'collapsed')
        ->set('preferencesData.table_density', 'compact')
        ->set('preferencesData.per_page', 50)
        ->set('preferencesData.home_page', 'dashboard')
        ->call('savePreferences')
        ->assertHasNoErrors()
        ->assertNotified('Preferências salvas com sucesso.');

    $preferences = $this->user->refresh()->preferences;

    expect($preferences->theme->value)->toBe('dark')
        ->and($preferences->sidebar_behavior->value)->toBe('collapsed')
        ->and($preferences->table_density->value)->toBe('compact')
        ->and($preferences->per_page)->toBe(50)
        ->and($preferences->home_page)->toBe('dashboard');
});

it('loads framework defaults when the user never saved preferences', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->assertSet('preferencesData.theme', 'system')
        ->assertSet('preferencesData.sidebar_behavior', 'remember')
        ->assertSet('preferencesData.table_density', 'comfortable')
        ->assertSet('preferencesData.per_page', 25)
        ->assertSet('preferencesData.home_page', 'admin');

    expect(UserPreference::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('keeps preferences isolated per user', function () {
    $other = User::factory()->create();
    $other->assignRole('admin');

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('preferencesData.theme', 'light')
        ->set('preferencesData.per_page', 100)
        ->call('savePreferences')
        ->assertHasNoErrors();

    expect(UserPreference::query()->where('user_id', $other->id)->exists())->toBeFalse()
        ->and(UserPreference::forUser($other)->theme->value)->toBe('system');
});

it('rejects preference values outside the allowed options', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('preferencesData.theme', 'neon')
        ->set('preferencesData.per_page', 999)
        ->set('preferencesData.home_page', 'https://evil.example.com')
        ->call('savePreferences')
        ->assertHasErrors(['preferencesData.theme', 'preferencesData.per_page', 'preferencesData.home_page']);

    expect(UserPreference::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('syncs the theme from the quick switcher endpoint', function () {
    $this->actingAs($this->user)
        ->postJson(route('account.preferences.theme'), ['theme' => 'dark'])
        ->assertNoContent();

    expect($this->user->refresh()->preferences->theme->value)->toBe('dark');
});

it('rejects invalid themes on the sync endpoint', function () {
    $this->actingAs($this->user)
        ->postJson(route('account.preferences.theme'), ['theme' => 'neon'])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson(route('account.preferences.theme'), [])
        ->assertUnprocessable();
});

it('requires authentication on the sync endpoint', function () {
    $this->postJson(route('account.preferences.theme'), ['theme' => 'dark'])
        ->assertUnauthorized();
});

it('injects the saved theme before the panel paints', function () {
    $this->user->preferences()->create(['theme' => 'dark']);

    $content = $this->actingAs($this->user)
        ->get(MyAccount::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->getContent();

    $themeSyncPosition = strpos($content, "window.localStorage.setItem('theme'");
    $filamentInitPosition = strpos($content, 'loadDarkMode');

    expect($themeSyncPosition)->not->toBeFalse()
        ->and($filamentInitPosition)->not->toBeFalse()
        ->and($themeSyncPosition)->toBeLessThan($filamentInitPosition);
});

it('applies the saved page size to tables without an explicit default', function () {
    $this->actingAs($this->user);
    $this->user->preferences()->create(['per_page' => 50]);

    $page = Livewire::actingAs($this->user)->test(ManageActivities::class)->instance();

    expect(UserPreference::globalDefaultTablePerPage(Table::make($page)))->toBe(50);
});

it('respects a table explicit default over the user preference', function () {
    $this->actingAs($this->user);
    $this->user->preferences()->create(['per_page' => 50]);

    $page = Livewire::actingAs($this->user)->test(ManageActivities::class)->instance();
    $table = Table::make($page)->defaultPaginationPageOption(25);

    expect($table->getDefaultPaginationPageOption())->toBe(25);
});

it('leaves tables with custom option lists untouched', function () {
    $this->actingAs($this->user);
    $this->user->preferences()->create(['per_page' => 100]);

    $page = Livewire::actingAs($this->user)->test(ManageActivities::class)->instance();
    $table = Table::make($page)->paginationPageOptions([10, 25]);

    expect(UserPreference::globalDefaultTablePerPage($table))->toBeNull();
});

it('extends the stock option list only for an explicit 100 choice', function () {
    $this->actingAs($this->user);

    expect(UserPreference::globalTablePageOptions())->toBeNull();

    $this->user->preferences()->create(['per_page' => 100]);
    $this->user->refresh();

    expect(UserPreference::globalTablePageOptions())->toBe([5, 10, 25, 50, 100]);
});

it('resolves the home url with a safe fallback', function () {
    expect(UserPreference::homeUrlFor(null))->toBe('/admin');

    $this->user->preferences()->create(['home_page' => 'dashboard']);

    expect(UserPreference::homeUrlFor($this->user->refresh()))->toBe(route('dashboard'));

    $this->user->preferences()->update(['home_page' => 'unknown-key']);

    expect(UserPreference::homeUrlFor($this->user->refresh()))->toBe('/admin');
});

it('hides the app dashboard home option from unverified users', function () {
    $unverified = User::factory()->unverified()->create();

    expect(UserPreference::homePageOptions($unverified))->not->toHaveKey('dashboard')
        ->and(UserPreference::homePageOptions($this->user))->toHaveKey('dashboard');
});
