<?php

use App\Actions\Proposals\StoreProposalContinuationData;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\JobController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Requests\VerifyProposalContinuationRequest;
use App\Livewire\Proposals\ContinuationForm;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

$magicLinkSessionKey = static fn (ProposalContinuationAccess $access): string => "proposal_magic_link.{$access->id}";
$verifiedSessionKey = static fn (ProposalContinuationAccess $access): string => "proposal_verified.{$access->id}";

$hasAuthorizedContinuationSession = static function (Request $request, ProposalContinuationAccess $access) use ($verifiedSessionKey): bool {
    return $request->session()->has($verifiedSessionKey($access)) && $access->isActive();
};

$ensureMagicLinkConfirmed = static function (Request $request, ProposalContinuationAccess $access) use ($magicLinkSessionKey): void {
    abort_unless($request->session()->has($magicLinkSessionKey($access)) && $access->isActive(), 403);
};

$ensureAuthorizedContinuation = static function (Request $request, ProposalContinuationAccess $access) use ($ensureMagicLinkConfirmed, $hasAuthorizedContinuationSession): void {
    $ensureMagicLinkConfirmed($request, $access);

    abort_unless($hasAuthorizedContinuationSession($request, $access), 403);

    $access->markAuthorizedUsage();
};

$ensureContinuationCanStore = static function (Proposal $proposal): void {
    abort_unless($proposal->canBeCompletedByRequester(), 403);
};

$normalizeProposalCnpj = static fn (string $value): string => preg_replace('/\D/', '', $value) ?? '';

Route::redirect('/proposta', '/proposals/create')->name('site.proposal.create');
Route::get('/proposals/create', \App\Livewire\Proposals\CreateProposalForm::class)->name('proposal.create');
Route::get('/proposta/continuar/{access}', function (Request $request, ProposalContinuationAccess $access) use ($hasAuthorizedContinuationSession, $loadProposalContinuation, $magicLinkSessionKey) {
    abort_unless($request->hasValidSignature() && $access->isActive(), 403);

    $access->markLinkOpened();

    $request->session()->put($magicLinkSessionKey($access), true);

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
Route::post('/proposta/continuar/{access}', function (VerifyProposalContinuationRequest $request, ProposalContinuationAccess $access) use ($ensureMagicLinkConfirmed, $loadProposalContinuation, $normalizeProposalCnpj, $verifiedSessionKey) {
    $ensureMagicLinkConfirmed($request, $access);

    $access->markLinkOpened();

    $proposal = $loadProposalContinuation($access);

    if ($normalizeProposalCnpj($request->validated('cnpj')) !== $normalizeProposalCnpj((string) $proposal->company?->cnpj)) {
        throw ValidationException::withMessages([
            'cnpj' => 'O CNPJ informado não corresponde à proposta enviada.',
        ]);
    }

    if (! $access->matchesCode($request->validated('code'))) {
        throw ValidationException::withMessages([
            'code' => 'O código informado é inválido.',
        ]);
    }

    $request->session()->put($verifiedSessionKey($access), true);

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
Route::get('/proposta/continuar/{access}/arquivos/{file}', function (Request $request, ProposalContinuationAccess $access, ProposalFile $file) use ($ensureAuthorizedContinuation) {
    $ensureAuthorizedContinuation($request, $access);

    abort_unless($file->proposal_id === $access->proposal_id, 404);

    return Storage::disk($file->disk)->download($file->file_path, $file->original_name);
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

// Admin Reports
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/projetos/{project}/relatorio', [App\Http\Controllers\Admin\ProjectReportController::class, 'generateReport'])->name('admin.projects.report');
    Route::get('/admin/projetos/{project}/analitico', [App\Http\Controllers\Admin\ProjectReportController::class, 'analyticalReport'])->name('admin.projects.analytical');
});

require __DIR__.'/settings.php';
require __DIR__.'/investor.php';

// NimbusDocs Portal Routes
Route::prefix('nimbus')->name('nimbus.')->group(function () {
    // Auth Routes...
    Route::get('/login', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'showRequestForm'])->name('auth.request');
    Route::post('/login', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'verifyPin'])->name('auth.verify.post');
    Route::post('/sair', [\App\Http\Controllers\Nimbus\PortalAuthController::class, 'logout'])->name('auth.logout');

    // Authenticated Portal Routes
    Route::middleware(['auth:nimbus'])->group(function () {
        Route::get('/dashboard', function () {
            $user = \Illuminate\Support\Facades\Auth::guard('nimbus')->user();

            // Dummy data for now before we implement the full controller queries
            $stats = [
                'total' => \App\Models\Nimbus\Submission::where('nimbus_portal_user_id', $user->id)->count(),
                'pending' => \App\Models\Nimbus\Submission::where('nimbus_portal_user_id', $user->id)->whereIn('status', ['PENDING', 'UNDER_REVIEW'])->count(),
                'approved' => \App\Models\Nimbus\Submission::where('nimbus_portal_user_id', $user->id)->where('status', 'APPROVED')->count(),
            ];

            $submissions = \App\Models\Nimbus\Submission::where('nimbus_portal_user_id', $user->id)
                ->orderByDesc('submitted_at')
                ->limit(5)
                ->get()
                ->toArray();

            return view('nimbus.dashboard', compact('stats', 'submissions'));
        })->name('dashboard');

        // Submissions
        Route::get('/submissions', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/new', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'create'])->name('submissions.create');
        Route::post('/submissions/cnpj-lookup', \App\Http\Controllers\Nimbus\CnpjLookupController::class)
            ->middleware('throttle:15,1')
            ->name('submissions.cnpj-lookup');
        Route::post('/submissions', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'store'])->name('submissions.store');
        Route::get('/submissions/{submission}', [\App\Http\Controllers\Nimbus\SubmissionController::class, 'show'])->name('submissions.show');

        // Documents
        Route::get('/documents', [\App\Http\Controllers\Nimbus\DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}/download', [\App\Http\Controllers\Nimbus\DocumentController::class, 'download'])->name('documents.download');
    });
});
