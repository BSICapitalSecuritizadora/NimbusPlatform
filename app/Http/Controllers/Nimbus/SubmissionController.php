<?php

namespace App\Http\Controllers\Nimbus;

use App\Actions\Nimbus\CreateSubmission;
use App\Actions\Nimbus\DownloadPortalSubmissionFile;
use App\Actions\Nimbus\ListPortalSubmissions;
use App\Actions\Nimbus\ReplyToSubmission;
use App\Actions\Nimbus\ShowPortalSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nimbus\StoreSubmissionReplyRequest;
use App\Http\Requests\Nimbus\StoreSubmissionRequest;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SubmissionController extends Controller
{
    public function index(ListPortalSubmissions $listPortalSubmissions): View
    {
        $submissions = $listPortalSubmissions->handle($this->portalUser());

        return view('nimbus.submissions.index', compact('submissions'));
    }

    public function create(): View
    {
        return view('nimbus.submissions.create');
    }

    public function store(StoreSubmissionRequest $request, CreateSubmission $createSubmission): RedirectResponse
    {
        try {
            $submission = $createSubmission->handle($request->toDTO(), $this->portalUser());

            return redirect()->route('nimbus.submissions.show', $submission->id)
                ->with('success', 'Solicitação enviada com sucesso! Nossa equipe analisará os documentos em breve.');

        } catch (Throwable $e) {
            Log::error('Erro ao processar submissão do portal externo.', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'Ocorreu um erro ao processar sua solicitação. Por favor, tente novamente mais tarde.');
        }
    }

    public function show(Submission $submission, ShowPortalSubmission $showPortalSubmission): View
    {
        $submission = $showPortalSubmission->handle($submission, $this->portalUser());

        return view('nimbus.submissions.show', compact('submission'));
    }

    public function reply(
        StoreSubmissionReplyRequest $request,
        Submission $submission,
        ReplyToSubmission $replyToSubmission,
    ): RedirectResponse {
        $replyToSubmission->handle($request->toDTO(), $submission, $this->portalUser());

        return redirect()
            ->route('nimbus.submissions.show', $submission)
            ->with('success', 'Correção enviada com sucesso. Sua solicitação voltou para análise.');
    }

    public function downloadFile(
        Submission $submission,
        SubmissionFile $file,
        DownloadPortalSubmissionFile $downloadPortalSubmissionFile,
    ): StreamedResponse {
        Gate::forUser($this->portalUser())->authorize('downloadFile', [$submission, $file]);

        return $downloadPortalSubmissionFile->handle($submission, $file, $this->portalUser());
    }

    protected function portalUser(): PortalUser
    {
        /** @var PortalUser $user */
        $user = Auth::guard('nimbus')->user();

        return $user;
    }
}
