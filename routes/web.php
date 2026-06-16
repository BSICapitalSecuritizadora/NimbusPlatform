<?php

use App\Http\Controllers\Auth\AzureController;
use App\Http\Controllers\Nimbus\AdminSubmissionFileController;
use App\Http\Controllers\Operacional\ProposalDashboardController as OperacionalProposalDashboardController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\JobController;
use App\Http\Controllers\Site\ProposalContinuationController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Site\SiteDocumentDownloadController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Livewire\Proposals\ContinuationForm;
use Illuminate\Support\Facades\Route;

// Microsoft Azure Auth
Route::get('/auth/azure/redirect', [AzureController::class, 'redirect'])->name('auth.azure.redirect');
Route::get('/auth/azure/callback', [AzureController::class, 'callback'])->name('auth.azure.callback');

Route::get('/', [HomeController::class, 'index'])->name('site.home');

Route::view('/servicos', 'site.service')->name('site.services');
Route::view('/servicos/servicer', 'site.servicer')->name('site.servicos.servicer');
Route::view('/intelligence', 'site.intelligence.index')->name('site.intelligence');
Route::view('/sobre', 'site.about')->name('site.about');
Route::view('/parcerias', 'site.partnerships')->name('site.partnerships');
Route::view('/politica-de-privacidade', 'site.privacy-policy')->name('site.privacy-policy');
Route::view('/termos-de-uso', 'site.terms-of-use')->name('site.terms-of-use');
Route::get('/governanca', [SiteController::class, 'governance'])->name('site.governance');
Route::get('/compliance', [SiteController::class, 'complianceBsi'])->name('site.compliance');
Route::get('/documentos/{document}/download', SiteDocumentDownloadController::class)->name('site.documents.download');
Route::view('/contato', 'site.contact')->name('site.contact');
Route::post('/contato', [SiteController::class, 'submitContact'])->name('site.contact.submit');

Route::get('/emissoes', [SiteController::class, 'emissions'])->name('site.emissions');
Route::get('/emissoes/{if_code}', [SiteController::class, 'emissionShow'])->name('site.emissions.show');
Route::get('/ri', [SiteController::class, 'ri'])->name('site.ri');

// Imobiliário
Route::get('/imobiliario/cri-real-estate', [SiteController::class, 'criRealEstate'])->name('site.imobiliario.cri');
Route::view('/imobiliario/loteamentos', 'site.imobiliario.loteamentos')->name('site.imobiliario.loteamentos');
Route::view('/imobiliario/incorporacao', 'site.imobiliario.incorporacao')->name('site.imobiliario.incorporacao');

// Agronegócio
Route::view('/agronegocio/cra', 'site.agronegocio.cra')->name('site.agronegocio.cra');
Route::view('/agronegocio/cooperativas', 'site.agronegocio.cooperativas')->name('site.agronegocio.cooperativas');
Route::view('/agronegocio/projetos', 'site.agronegocio.projetos')->name('site.agronegocio.projetos');

// Infra & Empresas
Route::view('/infra-empresas/cr-futuro', 'site.infra-empresas.cr-futuro')->name('site.infra.cr');
Route::view('/infra-empresas/recebiveis', 'site.infra-empresas.recebiveis')->name('site.infra.recebiveis');
Route::view('/infra-empresas/estruturacao-sob-medida', 'site.infra-empresas.estruturacao')->name('site.infra.estruturacao');

// Serviços > Estruturação
Route::view('/servicos/originacao', 'site.servicos.originacao')->name('site.servicos.originacao');
Route::view('/servicos/estrutura-juridica', 'site.servicos.estrutura-juridica')->name('site.servicos.estrutura-juridica');
Route::view('/servicos/registro-distribuicao', 'site.servicos.registro-distribuicao')->name('site.servicos.registro-distribuicao');

// Serviços > Gestão
Route::view('/servicos/portal-do-investidor', 'site.servicos.portal-investidor')->name('site.servicos.portal-investidor');
Route::view('/servicos/relatorios', 'site.servicos.relatorios')->name('site.servicos.relatorios');
Route::permanentRedirect('/servicos/compliance', '/servicos/monitoramento-regulatorio');
Route::view('/servicos/monitoramento-regulatorio', 'site.servicos.compliance')->name('site.servicos.monitoramento-regulatorio');

// Serviços > Tecnologia
Route::get('/servicos/documentos-acl', [SiteController::class, 'documentosAcl'])->name('site.servicos.documentos-acl');
Route::view('/servicos/auditoria-acessos', 'site.servicos.auditoria-acessos')->name('site.servicos.auditoria-acessos');
Route::view('/servicos/integracoes', 'site.servicos.integracoes')->name('site.servicos.integracoes');

Route::get('/documentos-publicos', [PublicDocumentsController::class, 'index'])
    ->name('public-documents');

// Proposals (Integrated from NimbusForms)
Route::redirect('/proposta', '/proposals/create')->name('site.proposal.create');
Route::get('/proposals/create', \App\Livewire\Proposals\CreateProposalForm::class)->name('proposal.create');
Route::get('/proposta/continuar/{access}', [ProposalContinuationController::class, 'access'])
    ->middleware('throttle:proposal-link-access')
    ->name('site.proposal.continuation.access');
Route::post('/proposta/continuar/{access}', [ProposalContinuationController::class, 'verify'])
    ->middleware(['throttle:proposal-verification', 'throttle:proposal-verification-global'])
    ->name('site.proposal.continuation.verify');
Route::get('/proposta/continuar/{access}/formulario', ContinuationForm::class)
    ->name('site.proposal.continuation.form');
Route::post('/proposta/continuar/{access}/formulario', [ProposalContinuationController::class, 'store'])
    ->middleware('throttle:proposal-continuation-store')
    ->name('site.proposal.continuation.store');
Route::get('/proposta/continuar/{access}/arquivos/{file}', [ProposalContinuationController::class, 'downloadFile'])
    ->middleware('throttle:proposal-continuation-download')
    ->name('site.proposal.continuation.files.download');

// Recruitment (Trabalhe Conosco)
Route::get('/trabalhe-conosco', [JobController::class, 'index'])->name('site.vacancies.index');
Route::get('/trabalhe-conosco/{slug}', [JobController::class, 'show'])->name('site.vacancies.show');
Route::post('/trabalhe-conosco/{id}/candidatar', [JobController::class, 'apply'])
    ->middleware('throttle:site-job-apply')
    ->name('site.vacancies.apply');

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

Route::middleware(['auth'])->get('/pending-approval', fn () => view('pages.auth.pending-approval'))->name('pending-approval');

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Operacional Interno (Fase 1 — POC Inertia/Vue, read-only, ao lado do Filament)
Route::middleware(['auth', 'approved', EnsureTwoFactorEnabled::class, \App\Http\Middleware\HandleInertiaRequests::class])
    ->prefix('operacional')
    ->name('operacional.')
    ->group(function () {
        Route::get('/propostas', [OperacionalProposalDashboardController::class, 'index'])
            ->name('proposals.dashboard');
    });

// Estudos de Caso (Públicos)
Route::get('/estudos-de-caso/{slug}', [App\Http\Controllers\Site\CaseStudyController::class, 'show'])->name('site.cases.show');

// Admin Routes
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/admin/projetos/{project}/relatorio', [App\Http\Controllers\Admin\ProjectReportController::class, 'generateReport'])->name('admin.projects.report');
    Route::get('/admin/projetos/{project}/analitico', [App\Http\Controllers\Admin\ProjectReportController::class, 'analyticalReport'])->name('admin.projects.analytical');
    Route::get('/admin/candidaturas/{jobApplication}/curriculo', [App\Http\Controllers\Admin\JobApplicationResumeController::class, 'download'])->name('admin.job-applications.resume');
    Route::get('/admin/documents/{document}/download', App\Http\Controllers\Admin\AdminDocumentDownloadController::class)
        ->name('admin.documents.download')
        ->middleware('throttle:60,1');
});

Route::middleware(['auth', 'approved', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('/admin/payments/template/download', App\Http\Controllers\Admin\PaymentTemplateDownloadController::class)
        ->name('admin.payments.template.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/pu-histories/template/download', App\Http\Controllers\Admin\PuHistoryTemplateDownloadController::class)
        ->name('admin.pu-histories.template.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/integralization-histories/template/download', App\Http\Controllers\Admin\IntegralizationHistoryTemplateDownloadController::class)
        ->name('admin.integralization-histories.template.download')
        ->middleware('throttle:60,1');
});

Route::redirect('/admin/nimbus-dashboard', '/admin/gestao-documental-externa-dashboard');
Route::redirect('/admin/nimbus/submissions', '/admin/gestao-documental-externa/submissions');

Route::middleware(['auth', EnsureTwoFactorEnabled::class])
    ->prefix('/admin/gestao-documental-externa')
    ->name('admin.nimbus.')
    ->group(function () {
        Route::prefix('/submissions')
            ->name('submissions.')
            ->group(function () {
                Route::post('/{submission}/response-files', [AdminSubmissionFileController::class, 'storeResponseFiles'])->name('response-files.store');

                Route::prefix('/files')
                    ->name('files.')
                    ->group(function () {
                        Route::get('/{file}/preview', [AdminSubmissionFileController::class, 'preview'])->name('preview');
                        Route::get('/{file}/download', [AdminSubmissionFileController::class, 'download'])->name('download');
                    });
            });

        Route::prefix('/documents')
            ->name('documents.')
            ->group(function () {
                Route::get('/general/{document}/preview', [\App\Http\Controllers\Nimbus\AdminDocumentController::class, 'previewGeneral'])->name('general.preview');
                Route::get('/general/{document}/download', [\App\Http\Controllers\Nimbus\AdminDocumentController::class, 'downloadGeneral'])->name('general.download');
                Route::get('/portal/{document}/preview', [\App\Http\Controllers\Nimbus\AdminDocumentController::class, 'previewPortal'])->name('portal.preview');
                Route::get('/portal/{document}/download', [\App\Http\Controllers\Nimbus\AdminDocumentController::class, 'downloadPortal'])->name('portal.download');
            });
    });

require __DIR__.'/settings.php';
require __DIR__.'/investor.php';

Route::redirect('/nimbus', '/gestao-documental-externa/login');
Route::redirect('/nimbus/login', '/gestao-documental-externa/login');

// Gestão Documental Externa Portal Routes
Route::prefix('gestao-documental-externa')->name('nimbus.')->group(function () {
    // Auth Routes...
    Route::get('/login', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'showRequestForm'])->name('auth.request');
    Route::post('/login', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'verifyPin'])
        ->middleware(['throttle:5,1', 'throttle:nimbus-access-code'])
        ->name('auth.verify.post');
    Route::post('/sair', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'logout'])->name('auth.logout');

    // Authenticated Portal Routes
    Route::middleware(['auth:nimbus'])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Nimbus\NimbusDashboardController::class, 'index'])->name('dashboard');

        // Submissions
        Route::get('/submissions', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/new', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'create'])->name('submissions.create');
        Route::post('/submissions/cnpj-lookup', \App\Http\Controllers\Nimbus\CnpjLookupController::class)
            ->middleware('throttle:15,1')
            ->name('submissions.cnpj-lookup');
        Route::post('/submissions', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'store'])->name('submissions.store');
        Route::post('/submissions/{submission}/reply', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'reply'])->name('submissions.reply');
        Route::get('/submissions/{submission}/files/{file}/download', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'downloadFile'])->name('submissions.files.download');
        Route::get('/submissions/{submission}', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'show'])->name('submissions.show');

        // Documents
        Route::get('/documents', [\App\Http\Controllers\Nimbus\DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}/preview', [\App\Http\Controllers\Nimbus\DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('/documents/{document}/download', [\App\Http\Controllers\Nimbus\DocumentController::class, 'download'])->name('documents.download');
        Route::get('/documents/general/{document}/preview', [\App\Http\Controllers\Nimbus\DocumentController::class, 'previewGeneral'])->name('documents.general.preview');
        Route::get('/documents/general/{document}/download', [\App\Http\Controllers\Nimbus\DocumentController::class, 'downloadGeneral'])->name('documents.general.download');
    });
});
