<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('redirects guests to login', function () {
    $this->get(route('operacional.proposals.dashboard'))
        ->assertRedirect();
});

it('forbids approved users without proposal access', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('operacional.proposals.dashboard'))
        ->assertForbidden();
});

it('renders the inertia proposals dashboard for an authorized admin', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('operacional.proposals.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Operacional/Propostas/Index')
            ->has('summary')
            ->has('summary.total')
            ->has('statusDistribution')
            ->has('recent')
        );
});

it('also authorizes a commercial representative', function () {
    $representative = User::factory()->withTwoFactor()->create();
    $representative->assignRole('commercial-representative');

    $this->actingAs($representative)
        ->get(route('operacional.proposals.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Operacional/Propostas/Index'));
});
