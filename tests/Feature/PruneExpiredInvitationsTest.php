<?php

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeInvitation(array $attributes = []): Invitation
{
    $user = User::factory()->create();

    return Invitation::create(array_merge([
        'email' => fake()->safeEmail(),
        'token' => Str::random(32),
        'invited_by' => $user->id,
        'expires_at' => now()->addDays(7),
        'used_at' => null,
    ], $attributes));
}

it('prunes expired invitations older than the given days', function () {
    makeInvitation(['expires_at' => now()->subDays(31)]);
    makeInvitation(['expires_at' => now()->subDays(31)]);
    $recent = makeInvitation(['expires_at' => now()->subDays(10)]);

    $this->artisan('invitations:prune --days=30')->assertSuccessful();

    expect(Invitation::count())->toBe(1);
    expect(Invitation::find($recent->id))->not->toBeNull();
});

it('does not prune accepted invitations even if expired', function () {
    makeInvitation(['expires_at' => now()->subDays(60), 'used_at' => now()->subDays(1)]);

    $this->artisan('invitations:prune --days=30')->assertSuccessful();

    expect(Invitation::count())->toBe(1);
});

it('does not prune recently expired invitations within the grace period', function () {
    makeInvitation(['expires_at' => now()->subDays(5)]);

    $this->artisan('invitations:prune --days=30')->assertSuccessful();

    expect(Invitation::count())->toBe(1);
});
