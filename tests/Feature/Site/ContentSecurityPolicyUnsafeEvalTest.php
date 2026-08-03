<?php

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function cspOf(string $url): string
{
    return (string) test()->get($url)->headers->get('Content-Security-Policy');
}

// ── M-1: 'unsafe-eval' liberado por heurística de conteúdo ───────────────────

it('keeps unsafe-eval out of the CSP on public site pages (M-1)', function (string $routeName) {
    expect(cspOf(route($routeName)))->not->toContain("'unsafe-eval'");
})->with([
    'home' => 'site.home',
    'contact' => 'site.contact',
    'about' => 'site.about',
    'public documents' => 'public-documents',
    // Páginas que carregavam @fluxScripts sem usar nenhum componente Flux.
    'cri real estate' => 'site.imobiliario.cri',
    'emissao cri' => 'site.servicos.emissao-cri',
    'emissao cra' => 'site.servicos.emissao-cra',
    'estruturacao de operacoes' => 'site.servicos.estruturacao-operacoes',
    'atendimento especializado' => 'site.servicos.atendimento-especializado',
    'captacao de recursos' => 'site.servicos.captacao-recursos',
]);

it('no longer ships Flux scripts on public site pages (M-1)', function (string $routeName) {
    $this->get(route($routeName))
        ->assertSuccessful()
        ->assertDontSee('/flux/flux', false);
})->with([
    'cri real estate' => 'site.imobiliario.cri',
    'emissao cri' => 'site.servicos.emissao-cri',
    'emissao cra' => 'site.servicos.emissao-cra',
    'estruturacao de operacoes' => 'site.servicos.estruturacao-operacoes',
    'atendimento especializado' => 'site.servicos.atendimento-especializado',
    'captacao de recursos' => 'site.servicos.captacao-recursos',
]);

it('does not derive the CSP from the response body (M-1)', function () {
    Route::middleware('web')->get('/__csp-body-probe', fn () => response(
        '<html><body wire:snapshot="{}" wire:id="abc">'
        .'<script src="/livewire/livewire.min.js"></script>'
        .'<script src="/flux/flux.min.js"></script>'
        .'window.livewireScriptConfig'
        .'</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    ));

    expect(cspOf('/__csp-body-probe'))->not->toContain("'unsafe-eval'");
});

it('does not grant unsafe-eval to unmatched or missing routes (M-1)', function () {
    expect(cspOf('/rota-que-nao-existe'))->not->toContain("'unsafe-eval'");
});

it('keeps unsafe-eval only where Alpine is actually rendered (M-1)', function (string $url) {
    expect(cspOf($url))->toContain("'unsafe-eval'");
})->with([
    'filament admin login' => '/admin/login',
    'investor portal login' => '/investidor/login',
    'application login' => '/login',
]);

it('keeps the Alpine allow-list restricted to named route patterns (M-1)', function () {
    $reflection = new ReflectionClass(SetSecurityHeaders::class);

    expect($reflection->getConstant('UNSAFE_EVAL_ROUTES'))
        ->toBeArray()
        ->each->toBeString()
        ->and($reflection->getMethod('shouldAllowUnsafeEval')->getNumberOfParameters())
        ->toBe(1);
});
