<?php

use App\Actions\Emissions\RecalculateObligationStatusesAction;
use App\Actions\Emissions\SendObligationDueNotificationsAction;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-06-18 09:00:00'));
});

afterEach(function () {
    $this->travelBack();
});

function historyEntriesFor(Obligation $obligation, ?string $eventType = null): Illuminate\Database\Eloquent\Collection
{
    $query = $obligation->historyEntries()->getQuery();

    if ($eventType !== null) {
        $query->where('event_type', $eventType);
    }

    return $query->get();
}

function makeHistoryUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('records a created entry when an obligation is created manually', function () {
    $obligation = Obligation::factory()->create(['status' => 'em_dia']);

    $entries = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_CREATED);

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->source)->toBe(ObligationHistoryEntry::SOURCE_SYSTEM)
        ->and($entries->first()->new_values['status'])->toBe('em_dia');
});

it('records a generated_from_term entry with the confidence score', function () {
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->create(['confidence_score' => 0.92]);

    $obligation = Obligation::factory()->for($emission)->create([
        'extracted_obligation_id' => $suggestion->id,
    ]);

    $entry = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_GENERATED_FROM_TERM)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe(ObligationHistoryEntry::SOURCE_TERM_EXTRACTION)
        ->and($entry->metadata['confidence_score'])->toBe(0.92);
});

it('records a status change with old and new values', function () {
    $obligation = Obligation::factory()->create(['status' => 'a_vencer']);

    $obligation->update(['status' => 'concluida']);

    $entry = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_COMPLETED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_values['status'])->toBe('a_vencer')
        ->and($entry->new_values['status'])->toBe('concluida')
        ->and($entry->description)->toContain('Status alterado de A vencer para Concluída');
});

it('records a due date change', function () {
    $obligation = Obligation::factory()->create(['due_date' => null]);

    $obligation->update(['due_date' => '2026-07-15']);

    $entry = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_DUE_DATE_CHANGED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values['due_date'])->toBe('2026-07-15')
        ->and($entry->description)->toContain('15/07/2026');
});

it('records a recalculated_status entry when the scheduled recalculation changes a status', function () {
    $obligation = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => '2026-06-10',
    ]);

    app(RecalculateObligationStatusesAction::class)->handle();

    $entry = historyEntriesFor($obligation->fresh(), ObligationHistoryEntry::EVENT_RECALCULATED_STATUS)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe(ObligationHistoryEntry::SOURCE_SCHEDULED_COMMAND)
        ->and($entry->new_values['status'])->toBe('vencida');
});

it('records a notification_sent entry when a due notification is delivered', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    $obligation = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => '2026-06-25', // 7 dias
        'responsible_user_id' => $user->id,
    ]);

    app(SendObligationDueNotificationsAction::class)->handle();

    $entry = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_NOTIFICATION_SENT)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe(ObligationHistoryEntry::SOURCE_NOTIFICATION)
        ->and($entry->metadata['milestone'])->toBe('due_7')
        ->and($entry->metadata['recipient'])->toBe('resp@bsi.test');
});

it('records a notification_failed entry with a safe error summary', function () {
    $user = User::factory()->create(['email' => 'resp@bsi.test']);
    $obligation = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => '2026-06-25',
        'responsible_user_id' => $user->id,
    ]);

    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('SMTP connection refused'));

    app(SendObligationDueNotificationsAction::class)->handle();

    $entry = historyEntriesFor($obligation, ObligationHistoryEntry::EVENT_NOTIFICATION_FAILED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->metadata['error'])->toContain('SMTP connection refused')
        ->and($entry->description)->not->toContain('SMTP');
});

it('does not record history when only irrelevant fields change', function () {
    $obligation = Obligation::factory()->create();
    $baseline = $obligation->historyEntries()->count();

    $obligation->update(['notes' => 'Anotação interna sem relevância para o histórico.']);

    expect($obligation->historyEntries()->count())->toBe($baseline);
});

it('hides the obligation history from users without permission', function () {
    $emission = Emission::factory()->create();

    $this->actingAs(User::factory()->create());

    expect(ObligationsRelationManager::canViewForRecord($emission, EditEmission::class))->toBeFalse();
});

it('exposes the read-only history action to authorized users', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create(['status' => 'a_vencer']);
    $obligation->update(['status' => 'concluida']);

    $this->actingAs(makeHistoryUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewHistory->value,
    ]));

    Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionVisible('history', $obligation)
        ->mountTableAction('history', $obligation)
        ->assertHasNoErrors();
});

it('hides the history action when the user lacks the view history permission', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create(['status' => 'a_vencer']);

    $this->actingAs(makeHistoryUserWithPermissions([
        AccessPermission::ObligationsView->value,
    ]));

    Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionHidden('history', $obligation);
});

it('renders the timeline view with the obligation events', function () {
    $obligation = Obligation::factory()->create(['status' => 'a_vencer']);
    $obligation->update(['status' => 'concluida']);

    $html = view('filament.obligations.history-timeline', [
        'obligation' => $obligation,
        'entries' => $obligation->historyEntries()->with('user')->latest('occurred_at')->latest('id')->get(),
    ])->render();

    expect($html)->toContain('Obrigação concluída')
        ->and($html)->toContain('Status alterado de A vencer para Concluída')
        ->and($html)->toContain('Obrigação criada');
});
