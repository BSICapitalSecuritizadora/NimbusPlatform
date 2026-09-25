<?php

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Support\AuthorizesThroughModelPolicy;
use App\Models\ContactMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Quando não existe policy para o model, o Filament libera o Resource por
 * padrão. Esconder o item de navegação com `->visible(...)` some com o menu,
 * mas a URL direta continua respondendo — o gate precisa estar no Resource.
 *
 * O gate explícito é um `canViewAny()` no próprio Resource ou o trait
 * {@see AuthorizesThroughModelPolicy}, que leva todo `can*()` à policy do
 * model. Neste caso o texto do arquivo não prova nada: confere-se que a policy
 * está registrada e responde `viewAny` — sem ela o trait nega tudo, e o
 * Resource ficaria fechado até para o administrador.
 */
it('gates every filament resource behind an explicit authorization check', function () {
    $isAuthorizedThroughRegisteredPolicy = function (SplFileInfo $file): bool {
        $resource = 'App\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if ((! class_exists($resource)) || (! in_array(AuthorizesThroughModelPolicy::class, class_uses_recursive($resource), true))) {
            return false;
        }

        $policy = Gate::getPolicyFor($resource::getModel());

        return is_object($policy) && method_exists($policy, 'viewAny');
    };

    $ungated = collect(File::allFiles(app_path('Filament/Resources')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Resource.php'))
        ->reject(fn (SplFileInfo $file): bool => str_contains(File::get($file->getPathname()), 'canViewAny')
            || $isAuthorizedThroughRegisteredPolicy($file))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($ungated)->toBe([]);
});

it('denies the contact message inbox to a role without the permission', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user);

    expect(ContactMessageResource::canViewAny())->toBeFalse();
})->with(['editor', 'commercial-representative']);

it('allows the contact message inbox to a user with the permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('contact-messages.view');

    $this->actingAs($user);

    expect(ContactMessageResource::canViewAny())->toBeTrue();
});

it('separates reading a contact message from registering its follow-up', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('contact-messages.view');

    $this->actingAs($user);

    $message = ContactMessage::factory()->create();

    expect(ContactMessageResource::canViewAny())->toBeTrue()
        ->and(ContactMessageResource::canEdit($message))->toBeFalse();

    $user->givePermissionTo('contact-messages.update');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(ContactMessageResource::canEdit($message->fresh()))->toBeTrue();
});

it('denies the reminder audit trail to a role without the permission', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user);

    expect(ReminderLogResource::canViewAny())->toBeFalse();
})->with(['editor', 'commercial-representative']);

it('allows the reminder audit trail to a user with the permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reminder-logs.view');

    $this->actingAs($user);

    expect(ReminderLogResource::canViewAny())->toBeTrue();
});

it('grants the new permissions to the administrator roles', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('contact-messages.view'))->toBeTrue()
        ->and($user->can('contact-messages.update'))->toBeTrue()
        ->and($user->can('reminder-logs.view'))->toBeTrue();
})->with(['admin', 'super-admin']);

/**
 * O deploy roda `migrate`, não `db:seed`. Sem a migration que cria e concede as
 * permissões novas, os dois Resources ficariam inacessíveis para todo mundo —
 * inclusive para o administrador — no primeiro boot depois do deploy.
 */
it('creates the new permissions through a migration, not only through the seeder', function () {
    $migration = collect(File::files(database_path('migrations')))
        ->first(fn (SplFileInfo $file): bool => str_contains(
            $file->getFilename(),
            'grant_contact_message_and_reminder_log_permissions',
        ));

    expect($migration)->not->toBeNull();

    $contents = File::get($migration->getPathname());

    expect($contents)->toContain('ContactMessagesView')
        ->and($contents)->toContain('ContactMessagesUpdate')
        ->and($contents)->toContain('ReminderLogsView');
});
