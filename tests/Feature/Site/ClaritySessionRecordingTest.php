<?php

use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const CLARITY_SCRIPT_HOST = 'clarity.ms';

beforeEach(function (): void {
    config(['services.clarity.id' => 'test-clarity-id']);
});

it('loads the session recorder on ordinary public pages', function () {
    $this->get(route('site.home'))
        ->assertOk()
        ->assertSee(CLARITY_SCRIPT_HOST, false);
});

/**
 * O Canal de Ética recebe denúncias. Gravar movimento de ponteiro, cliques e
 * conteúdo de tela de quem denuncia anula a finalidade do canal — e é o tipo de
 * tratamento que dificilmente se sustenta em legítimo interesse.
 */
it('never loads the session recorder on the whistleblowing channel', function () {
    $this->get(route('site.canal-etica'))
        ->assertOk()
        ->assertDontSee(CLARITY_SCRIPT_HOST, false);
});

it('never loads the session recorder on authentication screens', function (string $routeName) {
    $this->get(route($routeName))
        ->assertOk()
        ->assertDontSee(CLARITY_SCRIPT_HOST, false);
})->with([
    'login',
    'investor.login',
    'nimbus.auth.request',
]);

it('never loads the session recorder on the proposal form', function () {
    $this->get(route('proposal.create'))
        ->assertOk()
        ->assertDontSee(CLARITY_SCRIPT_HOST, false);
});

it('stays out of the markup entirely when no project id is configured', function () {
    config(['services.clarity.id' => null]);

    $this->get(route('site.home'))
        ->assertOk()
        ->assertDontSee(CLARITY_SCRIPT_HOST, false);
});

it('keeps every authenticated area out of the session recorder', function () {
    $excluded = (array) config('services.clarity.excluded_routes');

    expect($excluded)
        ->toContain('site.canal-etica')
        ->toContain('filament.*')
        ->toContain('investor.*')
        ->toContain('nimbus.*')
        ->toContain('login')
        ->toContain('password.*')
        ->toContain('two-factor.*');
});

it('still records the public emission pages, which carry no personal data', function () {
    $emission = Emission::factory()->active()->create([
        'if_code' => 'IF-CLARITY-01',
        'is_public' => true,
    ]);

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee(CLARITY_SCRIPT_HOST, false);
});
