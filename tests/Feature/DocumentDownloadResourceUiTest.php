<?php

use App\Filament\Resources\DocumentDownloads\DocumentDownloadResource;
use App\Filament\Resources\DocumentDownloads\Pages\ManageDocumentDownloads;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Investor;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the document downloads table for users with the audit.document-downloads.view permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.document-downloads.view');

    Livewire::actingAs($user)
        ->test(ManageDocumentDownloads::class)
        ->assertSuccessful();
});

it('does not allow view any for users without permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->assignRole('editor');

    $this->actingAs($user);
    expect(DocumentDownloadResource::canViewAny())->toBeFalse();
});

it('has correct page title and subheading', function () {
    $page = new ManageDocumentDownloads;

    expect($page->getTitle())->toBe('Registros de downloads')
        ->and($page->getSubheading())->toContain('Acompanhe downloads realizados no ambiente administrativo');
});

it('renders download records with formatted document title, source badge and investor in table', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.document-downloads.view');

    $document = Document::factory()->create([
        'title' => 'Termo de Securitização CRI BSI',
    ]);
    $investor = Investor::factory()->create([
        'name' => 'Anderson Cavalcante',
    ]);

    DocumentDownload::create([
        'document_id' => $document->id,
        'investor_id' => $investor->id,
        'source' => 'portal',
        'ip' => '192.168.1.50',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
        'downloaded_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ManageDocumentDownloads::class)
        ->assertSuccessful()
        ->assertSee('Termo de Securitização CRI BSI')
        ->assertSee('Portal')
        ->assertSee('Anderson Cavalcante');
});
