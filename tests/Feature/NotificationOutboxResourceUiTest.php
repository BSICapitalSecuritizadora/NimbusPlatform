<?php

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Filament\Resources\Nimbus\NotificationOutboxes\Pages\ListNotificationOutboxes;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->withTwoFactor()->create([
        'email' => 'auditor-envios@bsicapital.com.br',
    ]);
    $this->user->assignRole('admin');
});

it('renders the notification outbox audit list page with subheading and cockpit layout', function () {
    $this->actingAs($this->user)
        ->get(NotificationOutboxResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Auditoria de Envios')
        ->assertSee('Acompanhe o processamento, as tentativas e o resultado dos envios realizados pela plataforma.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-notification-outboxes-list-page');
});

it('displays columns with status badge, classification, recipient, subject, attempts and creation date', function () {
    $outbox = NotificationOutbox::factory()->create([
        'type' => 'token_created',
        'recipient_email' => 'investidor@empresa.com.br',
        'recipient_name' => 'Investidor Silva',
        'subject' => 'Sua nova chave de acesso BSI Capital',
        'template' => 'token_created',
        'payload_json' => ['token_id' => 123],
        'status' => 'SENT',
        'attempts' => 1,
        'max_attempts' => 5,
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->assertCanSeeTableRecords([$outbox])
        ->assertSee('Concluído')
        ->assertSee('Geração de Chave de Acesso')
        ->assertSee('Investidor Silva')
        ->assertSee('investidor@empresa.com.br')
        ->assertSee('Sua nova chave de acesso BSI Capital')
        ->assertSee('1 tentativa');
});

it('displays multiple attempts and failure badge color correctly', function () {
    $failedOutbox = NotificationOutbox::factory()->create([
        'type' => 'submission_received',
        'recipient_email' => 'falha@empresa.com.br',
        'subject' => 'Falha no envio de protocolo',
        'status' => 'FAILED',
        'attempts' => 3,
        'max_attempts' => 5,
        'last_error' => 'Connection timeout to SMTP gateway',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->assertCanSeeTableRecords([$failedOutbox])
        ->assertSee('Falhou')
        ->assertSee('3 de 5');
});

it('filters records by status tab', function () {
    $sentOutbox = NotificationOutbox::factory()->create([
        'type' => 'welcome_email',
        'recipient_email' => 'sent@empresa.com.br',
        'subject' => 'Envio concluido',
        'status' => 'SENT',
    ]);

    $pendingOutbox = NotificationOutbox::factory()->create([
        'type' => 'password_reset',
        'recipient_email' => 'pending@empresa.com.br',
        'subject' => 'Envio pendente',
        'status' => 'PENDING',
    ]);

    $failedOutbox = NotificationOutbox::factory()->create([
        'type' => 'new_announcement',
        'recipient_email' => 'failed@empresa.com.br',
        'subject' => 'Envio falho',
        'status' => 'FAILED',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->set('activeTab', 'concluidos')
        ->assertCanSeeTableRecords([$sentOutbox])
        ->assertCanNotSeeTableRecords([$pendingOutbox, $failedOutbox])
        ->set('activeTab', 'aguardando')
        ->assertCanSeeTableRecords([$pendingOutbox])
        ->assertCanNotSeeTableRecords([$sentOutbox, $failedOutbox])
        ->set('activeTab', 'falhas')
        ->assertCanSeeTableRecords([$failedOutbox])
        ->assertCanNotSeeTableRecords([$sentOutbox, $pendingOutbox]);
});

it('searches records by recipient name, email or subject', function () {
    $outbox1 = NotificationOutbox::factory()->create([
        'type' => 'user_precreated',
        'recipient_email' => 'carlos.alberto@empresa.com.br',
        'recipient_name' => 'Carlos Alberto Santos',
        'subject' => 'Seu acesso foi pré-aprovado',
        'status' => 'SENT',
    ]);

    $outbox2 = NotificationOutbox::factory()->create([
        'type' => 'new_general_document',
        'recipient_email' => 'mariana.costa@empresa.com.br',
        'recipient_name' => 'Mariana Costa',
        'subject' => 'Novo relatório gerencial publicado',
        'status' => 'SENT',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->searchTable('Carlos Alberto')
        ->assertCanSeeTableRecords([$outbox1])
        ->assertCanNotSeeTableRecords([$outbox2])
        ->searchTable('relatório gerencial')
        ->assertCanSeeTableRecords([$outbox2])
        ->assertCanNotSeeTableRecords([$outbox1]);
});

it('filters records by error occurrence', function () {
    $cleanOutbox = NotificationOutbox::factory()->create([
        'recipient_email' => 'clean@empresa.com.br',
        'status' => 'SENT',
        'last_error' => null,
    ]);

    $errorOutbox = NotificationOutbox::factory()->create([
        'recipient_email' => 'com-erro@empresa.com.br',
        'status' => 'FAILED',
        'last_error' => '550 Recipient rejected',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->filterTable('has_error', true)
        ->assertCanSeeTableRecords([$errorOutbox])
        ->assertCanNotSeeTableRecords([$cleanOutbox]);
});

it('displays friendly empty states for zero records without create action', function () {
    NotificationOutbox::query()->delete();

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->assertSee('Nenhum envio registrado')
        ->assertSee('Os envios processados pela plataforma aparecerão aqui para auditoria.')
        ->assertDontSee('Novo envio');
});

it('displays contextual empty state when search returns no records', function () {
    NotificationOutbox::factory()->create([
        'recipient_email' => 'existente@empresa.com.br',
        'subject' => 'Comunicado Mensal',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->searchTable('termo-inexistente-12345')
        ->assertSee('Nenhum envio encontrado')
        ->assertSee('Tente ajustar os filtros ou o termo pesquisado.')
        ->assertSee('Limpar filtros');
});

it('renders view notification details page with cockpit classes and infolist', function () {
    $outbox = NotificationOutbox::factory()->create([
        'type' => 'password_reset',
        'recipient_email' => 'detalhe@empresa.com.br',
        'recipient_name' => 'Roberto Auditor',
        'subject' => 'Instruções para redefinição',
        'correlation_id' => 'CORR-998877',
        'status' => 'SENT',
        'attempts' => 1,
        'payload_json' => ['user_id' => 45],
    ]);

    $this->actingAs($this->user)
        ->get(NotificationOutboxResource::getUrl('view', ['record' => $outbox], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Detalhes do Envio')
        ->assertSee('Histórico técnico e rastreabilidade detalhada do envio de notificação.')
        ->assertSee('CORR-998877')
        ->assertSee('Roberto Auditor')
        ->assertSee('detalhe@empresa.com.br');
});

it('allows cancelling a pending notification outbox record', function () {
    $pendingOutbox = NotificationOutbox::factory()->create([
        'status' => 'PENDING',
        'recipient_email' => 'cancelar@empresa.com.br',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->callTableAction('cancel', $pendingOutbox);

    expect($pendingOutbox->fresh()->status)->toBe('CANCELLED');
});

it('allows reprocessing a failed notification outbox record', function () {
    $failedOutbox = NotificationOutbox::factory()->create([
        'status' => 'FAILED',
        'attempts' => 3,
        'last_error' => 'Gateway 502',
        'recipient_email' => 'reprocessar@empresa.com.br',
    ]);

    Livewire::actingAs($this->user)
        ->test(ListNotificationOutboxes::class)
        ->callTableAction('reprocess', $failedOutbox);

    $fresh = $failedOutbox->fresh();
    expect($fresh->status)->toBe('PENDING')
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->last_error)->toBeNull();
});
