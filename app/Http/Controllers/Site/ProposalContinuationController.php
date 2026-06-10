<?php

namespace App\Http\Controllers\Site;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyProposalContinuationRequest;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Services\DocumentStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProposalContinuationController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const CONTINUATION_RELATIONS = [
        'company.sectors',
        'contact',
        'projects.characteristics.unitTypes',
        'files',
    ];

    public function access(Request $request, ProposalContinuationAccess $access): View|RedirectResponse
    {
        abort_unless($request->hasValidSignature() && $access->isActive(), 403);

        $access->markLinkOpened();

        $request->session()->put($access->magicLinkSessionKey(), true);

        if ($this->hasAuthorizedContinuationSession($request, $access)) {
            return redirect()->route('site.proposal.continuation.form', $access);
        }

        return view('site.proposal.access', [
            'access' => $access,
            'proposal' => $this->loadProposalContinuation($access),
        ]);
    }

    public function verify(VerifyProposalContinuationRequest $request, ProposalContinuationAccess $access): RedirectResponse
    {
        $this->ensureMagicLinkConfirmed($request, $access);

        $access->markLinkOpened();

        $proposal = $this->loadProposalContinuation($access);

        // H-3: validate both fields together to prevent enumeration of which one is wrong
        $cnpjMatches = Str::digitsOnly($request->validated('cnpj')) === Str::digitsOnly((string) $proposal->company?->cnpj);
        $codeMatches = $access->matchesCode($request->validated('code'));

        if (! $cnpjMatches || ! $codeMatches) {
            throw ValidationException::withMessages([
                'code' => 'CNPJ ou código inválido.',
            ]);
        }

        $request->session()->put($access->verifiedSessionKey(), true);

        $access->markVerified();

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Acesso validado. Você já pode continuar o preenchimento.');
    }

    public function store(Request $request, ProposalContinuationAccess $access, StoreProposalContinuationData $storeProposalContinuationData): RedirectResponse
    {
        $this->ensureAuthorizedContinuation($request, $access);

        $proposal = $this->loadProposalContinuation($access);
        $this->ensureContinuationCanStore($proposal);

        $storeProposalContinuationData->handle(
            $proposal,
            StoreProposalContinuationDataDTO::fromFlatPayload($request->all()),
            $request->file('arquivos', []),
        );

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Empreendimento(s) salvo(s) com sucesso.');
    }

    public function downloadFile(Request $request, ProposalContinuationAccess $access, ProposalFile $file, DocumentStorageService $documentStorageService): StreamedResponse
    {
        $this->ensureAuthorizedContinuation($request, $access);

        abort_unless($file->proposal_id === $access->proposal_id, 404);
        abort_unless($documentStorageService->privateExists($file->file_path), 404);

        return $documentStorageService->downloadPrivate(
            $file->file_path,
            $file->original_name,
        );
    }

    private function loadProposalContinuation(ProposalContinuationAccess $access): Proposal
    {
        return $access->proposal()
            ->with(self::CONTINUATION_RELATIONS)
            ->firstOrFail();
    }

    private function hasAuthorizedContinuationSession(Request $request, ProposalContinuationAccess $access): bool
    {
        return $request->session()->has($access->verifiedSessionKey()) && $access->isActive();
    }

    private function ensureMagicLinkConfirmed(Request $request, ProposalContinuationAccess $access): void
    {
        abort_unless($request->session()->has($access->magicLinkSessionKey()) && $access->isActive(), 403);
    }

    private function ensureAuthorizedContinuation(Request $request, ProposalContinuationAccess $access): void
    {
        $this->ensureMagicLinkConfirmed($request, $access);

        abort_unless($this->hasAuthorizedContinuationSession($request, $access), 403);

        $access->markAuthorizedUsage();
    }

    private function ensureContinuationCanStore(Proposal $proposal): void
    {
        abort_unless($proposal->canBeCompletedByRequester(), 403);
    }
}
