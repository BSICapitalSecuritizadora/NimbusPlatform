<?php

use App\Actions\Proposals\StoreProposalContinuationData;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Http\Controllers\Auth\AzureController;
use App\Http\Controllers\Nimbus\AdminSubmissionFileController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\JobController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Requests\VerifyProposalContinuationRequest;
use App\Livewire\Proposals\ContinuationForm;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Microsoft Azure Auth
Route::get('/auth/azure/redirect', [AzureController::class, 'redirect'])->name('auth.azure.redirect');
Route::get('/auth/azure/callback', [AzureController::class, 'callback'])->name('auth.azure.callback');

Route::get('/', [HomeController::class, 'index'])->name('site.home');

Route::view('/servicos', 'site.service')->name('site.services');
Route::view('/sobre', 'site.about')->name('site.about');
Route::view('/politica-de-privacidade', 'site.privacy-policy')->name('site.privacy-policy');
Route::view('/termos-de-uso', 'site.terms-of-use')->name('site.terms-of-use');
Route::get('/governanca', [SiteController::class, 'governance'])->name('site.governance');
Route::get('/compliance', [SiteController::class, 'complianceBsi'])->name('site.compliance');
Route::view('/contato', 'site.contact')->name('site.contact');

Route::get('/emissoes', [SiteController::class, 'emissions'])->name('site.emissions');
Route::get('/emissoes/{if_code}', [SiteController::class, 'emissionShow'])->name('site.emissions.show');
Route::get('/ri', [SiteController::class, 'ri'])->name('site.ri');

// Imobiliário
Route::view('/imobiliario/cri-real-estate', 'site.imobiliario.cri')->name('site.imobiliario.cri');
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
Route::view('/servicos/compliance', 'site.servicos.compliance')->name('site.servicos.compliance');

// Serviços > Tecnologia
Route::view('/servicos/documentos-acl', 'site.servicos.documentos-acl')->name('site.servicos.documentos-acl');
Route::view('/servicos/auditoria-acessos', 'site.servicos.auditoria-acessos')->name('site.servicos.auditoria-acessos');
Route::view('/servicos/integracoes', 'site.servicos.integracoes')->name('site.servicos.integracoes');

Route::get('/documentos-publicos', [PublicDocumentsController::class, 'index'])
    ->name('public-documents');

// Proposals (Integrated from NimbusForms)
$proposalContinuationRelations = [
    'company.sectors',
    'contact',
    'projects.characteristics.unitTypes',
    'files',
];

$loadProposalContinuation = static function (ProposalContinuationAccess $access) use ($proposalContinuationRelations): Proposal {
    return $access->proposal()
        ->with($proposalContinuationRelations)
        ->firstOrFail();
};

$hasAuthorizedContinuationSession = static function (Request $request, ProposalContinuationAccess $access): bool {
    return $request->session()->has($access->verifiedSessionKey()) && $access->isActive();
};

$ensureMagicLinkConfirmed = static function (Request $request, ProposalContinuationAccess $access): void {
    abort_unless($request->session()->has($access->magicLinkSessionKey()) && $access->isActive(), 403);
};

$ensureAuthorizedContinuation = static function (Request $request, ProposalContinuationAccess $access) use ($ensureMagicLinkConfirmed, $hasAuthorizedContinuationSession): void {
    $ensureMagicLinkConfirmed($request, $access);

    abort_unless($hasAuthorizedContinuationSession($request, $access), 403);

    $access->markAuthorizedUsage();
};

$ensureContinuationCanStore = static function (Proposal $proposal): void {
    abort_unless($proposal->canBeCompletedByRequester(), 403);
};

Route::redirect('/proposta', '/proposals/create')->name('site.proposal.create');
Route::get('/proposals/create', \App\Livewire\Proposals\CreateProposalForm::class)->name('proposal.create');
Route::get('/proposta/continuar/{access}', function (Request $request, ProposalContinuationAccess $access) use ($hasAuthorizedContinuationSession, $loadProposalContinuation) {
    abort_unless($request->hasValidSignature() && $access->isActive(), 403);

    $access->markLinkOpened();

    $request->session()->put($access->magicLinkSessionKey(), true);

    if ($hasAuthorizedContinuationSession($request, $access)) {
        return redirect()->route('site.proposal.continuation.form', $access);
    }

    return view('site.proposal.access', [
        'access' => $access,
        'proposal' => $loadProposalContinuation($access),
    ]);
})
    ->middleware('throttle:proposal-link-access')
    ->name('site.proposal.continuation.access');
Route::post('/proposta/continuar/{access}', function (VerifyProposalContinuationRequest $request, ProposalContinuationAccess $access) use ($ensureMagicLinkConfirmed, $loadProposalContinuation) {
    $ensureMagicLinkConfirmed($request, $access);

    $access->markLinkOpened();

    $proposal = $loadProposalContinuation($access);

    if (Str::digitsOnly($request->validated('cnpj')) !== Str::digitsOnly((string) $proposal->company?->cnpj)) {
        throw ValidationException::withMessages([
            'cnpj' => 'O CNPJ informado não corresponde à proposta enviada.',
        ]);
    }

    if (! $access->matchesCode($request->validated('code'))) {
        throw ValidationException::withMessages([
            'code' => 'O código informado é inválido.',
        ]);
    }

    $request->session()->put($access->verifiedSessionKey(), true);

    $access->markVerified();

    return redirect()
        ->route('site.proposal.continuation.form', $access)
        ->with('success', 'Acesso validado. Você já pode continuar o preenchimento.');
})
    ->middleware('throttle:proposal-verification')
    ->name('site.proposal.continuation.verify');
Route::get('/proposta/continuar/{access}/formulario', ContinuationForm::class)
    ->name('site.proposal.continuation.form');
Route::post('/proposta/continuar/{access}/formulario', function (Request $request, ProposalContinuationAccess $access, StoreProposalContinuationData $storeProposalContinuationData) use ($ensureAuthorizedContinuation, $ensureContinuationCanStore, $loadProposalContinuation) {
    $ensureAuthorizedContinuation($request, $access);

    $proposal = $loadProposalContinuation($access);
    $ensureContinuationCanStore($proposal);

    $storeProposalContinuationData->handle(
        $proposal,
        StoreProposalContinuationDataDTO::fromFlatPayload($request->all()),
        $request->file('arquivos', []),
    );

    return redirect()
        ->route('site.proposal.continuation.form', $access)
        ->with('success', 'Empreendimento(s) salvo(s) com sucesso.');
})
    ->middleware('throttle:proposal-continuation-store')
    ->name('site.proposal.continuation.store');
Route::get('/proposta/continuar/{access}/arquivos/{file}', function (
    Request $request,
    ProposalContinuationAccess $access,
    ProposalFile $file,
    DocumentStorageService $documentStorageService,
) use ($ensureAuthorizedContinuation) {
    $ensureAuthorizedContinuation($request, $access);

    abort_unless($file->proposal_id === $access->proposal_id, 404);
    abort_unless($documentStorageService->privateExists($file->file_path), 404);

    return $documentStorageService->downloadPrivate(
        $file->file_path,
        $file->original_name,
    );
})
    ->name('site.proposal.continuation.files.download');

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

Route::middleware(['auth'])->get('/pending-approval', fn () => view('pages.auth.pending-approval'))->name('pending-approval');

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Estudos de Caso (Públicos)
Route::get('/estudos-de-caso/{slug}', [App\Http\Controllers\Site\CaseStudyController::class, 'show'])->name('site.cases.show');

// Admin Routes
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/admin/projetos/{project}/relatorio', [App\Http\Controllers\Admin\ProjectReportController::class, 'generateReport'])->name('admin.projects.report');
    Route::get('/admin/projetos/{project}/analitico', [App\Http\Controllers\Admin\ProjectReportController::class, 'analyticalReport'])->name('admin.projects.analytical');
    Route::get('/admin/candidaturas/{jobApplication}/curriculo', [App\Http\Controllers\Admin\JobApplicationResumeController::class, 'download'])->name('admin.job-applications.resume');
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
        ->middleware('throttle:5,1')
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
