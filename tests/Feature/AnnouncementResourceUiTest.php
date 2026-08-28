<?php

use App\Filament\Resources\Nimbus\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Nimbus\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Nimbus\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Nimbus\Announcements\Schemas\AnnouncementForm;
use App\Models\Nimbus\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function announcementUiUser(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('renders the list announcements page with cockpit attributes, subheading, gold CTA and dynamic tabs', function (): void {
    $user = announcementUiUser('nimbus.announcements.view', 'nimbus.announcements.create');
    $this->actingAs($user);

    $page = new ListAnnouncements;
    expect($page->getTitle())->toBe('Avisos Gerais')
        ->and($page->getSubheading())->toBe('Gerencie comunicados e avisos exibidos aos usuários da plataforma.')
        ->and($page->getMaxContentWidth())->toBe(Width::Full);

    // Create different status announcements
    Announcement::query()->create([
        'title' => 'Aviso Ativo Normal',
        'body' => 'Conteúdo do comunicado ativo.',
        'level' => 'info',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    Announcement::query()->create([
        'title' => 'Aviso Agendado Futuro',
        'body' => 'Conteúdo do comunicado agendado.',
        'level' => 'warning',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(10),
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    Announcement::query()->create([
        'title' => 'Aviso Encerrado Passado',
        'body' => 'Conteúdo do comunicado já encerrado.',
        'level' => 'danger',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    Announcement::query()->create([
        'title' => 'Aviso Inativo Rascunho',
        'body' => 'Conteúdo do comunicado desativado.',
        'level' => 'success',
        'is_active' => false,
        'created_by_user_id' => $user->id,
    ]);

    Livewire::test(ListAnnouncements::class)
        ->assertSee('Avisos Gerais')
        ->assertSee('Gerencie comunicados e avisos exibidos aos usuários da plataforma.')
        ->assertSee('Novo aviso')
        ->assertSee('Todos')
        ->assertSee('Ativos')
        ->assertSee('Agendados')
        ->assertSee('Encerrados')
        ->assertSee('Inativos')
        ->assertSee('Aviso Ativo Normal')
        ->assertSee('Informativo')
        ->assertSee('Ativo')
        ->assertSee('Aviso Agendado Futuro')
        ->assertSee('Atenção')
        ->assertSee('Agendado')
        ->assertSee('Aviso Encerrado Passado')
        ->assertSee('Crítico')
        ->assertSee('Encerrado')
        ->assertSee('Aviso Inativo Rascunho')
        ->assertSee('Sucesso')
        ->assertSee('Inativo');
});

it('renders the custom empty state when there are no announcements', function (): void {
    $user = announcementUiUser('nimbus.announcements.view', 'nimbus.announcements.create');
    $this->actingAs($user);

    Livewire::test(ListAnnouncements::class)
        ->assertSee('Nenhum aviso geral cadastrado')
        ->assertSee('Crie um aviso para comunicar informações importantes aos usuários da plataforma.')
        ->assertSee('Novo aviso');
});

it('renders the search empty state when filtered with no results', function (): void {
    $user = announcementUiUser('nimbus.announcements.view', 'nimbus.announcements.create');
    $this->actingAs($user);

    Announcement::query()->create([
        'title' => 'Aviso Existente',
        'body' => 'Texto do aviso.',
        'level' => 'info',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    Livewire::test(ListAnnouncements::class)
        ->searchTable('TermoInexistenteXYZ123')
        ->assertSee('Nenhum aviso encontrado')
        ->assertSee('Tente ajustar os filtros ou o termo pesquisado.')
        ->assertSee('Limpar filtros');
});

it('configures the announcement form schema with full width and responsive columns', function (): void {
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $schema = AnnouncementForm::configure(Schema::make($livewire));

    $grid = collect($schema->getComponents())
        ->first(fn (mixed $c): bool => $c instanceof Grid);

    expect($grid)->not->toBeNull()
        ->and($grid?->getColumnSpan())->toMatchArray(['default' => 'full']);

    $sections = collect($grid?->getChildSchema()?->getComponents() ?? [])
        ->filter(fn (mixed $c): bool => $c instanceof Section)
        ->values();

    expect($sections)->toHaveCount(2);

    $contentSection = $sections[0];
    expect($contentSection->getHeading())->toBe('Conteúdo do Aviso')
        ->and($contentSection->getDescription())->toBe('Comunicados exibidos aos usuários no portal.')
        ->and($contentSection->getColumnSpan())->toMatchArray(['default' => 'full']);

    $publicationSection = $sections[1];
    expect($publicationSection->getHeading())->toBe('Publicação')
        ->and($publicationSection->getColumnSpan())->toMatchArray(['default' => 'full'])
        ->and($publicationSection->getColumns())->toMatchArray(['default' => 1, 'md' => 3]);
});

it('renders the create announcement page with full-width layout and actions', function (): void {
    $user = announcementUiUser('nimbus.announcements.view', 'nimbus.announcements.create');
    $this->actingAs($user);

    $page = new CreateAnnouncement;
    expect($page->getTitle())->toBe('Novo Aviso Geral')
        ->and($page->getSubheading())->toBe('Cadastre um novo comunicado a ser exibido aos usuários do portal.')
        ->and($page->getMaxContentWidth())->toBe(Width::Full);

    Livewire::test(CreateAnnouncement::class)
        ->assertSee('Novo Aviso Geral')
        ->assertSee('Cadastre um novo comunicado a ser exibido aos usuários do portal.')
        ->assertSee('Conteúdo do Aviso')
        ->assertSee('Publicação')
        ->assertSee('Criar aviso')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('renders the edit announcement page with full-width layout and actions', function (): void {
    $user = announcementUiUser('nimbus.announcements.view', 'nimbus.announcements.update', 'nimbus.announcements.delete');
    $this->actingAs($user);

    $announcement = Announcement::query()->create([
        'title' => 'Atualização Importante',
        'body' => 'Corpo do aviso para teste.',
        'level' => 'warning',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $page = new EditAnnouncement;
    expect($page->getTitle())->toBe('Editar Aviso Geral')
        ->and($page->getSubheading())->toBe('Atualize o conteúdo, nível de alerta ou período de exibição do aviso.')
        ->and($page->getMaxContentWidth())->toBe(Width::Full);

    Livewire::test(EditAnnouncement::class, [
        'record' => $announcement->getRouteKey(),
    ])
        ->assertSee('Editar Aviso Geral')
        ->assertSee('Atualize o conteúdo, nível de alerta ou período de exibição do aviso.')
        ->assertSee('Salvar alterações')
        ->assertSee('Cancelar')
        ->assertSee('Excluir');
});
