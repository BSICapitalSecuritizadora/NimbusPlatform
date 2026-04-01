<?php

namespace App\Http\Controllers\Site;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyProposalContinuationRequest;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProposalContinuationController extends Controller
{
    public function showAccess(Request $request, ProposalContinuationAccess $access): View|RedirectResponse
    {
        abort_unless($request->hasValidSignature() && $access->isActive(), 403);

        $access->markLinkOpened();

        $request->session()->put($this->magicLinkSessionKey($access), true);

        if ($this->isAuthorized($request, $access)) {
            return redirect()->route('site.proposal.continuation.form', $access);
        }

        return view('site.proposal.access', [
            'access' => $access,
            'proposal' => $this->loadProposal($access),
        ]);
    }

    public function verify(
        VerifyProposalContinuationRequest $request,
        ProposalContinuationAccess $access,
    ): RedirectResponse {
        $this->ensureMagicLinkConfirmed($request, $access);

        $access->markLinkOpened();

        $proposal = $this->loadProposal($access);

        if ($this->normalizeCnpj($request->validated('cnpj')) !== $this->normalizeCnpj((string) $proposal->company?->cnpj)) {
            throw ValidationException::withMessages([
                'cnpj' => 'O CNPJ informado não corresponde à proposta enviada.',
            ]);
        }

        if (! $access->matchesCode($request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => 'O código informado é inválido.',
            ]);
        }

        $request->session()->put($this->verifiedSessionKey($access), true);

        $access->markVerified();

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Acesso validado. Você já pode continuar o preenchimento.');
    }

    public function showForm(Request $request, ProposalContinuationAccess $access): View
    {
        $this->ensureAuthorized($request, $access);

        return view('site.proposal.continuation', [
            'access' => $access,
            'proposal' => $this->loadProposal($access),
        ]);
    }

    public function store(
        Request $request,
        ProposalContinuationAccess $access,
        StoreProposalContinuationData $storeProposalContinuationData,
    ): RedirectResponse {
        $this->ensureAuthorized($request, $access);

        $proposal = $this->loadProposal($access);
        $this->ensureCanStoreFromRequester($proposal);

        $storeProposalContinuationData->handle(
            $proposal,
            StoreProposalContinuationData::fromFlatPayload($request->all()),
            $request->file('arquivos', []),
        );

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Empreendimento(s) salvo(s) com sucesso.');
    }

    public function downloadFile(
        Request $request,
        ProposalContinuationAccess $access,
        ProposalFile $file,
    ) {
        $this->ensureAuthorized($request, $access);

        abort_unless($file->proposal_id === $access->proposal_id, 404);

        return Storage::disk($file->disk)->download($file->file_path, $file->original_name);
    }

    protected function loadProposal(ProposalContinuationAccess $access): Proposal
    {
        return $access->proposal()
            ->with([
                'company.sectors',
                'contact',
                'projects.characteristics.unitTypes',
                'files',
            ])
            ->firstOrFail();
    }

    protected function ensureAuthorized(Request $request, ProposalContinuationAccess $access): void
    {
        $this->ensureMagicLinkConfirmed($request, $access);

        abort_unless($this->isAuthorized($request, $access), 403);

        $access->markAuthorizedUsage();
    }

    protected function ensureMagicLinkConfirmed(Request $request, ProposalContinuationAccess $access): void
    {
        abort_unless($request->session()->has($this->magicLinkSessionKey($access)) && $access->isActive(), 403);
    }

    protected function ensureCanStoreFromRequester(Proposal $proposal): void
    {
        abort_unless($proposal->canBeCompletedByRequester(), 403);
    }

    protected function isAuthorized(Request $request, ProposalContinuationAccess $access): bool
    {
        return $request->session()->has($this->verifiedSessionKey($access)) && $access->isActive();
    }

    protected function magicLinkSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_magic_link.{$access->id}";
    }

    protected function verifiedSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_verified.{$access->id}";
    }

    protected function normalizeCnpj(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
