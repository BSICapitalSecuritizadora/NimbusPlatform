<?php

use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the external document management login URL and redirects the legacy URL', function () {
    expect(route('nimbus.auth.request', absolute: false))
        ->toBe('/gestao-documental-externa/login');

    $this->get('/nimbus/login')
        ->assertRedirect('/gestao-documental-externa/login');
});

it('authenticates a portal user with a hyphenated access code', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente do Portal',
        'email' => 'cliente.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $accessToken = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code' => '6AB4-371C-DA8A',
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => '6ab4-371c-da8a',
    ])
        ->assertRedirect(route('nimbus.dashboard'));

    expect(auth('nimbus')->id())->toBe($portalUser->id)
        ->and($accessToken->fresh()->status)->toBe('USED')
        ->and($accessToken->fresh()->used_at)->not->toBeNull()
        ->and($portalUser->fresh()->last_login_method)->toBe('ACCESS_CODE');
});

it('does not authenticate with an already used access code', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente do Portal',
        'email' => 'cliente.usado@example.com',
        'document_number' => '12345678902',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code' => '6AB4-371C-DA8B',
        'status' => 'USED',
        'expires_at' => now()->addDays(7),
        'used_at' => now()->subMinute(),
    ]);

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => '6AB4-371C-DA8B',
    ])
        ->assertSessionHasErrors('access_code');

    expect(auth('nimbus')->check())->toBeFalse();
});
