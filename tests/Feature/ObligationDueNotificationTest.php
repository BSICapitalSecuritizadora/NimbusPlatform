<?php

use App\Actions\Emissions\SendObligationDueNotificationsAction;
use App\Mail\ObligationDueNotificationMail;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function makeObligationFor(string $status, ?Carbon\CarbonInterface $dueDate, ?User $responsible = null): Obligation
{
    $emission = Emission::factory()->create(['name' => 'Emissão Teste']);

    return Obligation::factory()->for($emission)->create([
        'status' => $status,
        'due_date' => $dueDate,
        'responsible_user_id' => $responsible?->id,
        'title' => 'Comprovar destinação de recursos',
    ]);
}

function runNotifications(): array
{
    return app(SendObligationDueNotificationsAction::class)->handle();
}

beforeEach(function () {
    Mail::fake();
    config(['obligations.notifications.fallback_email' => null]);
});

it('notifies an obligation due in 7 days', function () {
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    makeObligationFor('a_vencer', now()->addDays(7), $user);

    $result = runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->hasTo('resp@bsi.test')
        && $mail->notificationType === ObligationNotification::TYPE_DUE_SOON
        && $mail->milestone === 'due_7');

    expect($result['sent'])->toBe(1);
});

it('notifies an obligation due in 3 days', function () {
    $user = User::factory()->create();
    makeObligationFor('a_vencer', now()->addDays(3), $user);

    runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->milestone === 'due_3'
        && $mail->notificationType === ObligationNotification::TYPE_DUE_SOON);
});

it('notifies an obligation due today', function () {
    $user = User::factory()->create();
    makeObligationFor('a_vencer', now(), $user);

    runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->milestone === 'due_today'
        && $mail->notificationType === ObligationNotification::TYPE_DUE_TODAY);
});

it('notifies an overdue obligation', function () {
    $user = User::factory()->create();
    makeObligationFor('vencida', now()->subDays(2), $user);

    runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->milestone === 'overdue'
        && $mail->notificationType === ObligationNotification::TYPE_OVERDUE);
});

it('does not notify completed obligations', function () {
    $user = User::factory()->create();
    makeObligationFor('concluida', now()->subDays(2), $user);

    $result = runNotifications();

    Mail::assertNothingSent();
    expect($result)->toMatchArray(['eligible' => 0, 'sent' => 0]);
});

it('does not notify manually decided (nao_aplicavel) obligations', function () {
    $user = User::factory()->create();
    makeObligationFor('nao_aplicavel', now(), $user);

    runNotifications();

    Mail::assertNothingSent();
});

it('does not notify obligations without a due date', function () {
    $user = User::factory()->create();
    makeObligationFor('a_vencer', null, $user);

    $result = runNotifications();

    Mail::assertNothingSent();
    expect($result['analyzed'])->toBe(0);
});

it('does not notify when there is no recipient and no fallback', function () {
    makeObligationFor('a_vencer', now()->addDays(7), null);

    $result = runNotifications();

    Mail::assertNothingSent();
    expect($result)->toMatchArray(['eligible' => 1, 'sent' => 0, 'ignored' => 1]);
});

it('uses the configured fallback email when there is no responsible user', function () {
    config(['obligations.notifications.fallback_email' => 'ops@bsi.test']);
    makeObligationFor('a_vencer', now()->addDays(7), null);

    runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->hasTo('ops@bsi.test'));
});

it('prefers the responsible user email over the configured fallback email', function () {
    config(['obligations.notifications.fallback_email' => 'ops@bsi.test']);
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    makeObligationFor('a_vencer', now()->addDays(7), $user);

    runNotifications();

    Mail::assertSent(ObligationDueNotificationMail::class, fn (ObligationDueNotificationMail $mail): bool => $mail->hasTo('resp@bsi.test')
        && ! $mail->hasTo('ops@bsi.test'));
});

it('does not send a duplicate notification for the same milestone', function () {
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    $obligation = makeObligationFor('a_vencer', now()->addDays(7), $user);

    ObligationNotification::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
        'milestone' => 'due_7',
        'status' => ObligationNotification::STATUS_SENT,
    ]);

    $result = runNotifications();

    Mail::assertNothingSent();
    expect($result)->toMatchArray(['eligible' => 1, 'sent' => 0, 'ignored' => 1]);
});

it('records a sent notification row for auditing', function () {
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    $obligation = makeObligationFor('a_vencer', now()->addDays(3), $user);

    runNotifications();

    $notification = $obligation->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->milestone)->toBe('due_3')
        ->and($notification->status)->toBe(ObligationNotification::STATUS_SENT)
        ->and($notification->recipient)->toBe('resp@bsi.test')
        ->and($notification->sent_at)->not->toBeNull();
});

it('runs the artisan command with a clear output', function () {
    $user = User::factory()->create();
    makeObligationFor('a_vencer', now()->addDays(7), $user);

    $this->artisan('obligations:send-due-notifications')
        ->expectsOutputToContain('Obrigações analisadas: 1')
        ->expectsOutputToContain('Notificações enviadas: 1')
        ->assertSuccessful();
});

it('registers the command in the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('obligations:send-due-notifications')
        ->assertSuccessful();
});
