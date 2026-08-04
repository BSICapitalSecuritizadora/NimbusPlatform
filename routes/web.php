<?php

use App\Http\Controllers\Admin\AdminDocumentDownloadController;
use App\Http\Controllers\Admin\EmissionMonthlyReportController;
use App\Http\Controllers\Admin\EmissionPuCurveExportController;
use App\Http\Controllers\Admin\EmissionPuHomologationReportController;
use App\Http\Controllers\Admin\IntegralizationHistoryTemplateDownloadController;
use App\Http\Controllers\Admin\JobApplicationResumeController;
use App\Http\Controllers\Admin\ObligationEvidenceDownloadController;
use App\Http\Controllers\Admin\PaymentTemplateDownloadController;
use App\Http\Controllers\Admin\ProjectReportController;
use App\Http\Controllers\Admin\PuHistoryTemplateDownloadController;
use App\Http\Controllers\Auth\AzureController;
use App\Http\Controllers\Nimbus\AdminDocumentController;
use App\Http\Controllers\Nimbus\AdminSubmissionFileController;
use App\Http\Controllers\Nimbus\CnpjLookupController;
use App\Http\Controllers\Nimbus\DocumentController;
use App\Http\Controllers\Nimbus\NimbusDashboardController;
use App\Http\Controllers\Nimbus\PortalAuthController;
use App\Http\Controllers\Nimbus\SubmissionController;
use App\Http\Controllers\Operacional\ProposalDashboardController as OperacionalProposalDashboardController;
use App\Http\Controllers\Site\CaseStudyController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\JobController;
use App\Http\Controllers\Site\ProposalContinuationController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Site\SiteDocumentDownloadController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Livewire\Proposals\ContinuationForm;
use App\Livewire\Proposals\CreateProposalForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
Route::view('/canal-de-etica', 'site.canal-etica')->name('site.canal-etica');
Route::get('/documentos/{document}/download', SiteDocumentDownloadController::class)
    ->name('site.documents.download')
    ->middleware('throttle:30,1');
Route::view('/contato', 'site.contact')->name('site.contact');
Route::post('/contato', [SiteController::class, 'submitContact'])
    ->middleware('throttle:site-contact')
    ->name('site.contact.submit');

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
Route::view('/servicos/estruturacao-de-operacoes', 'site.servicos.estruturacao-operacoes')->name('site.servicos.estruturacao-operacoes');
Route::view('/servicos/captacao-de-recursos', 'site.servicos.captacao-recursos')->name('site.servicos.captacao-recursos');
Route::view('/servicos/emissao-coordenacao-cri', 'site.servicos.emissao-cri')->name('site.servicos.emissao-cri');
Route::view('/servicos/emissao-coordenacao-cra', 'site.servicos.emissao-cra')->name('site.servicos.emissao-cra');

// Serviços > Gestão
Route::view('/servicos/portal-do-investidor', 'site.servicos.portal-investidor')->name('site.servicos.portal-investidor');
Route::view('/servicos/relatorios', 'site.servicos.relatorios')->name('site.servicos.relatorios');
Route::permanentRedirect('/servicos/compliance', '/servicos/monitoramento-regulatorio');
Route::view('/servicos/monitoramento-regulatorio', 'site.servicos.compliance')->name('site.servicos.monitoramento-regulatorio');
Route::view('/servicos/atendimento-especializado', 'site.servicos.atendimento-especializado')->name('site.servicos.atendimento-especializado');

// Serviços > Tecnologia
Route::get('/servicos/documentos-acl', [SiteController::class, 'documentosAcl'])->name('site.servicos.documentos-acl');
Route::view('/servicos/auditoria-acessos', 'site.servicos.auditoria-acessos')->name('site.servicos.auditoria-acessos');
Route::view('/servicos/integracoes', 'site.servicos.integracoes')->name('site.servicos.integracoes');

Route::get('/documentos-publicos', [PublicDocumentsController::class, 'index'])
    ->name('public-documents');

// Proposals (Integrated from NimbusForms)
Route::redirect('/proposta', '/proposals/create')->name('site.proposal.create');
Route::get('/proposals/create', CreateProposalForm::class)->name('proposal.create');
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

/**
 * Healthcheck para o probe da plataforma / monitoramento.
 *
 * Cada requisição custa um `SELECT 1` e um par escrita/remoção no storage, então
 * o endpoint é limitado por IP: sem o limite, um GET anônimo repetido vira
 * amplificação barata de I/O de disco e de conexões de banco.
 *
 * O corpo detalhado (`checks`) diz qual dependência está degradada e por isso só
 * é devolvido a quem apresenta o token compartilhado. O probe não precisa dele:
 * lê o código HTTP e o campo `status`.
 */
Route::get('/healthcheck', function (Request $request) {
    $checks = [
        'app' => true,
        'database' => false,
        'storage' => false,
    ];

    try {
        DB::select('SELECT 1');
        $checks['database'] = true;
    } catch (Throwable) {
    }

    try {
        $disk = Storage::disk(config('filesystems.default'));
        // Caminho único por requisição: dois probes simultâneos não podem
        // apagar o arquivo um do outro e reportar falha por isso.
        $probePath = 'healthcheck/'.Str::random(24).'.txt';
        $disk->put($probePath, 'ok');
        $disk->delete($probePath);
        $checks['storage'] = true;
    } catch (Throwable) {
    }

    $healthy = ! in_array(false, $checks, true);

    $expectedToken = (string) config('app.healthcheck_token', '');
    $providedToken = (string) $request->header('X-Healthcheck-Token', '');
    $showsDetails = $expectedToken !== '' && hash_equals($expectedToken, $providedToken);

    return response()->json(array_filter([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $showsDetails ? $checks : null,
        'timestamp' => now()->toIso8601String(),
    ], static fn (mixed $value): bool => $value !== null), $healthy ? 200 : 503);
})->middleware('throttle:20,1')->name('healthcheck');

Route::middleware(['auth'])->get('/pending-approval', fn () => view('pages.auth.pending-approval'))->name('pending-approval');

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Operacional Interno (Fase 1 — POC Inertia/Vue, read-only, ao lado do Filament)
Route::middleware(['auth', 'approved', EnsureTwoFactorEnabled::class, HandleInertiaRequests::class])
    ->prefix('operacional')
    ->name('operacional.')
    ->group(function () {
        Route::get('/propostas', [OperacionalProposalDashboardController::class, 'index'])
            ->name('proposals.dashboard');
    });

// Estudos de Caso (Públicos)
Route::get('/estudos-de-caso/{slug}', [CaseStudyController::class, 'show'])->name('site.cases.show');

// Admin Routes
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/admin/projetos/{project}/relatorio', [ProjectReportController::class, 'generateReport'])->name('admin.projects.report');
    Route::get('/admin/projetos/{project}/analitico', [ProjectReportController::class, 'analyticalReport'])->name('admin.projects.analytical');
    Route::get('/admin/candidaturas/{jobApplication}/curriculo', [JobApplicationResumeController::class, 'download'])->name('admin.job-applications.resume');
    Route::get('/admin/documents/{document}/download', AdminDocumentDownloadController::class)
        ->name('admin.documents.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/obligations/evidences/{evidence}/download', ObligationEvidenceDownloadController::class)
        ->name('admin.obligations.evidences.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/emissions/{emission}/relatorio-mensal', EmissionMonthlyReportController::class)
        ->name('admin.emissions.monthly-report.pdf')
        ->middleware('throttle:30,1');
});

Route::middleware(['auth', 'approved', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('/admin/payments/template/download', PaymentTemplateDownloadController::class)
        ->name('admin.payments.template.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/pu-histories/template/download', PuHistoryTemplateDownloadController::class)
        ->name('admin.pu-histories.template.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/integralization-histories/template/download', IntegralizationHistoryTemplateDownloadController::class)
        ->name('admin.integralization-histories.template.download')
        ->middleware('throttle:60,1');
    Route::get('/admin/emissions/{emission}/pu-curves/export', EmissionPuCurveExportController::class)
        ->name('admin.emissions.pu-curves.export')
        ->middleware('throttle:30,1');
    Route::get('/admin/emissions/{emission}/pu-curves/{version}/homologacao', EmissionPuHomologationReportController::class)
        ->name('admin.emissions.pu-homologation.pdf')
        ->middleware('throttle:30,1');
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
                Route::get('/general/{document}/preview', [AdminDocumentController::class, 'previewGeneral'])->name('general.preview');
                Route::get('/general/{document}/download', [AdminDocumentController::class, 'downloadGeneral'])->name('general.download');
                Route::get('/portal/{document}/preview', [AdminDocumentController::class, 'previewPortal'])->name('portal.preview');
                Route::get('/portal/{document}/download', [AdminDocumentController::class, 'downloadPortal'])->name('portal.download');
            });
    });

require __DIR__.'/settings.php';
require __DIR__.'/investor.php';

Route::redirect('/nimbus', '/gestao-documental-externa/login');
Route::redirect('/nimbus/login', '/gestao-documental-externa/login');

// Gestão Documental Externa Portal Routes
Route::prefix('gestao-documental-externa')->name('nimbus.')->group(function () {
    // Auth Routes...
    Route::get('/login', [PortalAuthController::class, 'showRequestForm'])->name('auth.request');
    Route::post('/login', [PortalAuthController::class, 'verifyPin'])
        ->middleware(['throttle:5,1', 'throttle:nimbus-access-code'])
        ->name('auth.verify.post');
    Route::post('/sair', [PortalAuthController::class, 'logout'])->name('auth.logout');

    // Authenticated Portal Routes
    Route::middleware(['auth:nimbus'])->group(function () {
        Route::get('/dashboard', [NimbusDashboardController::class, 'index'])->name('dashboard');

        // Submissions
        Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/new', [SubmissionController::class, 'create'])->name('submissions.create');
        Route::post('/submissions/cnpj-lookup', CnpjLookupController::class)
            ->middleware('throttle:15,1')
            ->name('submissions.cnpj-lookup');
        Route::post('/submissions', [SubmissionController::class, 'store'])->name('submissions.store');
        Route::post('/submissions/{submission}/reply', [SubmissionController::class, 'reply'])->name('submissions.reply');
        Route::get('/submissions/{submission}/files/{file}/download', [SubmissionController::class, 'downloadFile'])->name('submissions.files.download');
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');

        // Documents
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::get('/documents/general/{document}/preview', [DocumentController::class, 'previewGeneral'])->name('documents.general.preview');
        Route::get('/documents/general/{document}/download', [DocumentController::class, 'downloadGeneral'])->name('documents.general.download');
    });
});
