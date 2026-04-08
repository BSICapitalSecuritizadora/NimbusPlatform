<?php

it('redirects users to microsoft azure authentication', function () {
    config()->set('services.azure', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'http://localhost/auth/azure/callback',
        'tenant' => 'test-tenant',
    ]);

    $response = $this->get(route('auth.azure.redirect'));

    $response->assertRedirect();

    expect($response->headers->get('Location'))
        ->toStartWith('https://login.microsoftonline.com/test-tenant/oauth2/v2.0/authorize?')
        ->toContain('client_id=test-client-id')
        ->toContain('redirect_uri=http%3A%2F%2Flocalhost%2Fauth%2Fazure%2Fcallback');
});
