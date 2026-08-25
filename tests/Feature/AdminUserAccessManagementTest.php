<?php

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Http\Controllers\Auth\AzureController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('allows a super admin to create an SSO-only user with roles and direct permissions', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $role = Role::findByName('editor');
    $permission = Permission::findByName('funds.delete');

    $this->actingAs($superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana Acesso',
            'email' => 'ANA.ACESSO@BSICAPITAL.COM.BR',
            'cargo' => 'Analista',
            'departamento' => 'Gestão',
            'is_active' => true,
            'roles' => [$role->id],
            'permissions' => [$permission->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ana.acesso@bsicapital.com.br')->first();

    expect($user)->not->toBeNull()
        ->and($user?->password)->not->toBeNull()
        ->and(Hash::check('password', (string) $user?->password))->toBeFalse()
        ->and($user?->approved_at)->not->toBeNull()
        ->and($user?->invited_by)->toBe($superAdmin->id)
        ->and($user?->hasRole('editor'))->toBeTrue()
        ->and($user?->hasDirectPermission('funds.delete'))->toBeTrue();
});

it('restricts user and role management to super admins', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse();
});

it('logs user status changes to the activity log when is_active is updated', function () {
    $target = User::factory()->create(['is_active' => true]);

    $target->update(['is_active' => false]);

    $log = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $target->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    expect($log)->not->toBeNull()
        ->and(array_key_exists('is_active', $log->properties['attributes'] ?? []))->toBeTrue()
        ->and(array_key_exists('is_active', $log->properties['old'] ?? []))->toBeTrue();
});

it('logs out an inactive user and redirects to admin login when accessing a protected route', function () {
    $user = User::factory()->create([
        'is_active' => false,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('redirects an unapproved but active user to pending-approval', function () {
    $user = User::factory()->unapproved()->create([
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('pending-approval'));
});

it('blocks inactive users and users without panel permissions', function () {
    $inactive = User::factory()->create([
        'is_active' => false,
    ]);
    $inactive->assignRole('admin');

    $withoutPermissions = User::factory()->create();

    expect($inactive->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($withoutPermissions->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('authenticates an active authorized user through Microsoft 365 only', function () {
    $user = User::factory()->create([
        'email' => 'maria@bsicapital.com.br',
        'azure_id' => null,
        'last_login_at' => null,
    ]);
    $user->assignRole('admin');

    $provider = Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(new class implements SocialiteUser
        {
            /**
             * @var array<string, mixed>
             */
            public array $user = [
                'mail' => 'maria@bsicapital.com.br',
                'amr' => ['pwd', 'mfa'],
            ];

            public function getId(): string
            {
                return 'azure-user-123';
            }

            public function getNickname(): ?string
            {
                return null;
            }

            public function getName(): ?string
            {
                return 'Maria SSO';
            }

            public function getEmail(): ?string
            {
                return 'maria@bsicapital.com.br';
            }

            public function getAvatar(): ?string
            {
                return null;
            }
        });

    Socialite::shouldReceive('driver')
        ->with('azure')
        ->once()
        ->andReturn($provider);

    $response = $this->get(route('auth.azure.callback'));

    $response->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);

    expect(session('auth.microsoft_sso'))->toBeTrue()
        ->and($user->refresh()->azure_id)->toBe('azure-user-123')
        ->and($user->last_login_at)->not->toBeNull();
});

it('normalizes Microsoft email claims from fallback fields', function () {
    $controller = new AzureController;

    $reflection = new ReflectionMethod($controller, 'resolveMicrosoftEmail');
    $reflection->setAccessible(true);

    $email = $reflection->invoke($controller, new class implements SocialiteUser
    {
        /**
         * @var array<string, string>
         */
        public array $user = [
            'userPrincipalName' => 'USER@BSICAPITAL.COM.BR',
        ];

        public function getId(): string
        {
            return 'azure-user-456';
        }

        public function getNickname(): ?string
        {
            return null;
        }

        public function getName(): ?string
        {
            return null;
        }

        public function getEmail(): ?string
        {
            return null;
        }

        public function getAvatar(): ?string
        {
            return null;
        }
    });

    expect($email)->toBe('user@bsicapital.com.br');
});

it('renders the users list page with cockpit headers and columns for super admins', function () {
    $superAdmin = User::factory()->create([
        'name' => 'Anderson Cavalcante',
        'email' => 'anderson.cavalcante@bsicapital.com.br',
        'cargo' => 'Desenvolvedor',
        'departamento' => 'Tecnologia',
        'is_active' => true,
    ]);
    $superAdmin->assignRole('super-admin');

    Livewire::actingAs($superAdmin)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$superAdmin])
        ->assertTableColumnStateSet('name', 'Anderson Cavalcante', $superAdmin)
        ->assertTableColumnStateSet('email', 'anderson.cavalcante@bsicapital.com.br', $superAdmin)
        ->assertTableColumnStateSet('cargo', 'Desenvolvedor', $superAdmin)
        ->assertTableColumnStateSet('departamento', 'Tecnologia', $superAdmin)
        ->assertTableColumnStateSet('is_active', 'Ativo', $superAdmin);
});

it('displays the empty state when no users match the search filter', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin);

    Livewire::test(ListUsers::class)
        ->searchTable('usuario_inexistente_9999')
        ->assertSee('Nenhum usuário encontrado')
        ->assertSee('Limpar busca');
});

it('filters users by status and role correctly', function () {
    $superAdmin = User::factory()->create([
        'name' => 'Super Admin User',
        'is_active' => true,
    ]);
    $superAdmin->assignRole('super-admin');

    $inactiveUser = User::factory()->create([
        'name' => 'Inactive User',
        'is_active' => false,
    ]);
    $inactiveUser->assignRole('editor');

    $this->actingAs($superAdmin);

    Livewire::test(ListUsers::class)
        ->filterTable('is_active', '0')
        ->assertCanSeeTableRecords([$inactiveUser])
        ->assertCanNotSeeTableRecords([$superAdmin]);
});

it('allows a super admin to edit user details and sync direct permissions in EditUser', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $targetUser = User::factory()->create([
        'name' => 'Carlos Gestor',
        'email' => 'carlos.gestor@bsicapital.com.br',
        'cargo' => 'Coordenador',
        'departamento' => 'Operações',
        'is_active' => true,
    ]);
    $targetUser->assignRole('editor');

    $perm1 = Permission::findByName('documents.view');
    $perm2 = Permission::findByName('obligations.view');

    $this->actingAs($superAdmin);

    Livewire::test(EditUser::class, [
        'record' => $targetUser->getKey(),
    ])
        ->assertSchemaComponentExists('cargo')
        ->assertSchemaComponentExists('roles')
        ->assertSchemaComponentExists('permissions')
        ->fillForm([
            'cargo' => 'Gerente de Operações',
            'permissions' => [$perm1->id, $perm2->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $targetUser->refresh();

    expect($targetUser->cargo)->toBe('Gerente de Operações')
        ->and($targetUser->hasDirectPermission('documents.view'))->toBeTrue()
        ->and($targetUser->hasDirectPermission('obligations.view'))->toBeTrue();
});

it('renders the roles list page with summary columns and search placeholder', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $role = Role::findByName('editor');

    Livewire::actingAs($superAdmin)
        ->test(ListRoles::class)
        ->assertCanSeeTableRecords([$role])
        ->assertTableColumnStateSet('name', 'editor', $role)
        ->assertTableColumnFormattedStateSet('name', 'Editor', $role)
        ->assertSee('Alcance e Permissões')
        ->assertSee('Usuários vinculados');
});

it('displays empty state on roles list when search yields no matches', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    Livewire::actingAs($superAdmin)
        ->test(ListRoles::class)
        ->searchTable('perfil_totalmente_inexistente_9999')
        ->assertSee('Nenhum perfil encontrado')
        ->assertSee('Limpar busca');
});

it('allows super admin to edit a role and sync permissions in EditRole', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $customRole = Role::create([
        'name' => 'analista-operacoes',
        'guard_name' => 'web',
    ]);

    $perm1 = Permission::findByName('emissions.view');
    $perm2 = Permission::findByName('contracts.view');

    Livewire::actingAs($superAdmin)
        ->test(EditRole::class, [
            'record' => $customRole->getKey(),
        ])
        ->assertSchemaComponentExists('name')
        ->assertSchemaComponentExists('permissions')
        ->fillForm([
            'name' => 'analista-operacoes-senior',
            'permissions' => [$perm1->id, $perm2->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $customRole->refresh();

    expect($customRole->name)->toBe('analista-operacoes-senior')
        ->and($customRole->hasPermissionTo('emissions.view'))->toBeTrue()
        ->and($customRole->hasPermissionTo('contracts.view'))->toBeTrue();
});
