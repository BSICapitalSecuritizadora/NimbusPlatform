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

it('renders the Nimbus login page with a nonce-protected inline script', function () {
    $response = $this->get(route('nimbus.auth.request'));

    $response->assertSuccessful();

    expect($response->getContent())
        ->toMatch('/<script nonce="[^"]*">\\s*\\/\\/ Access Code Formatter/s');
});

it('authenticates a portal user with a hyphenated access code', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente do Portal',
        'email' => 'cliente.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $plainCode = '6AB4-371C-DA8A';

    $accessToken = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash($plainCode),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => strtolower($plainCode),
    ])
        ->assertRedirect(route('nimbus.dashboard'));

    expect(auth('nimbus')->id())->toBe($portalUser->id)
        ->and($accessToken->fresh()->status)->toBe('USED')
        ->and($accessToken->fresh()->used_at)->not->toBeNull()
        ->and($portalUser->fresh()->last_login_method)->toBe('ACCESS_CODE');
});

it('regenerates the session after successful login to prevent session fixation', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Fixação',
        'email' => 'fixacao@example.com',
        'document_number' => '12345678903',
        'phone_number' => '11999999998',
        'status' => 'ACTIVE',
    ]);

    $plainCode = 'AAAA-BBBB-CCCC';

    AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash($plainCode),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);

    $beforeSessionId = session()->getId();

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => $plainCode,
    ])->assertRedirect(route('nimbus.dashboard'));

    expect(session()->getId())->not->toBe($beforeSessionId);
});

it('does not authenticate with an already used access code', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente do Portal',
        'email' => 'cliente.usado@example.com',
        'document_number' => '12345678902',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $plainCode = '6AB4-371C-DA8B';

    AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash($plainCode),
        'status' => 'USED',
        'expires_at' => now()->addDays(7),
        'used_at' => now()->subMinute(),
    ]);

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => $plainCode,
    ])
        ->assertSessionHasErrors('access_code');

    expect(auth('nimbus')->check())->toBeFalse();
});

it('does not authenticate with a code that has no matching hash in the database (plaintext lookup gone)', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Hacker',
        'email' => 'hacker@example.com',
        'document_number' => '99999999999',
        'phone_number' => '11000000000',
        'status' => 'ACTIVE',
    ]);

    // Simulates a legacy row with only the plaintext code but no code_hash
    AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code' => 'LEAK-LEAK-LEAK',
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('nimbus.auth.verify.post'), [
        'access_code' => 'LEAK-LEAK-LEAK',
    ])
        ->assertSessionHasErrors('access_code');

    expect(auth('nimbus')->check())->toBeFalse();
});
