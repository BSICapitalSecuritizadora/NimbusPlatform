<?php

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\JobController;
use App\Http\Controllers\Site\ProposalController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('site.home');

Route::get('/servicos', [SiteController::class, 'services'])->name('site.services');
Route::get('/sobre', [SiteController::class, 'about'])->name('site.about');
Route::get('/governanca', [SiteController::class, 'governance'])->name('site.governance');
Route::get('/compliance', [SiteController::class, 'complianceBsi'])->name('site.compliance');
Route::get('/contato', [SiteController::class, 'contact'])->name('site.contact');

Route::get('/emissoes', [SiteController::class, 'emissions'])->name('site.emissions');
Route::get('/emissoes/{if_code}', [SiteController::class, 'emissionShow'])->name('site.emissions.show');
Route::get('/ri', [SiteController::class, 'ri'])->name('site.ri');

// Imobiliário
Route::get('/imobiliario/cri-real-estate', [SiteController::class, 'criRealEstate'])->name('site.imobiliario.cri');
Route::get('/imobiliario/loteamentos', [SiteController::class, 'loteamentos'])->name('site.imobiliario.loteamentos');
Route::get('/imobiliario/incorporacao', [SiteController::class, 'incorporacao'])->name('site.imobiliario.incorporacao');

// Agronegócio
Route::get('/agronegocio/cra', [SiteController::class, 'cra'])->name('site.agronegocio.cra');
Route::get('/agronegocio/cooperativas', [SiteController::class, 'cooperativas'])->name('site.agronegocio.cooperativas');
Route::get('/agronegocio/projetos', [SiteController::class, 'projetos'])->name('site.agronegocio.projetos');

// Infra & Empresas
Route::get('/infra-empresas/cr-futuro', [SiteController::class, 'crFuturo'])->name('site.infra.cr');
Route::get('/infra-empresas/recebiveis', [SiteController::class, 'recebiveis'])->name('site.infra.recebiveis');
Route::get('/infra-empresas/estruturacao-sob-medida', [SiteController::class, 'estruturacaoSobMedida'])->name('site.infra.estruturacao');

// Serviços > Estruturação
Route::get('/servicos/originacao', [SiteController::class, 'originacao'])->name('site.servicos.originacao');
Route::get('/servicos/estrutura-juridica', [SiteController::class, 'estruturaJuridica'])->name('site.servicos.estrutura-juridica');
Route::get('/servicos/registro-distribuicao', [SiteController::class, 'registroDistribuicao'])->name('site.servicos.registro-distribuicao');

// Serviços > Gestão
Route::get('/servicos/portal-do-investidor', [SiteController::class, 'portalInvestidor'])->name('site.servicos.portal-investidor');
Route::get('/servicos/relatorios', [SiteController::class, 'relatorios'])->name('site.servicos.relatorios');
Route::get('/servicos/compliance', [SiteController::class, 'compliance'])->name('site.servicos.compliance');

// Serviços > Tecnologia
Route::get('/servicos/documentos-acl', [SiteController::class, 'documentosAcl'])->name('site.servicos.documentos-acl');
Route::get('/servicos/auditoria-acessos', [SiteController::class, 'auditoriaAcessos'])->name('site.servicos.auditoria-acessos');
Route::get('/servicos/integracoes', [SiteController::class, 'integracoes'])->name('site.servicos.integracoes');

Route::get('/documentos-publicos', [PublicDocumentsController::class, 'index'])
    ->name('public-documents');

// Proposals (Integrated from NimbusForms)
Route::get('/proposta', [ProposalController::class, 'create'])->name('site.proposal.create');
Route::post('/proposta', [ProposalController::class, 'store'])->name('site.proposal.store');

// Recruitment (Trabalhe Conosco)
Route::get('/trabalhe-conosco', [JobController::class, 'index'])->name('site.vacancies.index');
Route::get('/trabalhe-conosco/{slug}', [JobController::class, 'show'])->name('site.vacancies.show');
Route::post('/trabalhe-conosco/{id}/candidatar', [JobController::class, 'apply'])->name('site.vacancies.apply');

// Healthcheck para staging / monitoramento
Route::get('/healthcheck', function () {
    $checks = [
        'app' => true,
        'database' => false,
        'storage' => false,
    ];

    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
        $checks['database'] = true;
    } catch (\Throwable) {
    }

    try {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        $disk->put('healthcheck.txt', 'ok');
        $disk->delete('healthcheck.txt');
        $checks['storage'] = true;
    } catch (\Throwable) {
    }

    $healthy = ! in_array(false, $checks, true);

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
})->name('healthcheck');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Estudos de Caso (Públicos)
Route::get('/estudos-de-caso/{slug}', [App\Http\Controllers\Site\CaseStudyController::class, 'show'])->name('site.cases.show');

// Admin Reports
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/projetos/{project}/relatorio', [App\Http\Controllers\Admin\ProjectReportController::class, 'generateReport'])->name('admin.projects.report');
    Route::get('/admin/projetos/{project}/analitico', [App\Http\Controllers\Admin\ProjectReportController::class, 'analyticalReport'])->name('admin.projects.analytical');
});

require __DIR__.'/settings.php';
require __DIR__.'/investor.php';
