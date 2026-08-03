<?php

beforeEach(function () {
    app()->detectEnvironment(fn (): string => 'production');
});

it('redirects a plain http request to https in production, keeping path and query string', function () {
    $this->get('http://bsicapital.com.br/politica-de-privacidade?utm_source=news')
        ->assertStatus(301)
        ->assertRedirect('https://bsicapital.com.br/politica-de-privacidade?utm_source=news');
});

it('redirects non-safe methods without losing the request body', function () {
    $this->post('http://bsicapital.com.br/contato', ['name' => 'Fulano'])
        ->assertStatus(308)
        ->assertRedirect('https://bsicapital.com.br/contato');
});

it('does not redirect a request that already arrived over https', function () {
    $this->get('https://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful();
});

it('does not redirect when the proxy reports https, so an untrusted proxy cannot cause a loop', function () {
    config(['app.url' => 'http://bsicapital.com.br']);

    $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful();

    $this->withHeader('X-Forwarded-Proto', 'https, http')
        ->get('http://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful();
});

it('keeps the platform health probes answering over plain http', function (string $path) {
    $this->get("http://bsicapital.com.br{$path}")
        ->assertSuccessful();
})->with([
    'framework health route' => '/up',
    'application healthcheck' => '/healthcheck',
]);

it('does not redirect outside production', function () {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('http://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful();
});

it('can be switched off when the edge already redirects', function () {
    config(['app.force_https_redirect' => false]);

    $this->get('http://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful();
});

it('still sends the hsts header on the secure response', function () {
    $this->get('https://bsicapital.com.br/politica-de-privacidade')
        ->assertSuccessful()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
