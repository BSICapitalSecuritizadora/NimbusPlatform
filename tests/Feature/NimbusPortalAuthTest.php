<?php

use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
