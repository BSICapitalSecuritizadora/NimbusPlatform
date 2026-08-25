<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\ReminderLogs\Pages\ManageReminderLogs;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Models\ReminderLog;
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

it('renders the reminder log table for users with the reminder_logs.view permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo(AccessPermission::ReminderLogsView->value);

    Livewire::actingAs($user)
        ->test(ManageReminderLogs::class)
        ->assertSuccessful();
});

it('does not render the reminder log table for users without permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->assignRole('editor');

    expect(ReminderLogResource::canViewAny())->toBeFalse();
});

it('has correct page title and subheading', function () {
    $page = new ManageReminderLogs;

    expect($page->getTitle())->toBe('Auditoria de lembretes')
        ->and($page->getSubheading())->toContain('Acompanhe os lembretes processados');
});

it('formats friendly names for type, channel, status, and severity', function () {
    expect(ReminderLogResource::friendlyType('DatabaseNotification'))->toBe('Notificação no sistema')
        ->and(ReminderLogResource::friendlyType('MeasurementWorkflowNotification'))->toBe('Fluxo de Medição')
        ->and(ReminderLogResource::friendlyType(null))->toBe('—')
        ->and(ReminderLogResource::friendlyChannel('database'))->toBe('Sistema')
        ->and(ReminderLogResource::friendlyChannel('mail'))->toBe('E-mail')
        ->and(ReminderLogResource::friendlyChannel(null))->toBe('—')
        ->and(ReminderLogResource::friendlyStatus('sent'))->toBe('Enviado')
        ->and(ReminderLogResource::friendlyStatus('failed'))->toBe('Falhou')
        ->and(ReminderLogResource::friendlyStatus('pending'))->toBe('Pendente')
        ->and(ReminderLogResource::friendlyStatus(null))->toBe('—')
        ->and(ReminderLogResource::friendlySeverity('info'))->toBe('Informativa')
        ->and(ReminderLogResource::friendlySeverity('warning'))->toBe('Atenção')
        ->and(ReminderLogResource::friendlySeverity('danger'))->toBe('Crítica')
        ->and(ReminderLogResource::friendlySeverity(null))->toBe('—');
});

it('renders reminder records with friendly labels in the livewire table', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo(AccessPermission::ReminderLogsView->value);

    ReminderLog::create([
        'type' => 'DatabaseNotification',
        'channel' => 'database',
        'status' => 'sent',
        'recipient_email' => 'anderson.cavalcante@bsicapital.com.br',
        'severity' => 'info',
        'reason' => 'Notificação de boas-vindas ao sistema',
        'sent_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ManageReminderLogs::class)
        ->assertSuccessful()
        ->assertSee('Notificação no sistema')
        ->assertSee('Sistema')
        ->assertSee('anderson.cavalcante@bsicapital.com.br')
        ->assertSee('Enviado')
        ->assertSee('Informativa')
        ->assertSee('Notificação de boas-vindas ao sistema');
});
