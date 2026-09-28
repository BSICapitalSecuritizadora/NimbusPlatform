<?php

use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('returns all records when date filters are empty', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $activity1 = Activity::create([
        'log_name' => 'default',
        'description' => 'Acao 1',
        'created_at' => '2026-08-15 10:00:00',
    ]);

    $activity2 = Activity::create([
        'log_name' => 'default',
        'description' => 'Acao 2',
        'created_at' => '2026-09-15 12:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->assertCanSeeTableRecords([$activity1, $activity2]);
});

it('filters correctly by only created_from (start date only)', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $before = Activity::create([
        'log_name' => 'default',
        'description' => 'Before start date',
        'created_at' => '2026-08-31 23:59:59',
    ]);

    $onStart = Activity::create([
        'log_name' => 'default',
        'description' => 'On start date',
        'created_at' => '2026-09-01 00:00:00',
    ]);

    $after = Activity::create([
        'log_name' => 'default',
        'description' => 'After start date',
        'created_at' => '2026-09-15 12:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_from' => '2026-09-01',
        ])
        ->assertCanSeeTableRecords([$onStart, $after])
        ->assertCanNotSeeTableRecords([$before]);
});

it('filters correctly by only created_until (end date only) including end of day 23:59:59', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $activity1 = Activity::create([
        'log_name' => 'default',
        'description' => 'Morning of end date',
        'created_at' => '2026-09-30 08:00:00',
    ]);

    $endOfDayActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'End of day record',
        'created_at' => '2026-09-30 23:59:59',
    ]);

    $nextDay = Activity::create([
        'log_name' => 'default',
        'description' => 'Next day record',
        'created_at' => '2026-10-01 00:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_until' => '2026-09-30',
        ])
        ->assertCanSeeTableRecords([$activity1, $endOfDayActivity])
        ->assertCanNotSeeTableRecords([$nextDay]);
});

it('filters correctly by complete date interval (created_from and created_until)', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $before = Activity::create([
        'log_name' => 'default',
        'description' => 'Before range',
        'created_at' => '2026-08-31 23:59:59',
    ]);

    $start = Activity::create([
        'log_name' => 'default',
        'description' => 'Start of range',
        'created_at' => '2026-09-01 09:00:00',
    ]);

    $middle = Activity::create([
        'log_name' => 'default',
        'description' => 'Middle of range',
        'created_at' => '2026-09-15 12:00:00',
    ]);

    $end = Activity::create([
        'log_name' => 'default',
        'description' => 'End of range',
        'created_at' => '2026-09-30 23:59:59',
    ]);

    $after = Activity::create([
        'log_name' => 'default',
        'description' => 'After range',
        'created_at' => '2026-10-01 00:00:01',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_from' => '2026-09-01',
            'created_until' => '2026-09-30',
        ])
        ->assertCanSeeTableRecords([$start, $middle, $end])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('filters correctly when both dates are set to the same day', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $morning = Activity::create([
        'log_name' => 'default',
        'description' => 'Morning',
        'created_at' => '2026-09-15 08:30:00',
    ]);

    $night = Activity::create([
        'log_name' => 'default',
        'description' => 'Night',
        'created_at' => '2026-09-15 23:45:00',
    ]);

    $otherDay = Activity::create([
        'log_name' => 'default',
        'description' => 'Previous day',
        'created_at' => '2026-09-14 23:59:59',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_from' => '2026-09-15',
            'created_until' => '2026-09-15',
        ])
        ->assertCanSeeTableRecords([$morning, $night])
        ->assertCanNotSeeTableRecords([$otherDay]);
});

it('handles invalid interval safely returning no records', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'Any record',
        'created_at' => '2026-09-15 12:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_from' => '2026-09-30',
            'created_until' => '2026-09-01',
        ])
        ->assertCanNotSeeTableRecords([$activity]);
});

it('resets date filter when resetting table filters', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    $activity1 = Activity::create([
        'log_name' => 'default',
        'description' => 'Acao 1',
        'created_at' => '2026-08-01 10:00:00',
    ]);

    $activity2 = Activity::create([
        'log_name' => 'default',
        'description' => 'Acao 2',
        'created_at' => '2026-09-15 12:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->filterTable('created_at', [
            'created_from' => '2026-09-01',
            'created_until' => '2026-09-30',
        ])
        ->assertCanSeeTableRecords([$activity2])
        ->assertCanNotSeeTableRecords([$activity1])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$activity1, $activity2]);
});

it('combines date filter with causer_id user filter without conflict', function () {
    $user1 = User::factory()->create(['name' => 'Usuario Um', 'approved_at' => now(), 'is_active' => true]);
    $user2 = User::factory()->create(['name' => 'Usuario Dois', 'approved_at' => now(), 'is_active' => true]);
    $user1->givePermissionTo('audit.activities.view');

    $activityUser1InRange = Activity::create([
        'log_name' => 'default',
        'description' => 'User 1 in range',
        'causer_id' => $user1->id,
        'causer_type' => User::class,
        'created_at' => '2026-09-10 10:00:00',
    ]);

    $activityUser2InRange = Activity::create([
        'log_name' => 'default',
        'description' => 'User 2 in range',
        'causer_id' => $user2->id,
        'causer_type' => User::class,
        'created_at' => '2026-09-12 10:00:00',
    ]);

    $activityUser1OutOfRange = Activity::create([
        'log_name' => 'default',
        'description' => 'User 1 out of range',
        'causer_id' => $user1->id,
        'causer_type' => User::class,
        'created_at' => '2026-08-10 10:00:00',
    ]);

    Livewire::actingAs($user1)
        ->test(ManageActivities::class)
        ->filterTable('causer_id', $user1->id)
        ->filterTable('created_at', [
            'created_from' => '2026-09-01',
            'created_until' => '2026-09-30',
        ])
        ->assertCanSeeTableRecords([$activityUser1InRange])
        ->assertCanNotSeeTableRecords([$activityUser2InRange, $activityUser1OutOfRange]);
});
