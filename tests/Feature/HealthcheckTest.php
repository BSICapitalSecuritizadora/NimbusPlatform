<?php

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(RateLimiter::class)->clear(sha1('healthcheck|127.0.0.1'));
    Storage::fake(config('filesystems.default'));
});

it('reports the application as healthy', function () {
    $this->getJson('/healthcheck')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

/**
 * O corpo detalhado diz qual dependência está degradada — é reconhecimento de
 * infraestrutura de graça para um visitante anônimo.
 */
it('hides the dependency breakdown from callers without the shared token', function () {
    config(['app.healthcheck_token' => 'probe-token']);

    $this->getJson('/healthcheck')
        ->assertOk()
        ->assertJsonMissingPath('checks');
});

it('returns the dependency breakdown to the platform probe', function () {
    config(['app.healthcheck_token' => 'probe-token']);

    $this->getJson('/healthcheck', ['X-Healthcheck-Token' => 'probe-token'])
        ->assertOk()
        ->assertJsonPath('checks.app', true)
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.storage', true);
});

it('does not accept a wrong token as the platform probe', function () {
    config(['app.healthcheck_token' => 'probe-token']);

    $this->getJson('/healthcheck', ['X-Healthcheck-Token' => 'wrong-token'])
        ->assertOk()
        ->assertJsonMissingPath('checks');
});

/**
 * Cada requisição custa um SELECT e um par escrita/remoção no storage; sem
 * limite, um GET anônimo repetido vira amplificação barata de I/O.
 */
it('throttles repeated healthcheck requests from the same address', function () {
    for ($request = 1; $request <= 20; $request++) {
        $this->getJson('/healthcheck')->assertOk();
    }

    $this->getJson('/healthcheck')->assertStatus(429);
});

it('does not leave the probe file behind on the default disk', function () {
    $this->getJson('/healthcheck')->assertOk();

    expect(Storage::disk(config('filesystems.default'))->allFiles('healthcheck'))->toBe([]);
});
