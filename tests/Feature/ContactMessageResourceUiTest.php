<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Tables\ContactMessagesTable;
use App\Models\ContactMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function contactMessageUiUser(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('renders the contact messages list page with cockpit styling and subheadings', function (): void {
    $user = contactMessageUiUser(AccessPermission::ContactMessagesView->value);
    $this->actingAs($user);

    $page = new ListContactMessages;
    expect($page->getTitle())->toBe('Mensagens de contato')
        ->and($page->getSubheading())->toContain('Acompanhe as mensagens recebidas');
});

it('does not render a create action in the header', function (): void {
    $user = contactMessageUiUser(AccessPermission::ContactMessagesView->value);
    $this->actingAs($user);

    $headerActionsMethod = new ReflectionMethod(ListContactMessages::class, 'getHeaderActions');
    $headerActionsMethod->setAccessible(true);
    $actions = $headerActionsMethod->invoke(new ListContactMessages);

    expect($actions)->toBeArray()->toBeEmpty();
});

it('configures the table with search placeholder and inbox empty state', function (): void {
    $table = ContactMessagesTable::configure(Table::make(new ListContactMessages));

    expect($table->getSearchPlaceholder())->toBe('Buscar por nome, assunto ou e-mail...')
        ->and($table->getEmptyStateHeading())->toBe('Nenhuma mensagem recebida')
        ->and($table->getEmptyStateDescription())->toBe('As mensagens enviadas pelos canais de contato do site aparecerão aqui.')
        ->and($table->getEmptyStateIcon())->toBe('heroicon-o-inbox')
        ->and($table->getDefaultPaginationPageOption())->toBe(10);
});

it('has proper columns configured for inbox reading hierarchy', function (): void {
    $table = ContactMessagesTable::configure(Table::make(new ListContactMessages));
    $columns = collect($table->getColumns());

    expect($columns->has('name'))->toBeTrue()
        ->and($columns->has('subject'))->toBeTrue()
        ->and($columns->has('status'))->toBeTrue()
        ->and($columns->has('email'))->toBeTrue()
        ->and($columns->has('created_at'))->toBeTrue();

    $nameColumn = $columns->get('name');
    expect($nameColumn->isSearchable())->toBeTrue()
        ->and($nameColumn->isSortable())->toBeTrue();

    $emailColumn = $columns->get('email');
    expect($emailColumn->isCopyable('carlos@empresa.com.br'))->toBeTrue();
});

it('renders contact messages in the livewire table', function (): void {
    $user = contactMessageUiUser(AccessPermission::ContactMessagesView->value);
    $this->actingAs($user);

    $message1 = ContactMessage::factory()->create([
        'name' => 'Carlos Alberto Silva',
        'email' => 'carlos@empresa.com.br',
        'subject' => 'Comercial e novos negócios',
        'status' => ContactMessage::STATUS_NEW,
    ]);

    $message2 = ContactMessage::factory()->create([
        'name' => 'Mariana Souza',
        'email' => 'mariana@investimentos.com.br',
        'subject' => 'Relações com investidores',
        'status' => ContactMessage::STATUS_DONE,
    ]);

    Livewire::test(ListContactMessages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$message1, $message2])
        ->assertSee('Carlos Alberto Silva')
        ->assertSee('Mariana Souza')
        ->assertSee('Comercial e novos negócios')
        ->assertSee('Relações com investidores')
        ->assertSee('Novo')
        ->assertSee('Respondido');
});

it('filters contact messages by tab', function (): void {
    $user = contactMessageUiUser(AccessPermission::ContactMessagesView->value);
    $this->actingAs($user);

    $newMessage = ContactMessage::factory()->create([
        'name' => 'Lead Novo',
        'status' => ContactMessage::STATUS_NEW,
    ]);

    $doneMessage = ContactMessage::factory()->create([
        'name' => 'Lead Atendido',
        'status' => ContactMessage::STATUS_DONE,
    ]);

    Livewire::test(ListContactMessages::class)
        ->set('activeTab', 'novas')
        ->assertCanSeeTableRecords([$newMessage])
        ->assertCanNotSeeTableRecords([$doneMessage])
        ->set('activeTab', 'respondidas')
        ->assertCanSeeTableRecords([$doneMessage])
        ->assertCanNotSeeTableRecords([$newMessage]);
});

it('allows viewing a contact message when authorized', function (): void {
    $user = contactMessageUiUser(AccessPermission::ContactMessagesView->value);
    $this->actingAs($user);

    $message = ContactMessage::factory()->create([
        'name' => 'Roberta Mendes',
        'email' => 'roberta@teste.com',
        'subject' => 'Compliance e ética',
        'message' => 'Mensagem detalhada sobre governança.',
    ]);

    $this->get(ContactMessageResource::getUrl('view', ['record' => $message]))
        ->assertSuccessful()
        ->assertSee('Roberta Mendes')
        ->assertSee('roberta@teste.com')
        ->assertSee('Compliance e ética')
        ->assertSee('Mensagem detalhada sobre governança.');
});
