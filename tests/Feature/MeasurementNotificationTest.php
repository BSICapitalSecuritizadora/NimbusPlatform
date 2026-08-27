<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function measurementNotificationActor(): User
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    return $user;
}

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
        ->and($message->viewData['title'])->toBe('Nova medição para análise')
        ->and((new MeasurementWorkflowNotification($measurement, 'submitted'))->via(User::factory()->create()))
        ->toBe(['mail', 'database']);

    $html = view($message->view, $message->viewData)->render();

    expect($html)->toContain('logo-bsi-email.png')
        ->and($html)->toContain('Acompanhamento de Medições');
});

it('notifies the first stage reviewer when a measurement enters review', function () {
    Notification::fake();

    $reviewer = measurementNotificationActor();
    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id]);

    app(MeasurementWorkflow::class)->startReview($measurement, $reviewer);

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
    $actor = measurementNotificationActor();
    $operation = Operation::factory()->create(['responsible_user_id' => $actor->id]);
    $operation->rejectionNotifyUsers()->attach([$watcherA->id, $watcherB->id]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'uploaded_by' => $uploader->id,
        'status' => 'in_review',
    ]);
    $measurement->reviews()->create([
        'stage' => 1,
        'reviewer_user_id' => $actor->id,
        'status' => 'pending',
    ]);

    app(MeasurementWorkflow::class)->reject($measurement, $actor, 'Faltam documentos');

    Notification::assertSentTo($uploader, MeasurementWorkflowNotification::class);
    Notification::assertSentTo($watcherA, MeasurementWorkflowNotification::class);
    Notification::assertSentTo($watcherB, MeasurementWorkflowNotification::class);
});

it('notifies the payment manager to attach receipts when a payment is registered', function () {
    Notification::fake();

    $manager = measurementNotificationActor();
    $operation = Operation::factory()->create(['payment_manager_user_id' => $manager->id]);
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'engineering_snapshot' => [
            'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
            'measurement_id' => 0,
            'operation_id' => $operation->id,
            'emission_id' => $operation->emission_id,
            'plan_sets' => [['plan_set_id' => $planSet->id, 'is_default' => true]],
        ],
    ]);
    $snapshot = $measurement->engineering_snapshot;
    $snapshot['measurement_id'] = $measurement->id;
    $measurement->forceFill(['engineering_snapshot' => $snapshot])->save();
    $measurement->reviews()->create([
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reviewer_user_id' => $manager->id,
        'status' => 'pending',
    ]);

    app(MeasurementWorkflow::class)->registerPayments($measurement, $manager, [
        ['amount' => 5000, 'pay_date' => now()],
    ]);

    Notification::assertSentTo(
        $manager,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'payment_registered',
    );
});

it('notifies the finalizer when the last receipt is attached', function () {
    Notification::fake();

    $manager = measurementNotificationActor();
    $uploader = measurementNotificationActor();
    $finalizer = User::factory()->create();
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_receipt_uploader_user_id' => $uploader->id,
        'payment_finalizer_user_id' => $finalizer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $measurement->reviews()->create(['stage' => 4, 'reviewer_user_id' => $manager->id, 'status' => 'approved']);
    $payment = $measurement->payments()->create([
        'operation_id' => $operation->id,
        'amount' => 1000,
        'pay_date' => now(),
        'created_by' => $manager->id,
    ]);

    Storage::disk('local')->put('measurements/receipts/r.pdf', '%PDF-1.7 receipt');
    app(MeasurementWorkflow::class)->attachReceipt($payment, $uploader, 'measurements/receipts/r.pdf', 'local');

    Notification::assertSentTo(
        $finalizer,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'ready_to_finalize',
    );
});

it('waits for every receipt before notifying the finalizer', function () {
    Notification::fake();

    $finalizer = User::factory()->create();
    $uploader = measurementNotificationActor();
    $operation = Operation::factory()->create([
        'payment_receipt_uploader_user_id' => $uploader->id,
        'payment_finalizer_user_id' => $finalizer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $measurement->reviews()->create(['stage' => 4, 'reviewer_user_id' => $uploader->id, 'status' => 'approved']);
    $paymentA = $measurement->payments()->create(['operation_id' => $operation->id, 'amount' => 100, 'pay_date' => now()]);
    $measurement->payments()->create(['operation_id' => $operation->id, 'amount' => 200, 'pay_date' => now()]);

    Storage::disk('local')->put('measurements/receipts/a.pdf', '%PDF-1.7 receipt-a');
    app(MeasurementWorkflow::class)->attachReceipt($paymentA, $uploader, 'measurements/receipts/a.pdf', 'local');

    Notification::assertNotSentTo($finalizer, MeasurementWorkflowNotification::class);
});

it('notifies the payment manager when the last stage is approved', function () {
    Notification::fake();

    $actor = measurementNotificationActor();
    $paymentManager = User::factory()->create();
    $operation = Operation::factory()->create([
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $paymentManager->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'in_review',
        'current_stage' => 3,
    ]);
    $measurement->reviews()->create(['stage' => 3, 'reviewer_user_id' => $actor->id, 'status' => 'pending']);

    app(MeasurementWorkflow::class)->approve($measurement, $actor);

    Notification::assertSentTo(
        $paymentManager,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $n): bool => $n->event === 'awaiting_payment',
    );
});
