<?php

use Illuminate\Routing\ViewController;
use Illuminate\Support\Facades\Route;

it('renders static public site pages through route views', function (string $routeName, string $view) {
    $this->get(route($routeName))
        ->assertSuccessful()
        ->assertViewIs($view);
})->with([
    'services overview' => ['site.services', 'site.service'],
    'about page' => ['site.about', 'site.about'],
    'contact page' => ['site.contact', 'site.contact'],
    'cri real estate page' => ['site.imobiliario.cri', 'site.imobiliario.cri'],
    'loteamentos page' => ['site.imobiliario.loteamentos', 'site.imobiliario.loteamentos'],
    'incorporacao page' => ['site.imobiliario.incorporacao', 'site.imobiliario.incorporacao'],
    'cra page' => ['site.agronegocio.cra', 'site.agronegocio.cra'],
    'cooperativas page' => ['site.agronegocio.cooperativas', 'site.agronegocio.cooperativas'],
    'projetos page' => ['site.agronegocio.projetos', 'site.agronegocio.projetos'],
    'cr futuro page' => ['site.infra.cr', 'site.infra-empresas.cr-futuro'],
    'recebiveis page' => ['site.infra.recebiveis', 'site.infra-empresas.recebiveis'],
    'estruturacao sob medida page' => ['site.infra.estruturacao', 'site.infra-empresas.estruturacao'],
    'originacao page' => ['site.servicos.originacao', 'site.servicos.originacao'],
    'estrutura juridica page' => ['site.servicos.estrutura-juridica', 'site.servicos.estrutura-juridica'],
    'registro distribuicao page' => ['site.servicos.registro-distribuicao', 'site.servicos.registro-distribuicao'],
    'portal do investidor page' => ['site.servicos.portal-investidor', 'site.servicos.portal-investidor'],
    'relatorios page' => ['site.servicos.relatorios', 'site.servicos.relatorios'],
    'servicos compliance page' => ['site.servicos.compliance', 'site.servicos.compliance'],
    'documentos acl page' => ['site.servicos.documentos-acl', 'site.servicos.documentos-acl'],
    'auditoria acessos page' => ['site.servicos.auditoria-acessos', 'site.servicos.auditoria-acessos'],
    'integracoes page' => ['site.servicos.integracoes', 'site.servicos.integracoes'],
]);

it('maps static public site pages to the laravel view controller', function (string $routeName) {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and(ltrim($route->getActionName(), '\\'))->toBe(ViewController::class);
})->with([
    'site.services',
    'site.about',
    'site.contact',
    'site.imobiliario.cri',
    'site.imobiliario.loteamentos',
    'site.imobiliario.incorporacao',
    'site.agronegocio.cra',
    'site.agronegocio.cooperativas',
    'site.agronegocio.projetos',
    'site.infra.cr',
    'site.infra.recebiveis',
    'site.infra.estruturacao',
    'site.servicos.originacao',
    'site.servicos.estrutura-juridica',
    'site.servicos.registro-distribuicao',
    'site.servicos.portal-investidor',
    'site.servicos.relatorios',
    'site.servicos.compliance',
    'site.servicos.documentos-acl',
    'site.servicos.auditoria-acessos',
    'site.servicos.integracoes',
]);
