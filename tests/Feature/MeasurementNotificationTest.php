<?php

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends through the configured nimbus mailer (graph), not the default', function () {
    config(['nimbus.mail.mailer' => 'graph']);

    $measurement = Measurement::factory()->create();

    $message = (new MeasurementWorkflowNotification($measurement, 'submitted'))
        ->toMail(User::factory()->create());

    expect($message->mailer)->toBe('graph');
});

it('renders the BSI Capital house template instead of the default markdown', function () {
    $measurement = Measurement::factory()->create(['status' => 'in_review', 'current_stage' => 1]);

    $message = (new MeasurementWorkflowNotification($measurement, 'submitted'))
        ->toMail(User::factory()->create(['name' => 'Anderson Cavalcante']));

    expect($message->view)->toBe('emails.measurements.workflow')
        ->and($message->viewData['firstName'])->toBe('Anderson')
        ->and($message->viewData['title'])->toBe('Nova medição para análise');

    $html = view($message->view, $message->viewData)->render();

    expect($html)->toContain('logo-bsi-email.png')
        ->and($html)->toContain('Acompanhamento de Medições');
});

it('notifies the first stage reviewer when a measurement enters review', function () {
    Notification::fake();

    $reviewer = User::factory()->create();
    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id]);

    app(MeasurementWorkflow::class)->startReview($measurement);

    Notification::assertSentTo(
        $reviewer,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'submitted',
    );
});

it('notifies the uploader and every rejection subscriber on rejection', function () {
    Notification::fake();

    $uploader = User::factory()->create();
    $watcherA = User::factory()->create();
    $watcherB = User::factory()->create();
    $actor = User::factory()->create();
    $operation = Operation::factory()->create(['responsible_user_id' => $actor->id]);
    $operation->rejectionNotifyUsers()->attach([$watcherA->id, $watcherB->id]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'uploaded_by' => $uploader->id,
        'status' => 'in_review',
    ]);

    app(MeasurementWorkflow::class)->reject($measurement, $actor, 'Faltam documentos');

    Notification::assertSentTo($uploader, MeasurementWorkflowNotification::class);
    Notification::assertSentTo($watcherA, MeasurementWorkflowNotification::class);
    Notification::assertSentTo($watcherB, MeasurementWorkflowNotification::class);
});

it('notifies the payment manager to attach receipts when a payment is registered', function () {
    Notification::fake();

    $manager = User::factory()->create();
    $operation = Operation::factory()->create(['payment_manager_user_id' => $manager->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
    ]);

    app(MeasurementWorkflow::class)->registerPayments($measurement, $manager, [
        ['amount' => 5000, 'pay_date' => now()],
    ]);

    Notification::assertSentTo(
        $manager,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'awaiting_receipt',
    );
});

it('notifies the finalizer when the last receipt is attached', function () {
    Notification::fake();

    $manager = User::factory()->create();
    $finalizer = User::factory()->create();
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_finalizer_user_id' => $finalizer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_receipt',
    ]);
    $payment = $measurement->payments()->create([
        'operation_id' => $operation->id,
        'amount' => 1000,
        'pay_date' => now(),
        'created_by' => $manager->id,
    ]);

    app(MeasurementWorkflow::class)->attachReceipt($payment, 'measurements/receipts/r.pdf');

    Notification::assertSentTo(
        $finalizer,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'receipt_attached',
    );
});

it('waits for every receipt before notifying the finalizer', function () {
    Notification::fake();

    $finalizer = User::factory()->create();
    $operation = Operation::factory()->create(['payment_finalizer_user_id' => $finalizer->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_receipt',
    ]);
    $paymentA = $measurement->payments()->create(['operation_id' => $operation->id, 'amount' => 100, 'pay_date' => now()]);
    $measurement->payments()->create(['operation_id' => $operation->id, 'amount' => 200, 'pay_date' => now()]);

    app(MeasurementWorkflow::class)->attachReceipt($paymentA, 'measurements/receipts/a.pdf');

    Notification::assertNotSentTo($finalizer, MeasurementWorkflowNotification::class);
});

it('notifies the payment manager when the last stage is approved', function () {
    Notification::fake();

    $actor = User::factory()->create();
    $paymentManager = User::factory()->create();
    $operation = Operation::factory()->create([
        'responsible_user_id' => $actor->id,
        'payment_manager_user_id' => $paymentManager->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'in_review',
        'current_stage' => 1,
    ]);

    app(MeasurementWorkflow::class)->approve($measurement, $actor);

    Notification::assertSentTo(
        $paymentManager,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'awaiting_payment',
    );
});
