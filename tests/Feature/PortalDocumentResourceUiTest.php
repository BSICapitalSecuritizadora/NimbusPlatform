<?php

use App\Filament\Resources\Nimbus\PortalDocuments\Pages\CreatePortalDocument;
use App\Filament\Resources\Nimbus\PortalDocuments\Pages\EditPortalDocument;
use App\Filament\Resources\Nimbus\PortalDocuments\Schemas\PortalDocumentForm;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

function portalDocumentUiUser(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('renders the create portal document page with full-width cockpit layout and correct actions', function (): void {
    $user = portalDocumentUiUser('nimbus.portal-documents.view', 'nimbus.portal-documents.create');
    $this->actingAs($user);

    $page = new CreatePortalDocument;
    expect($page->getTitle())->toBe('Novo Documento do Usuário')
        ->and($page->getSubheading())->toBe('Envie um documento específico para um usuário do portal, com título, descrição e arquivo.')
        ->and($page->getMaxContentWidth())->toBe(Width::Full);

    Livewire::test(CreatePortalDocument::class)
        ->assertSee('Novo Documento do Usuário')
        ->assertSee('Envie um documento específico para um usuário do portal, com título, descrição e arquivo.')
        ->assertSee('Dados do Documento')
        ->assertSee('Criar documento')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('configures the portal document form schema with full width and responsive columns', function (): void {
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $schema = PortalDocumentForm::configure(Schema::make($livewire));

    $grid = collect($schema->getComponents())
        ->first(fn (mixed $c): bool => $c instanceof Grid);

    expect($grid)->not->toBeNull()
        ->and($grid?->getColumnSpan())->toMatchArray(['default' => 'full']);

    $sections = collect($grid?->getChildSchema()?->getComponents() ?? [])
        ->filter(fn (mixed $c): bool => $c instanceof Section)
        ->values();

    expect($sections)->toHaveCount(2);

    $mainSection = $sections[0];
    expect($mainSection->getHeading())->toBe('Dados do Documento')
        ->and($mainSection->getDescription())->toBe('Envie um documento específico para um usuário do portal, com título, descrição e arquivo.')
        ->and($mainSection->getColumnSpan())->toMatchArray(['default' => 'full'])
        ->and($mainSection->getColumns())->toMatchArray(['default' => 1, 'md' => 2]);

    $portalUserSelect = $mainSection->getChildSchema()->getComponentByStatePath('nimbus_portal_user_id');
    $titleInput = $mainSection->getChildSchema()->getComponentByStatePath('title');
    $descriptionInput = $mainSection->getChildSchema()->getComponentByStatePath('description');
    $fileUpload = $mainSection->getChildSchema()->getComponentByStatePath('file_path');

    expect($portalUserSelect)->toBeInstanceOf(Select::class)
        ->and($portalUserSelect?->getColumnSpan())->toMatchArray(['default' => 1, 'md' => 1])
        ->and($titleInput)->toBeInstanceOf(TextInput::class)
        ->and($titleInput?->getColumnSpan())->toMatchArray(['default' => 1, 'md' => 1])
        ->and($descriptionInput)->toBeInstanceOf(Textarea::class)
        ->and($descriptionInput?->getColumnSpan())->toMatchArray(['default' => 'full'])
        ->and($fileUpload)->toBeInstanceOf(FileUpload::class)
        ->and($fileUpload?->getColumnSpan())->toMatchArray(['default' => 'full']);
});

it('renders the edit portal document page with full-width cockpit layout and actions', function (): void {
    $user = portalDocumentUiUser('nimbus.portal-documents.view', 'nimbus.portal-documents.update', 'nimbus.portal-documents.delete');
    $this->actingAs($user);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Investidor Silva',
        'email' => 'investidor.silva@example.com',
        'document_number' => '123.456.789-01',
    ]);

    $document = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Social Registrado',
        'description' => 'Documento enviado para conferência.',
        'file_path' => 'private/portal-documents/contrato.pdf',
        'file_original_name' => 'contrato.pdf',
        'file_size' => 1048576,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $user->id,
    ]);

    $page = new EditPortalDocument;
    expect($page->getMaxContentWidth())->toBe(Width::Full);

    Livewire::test(EditPortalDocument::class, [
        'record' => $document->getRouteKey(),
    ])
        ->assertSee('Editar Documento do Usuário')
        ->assertSee('Atualize os dados, destinatário ou arquivo do documento.')
        ->assertSee('Dados do Documento')
        ->assertSee('Informações do Arquivo')
        ->assertSee('Salvar alterações')
        ->assertSee('Cancelar')
        ->assertSee('Excluir');
});
