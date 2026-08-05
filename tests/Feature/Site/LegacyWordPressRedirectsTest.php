<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * O domínio serviu um WordPress com centenas de URLs indexadas. Estes testes
 * fixam o contrato da migração: o que existia continua alcançável, o que era
 * lixo continua respondendo 404, e nenhum redirect sombreia rota da aplicação.
 */
it('redirects the institutional pages that changed address', function (string $legacyPath, string $destination) {
    $this->get($legacyPath)
        ->assertStatus(301)
        ->assertRedirect($destination);
})->with([
    ['/r-i', '/ri'],
    ['/real-estate', '/imobiliario/cri-real-estate'],
    ['/somos-a-bsi-capital-securitizadora', '/sobre'],
    ['/fale-com-a-bsi', '/contato'],
    ['/securitizacao', '/servicos/estruturacao-de-operacoes'],
    ['/societarios', '/ri?category=societarios'],
    ['/faq', '/contato'],
    ['/case/securitizacao', '/servicos'],
]);

it('honours the trailing slash the WordPress URLs carried', function () {
    $this->get('/r-i/')
        ->assertStatus(301)
        ->assertRedirect('/ri');
});

it('sends the per-series pages to the emissions listing', function (string $legacyPath) {
    $this->get($legacyPath)
        ->assertStatus(301)
        ->assertRedirect('/emissoes');
})->with([
    '/1a-e-2a-serie',
    '/3a-serie',
    '/9a-serie',
    '/11a-12a-e-13a-serie',
    '/16a-17a-e-18a-serie',
    '/19a-serie-2',
    '/22a-emissao',
    '/26a-emissao',
]);

/**
 * Eram 621 URLs. O curinga cobre todas sem enumerá-las — inclusive as que o
 * WordPress publicava com caminho aninhado.
 */
it('sends every legacy document download to the public documents page', function (string $legacyPath) {
    $this->get($legacyPath)
        ->assertStatus(301)
        ->assertRedirect('/ri');
})->with([
    '/download/termo-de-securitizacao',
    '/download/segundo-aditivo-ts-rio-branco',
    '/download/agt-11-11-2021',
    '/download/relatorio-anual-2020',
    '/download/1-2-e-3-convocacao-para-assembleia-de-08-09-2021-diario-oficial-sp',
    '/download/algum/caminho/aninhado',
]);

/**
 * Redirecionar página morta para a home é soft 404 e os buscadores penalizam.
 * Estas eram rascunho, teste ou artefato de plugin — 404 é a resposta honesta.
 */
it('lets the leftover WordPress pages return 404 instead of a soft redirect', function (string $legacyPath) {
    $this->get($legacyPath)->assertNotFound();
})->with([
    '/login-customizer',
    '/remover',
    '/temporario-2',
    '/novo-lay-out-serie',
    '/39a-serie-teste',
    '/14a-serie-teste',
    '/testimonial',
]);

/**
 * Estes caminhos não mudaram de endereço. Um redirect aqui seria um salto
 * desnecessário em toda visita vinda de busca.
 */
it('serves the unchanged paths directly, with or without the trailing slash', function (string $path) {
    $this->get($path)->assertOk();
})->with([
    '/servicos',
    '/servicos/',
    '/governanca',
    '/governanca/',
    '/emissoes',
    '/emissoes/',
    '/contato',
    '/contato/',
]);

it('never lets a legacy redirect shadow an application route', function () {
    $this->get('/ri')->assertOk();
    $this->get('/sobre')->assertOk();
    $this->get('/imobiliario/cri-real-estate')->assertOk();
    $this->get('/servicos/estruturacao-de-operacoes')->assertOk();
});
