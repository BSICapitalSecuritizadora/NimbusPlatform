<?php

use App\Models\Investor;
use App\Models\Nimbus\PortalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('web')->get('/__security/authenticated-cache-probe', fn () => response('ok'));
});

it('prevents authenticated responses from being stored by browsers and proxies', function (Closure $createUser, string $guard) {
    $response = $this->actingAs($createUser(), $guard)
        ->get('/__security/authenticated-cache-probe');

    $response->assertSuccessful()
        ->assertHeader('Pragma', 'no-cache');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('private')
        ->toContain('max-age=0');
})->with([
    'application user' => [fn () => User::factory()->create(), 'web'],
    'investor' => [fn () => Investor::factory()->create(), 'investor'],
    'Nimbus portal user' => [fn () => PortalUser::query()->create([
        'full_name' => 'Cliente Nimbus',
        'email' => 'cliente-cache@example.com',
        'document_number' => '12345678901',
        'status' => 'ACTIVE',
    ]), 'nimbus'],
]);

it('does not apply the restrictive private cache policy to public responses', function () {
    $response = $this->get('/__security/authenticated-cache-probe');

    $response->assertSuccessful();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});
