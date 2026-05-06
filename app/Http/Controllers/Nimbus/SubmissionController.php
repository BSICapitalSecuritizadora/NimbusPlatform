<?php

namespace App\Http\Controllers\Nimbus;

use App\Actions\Nimbus\CreateSubmission;
use App\Actions\Nimbus\DownloadPortalSubmissionFile;
use App\Actions\Nimbus\ListPortalSubmissions;
use App\Actions\Nimbus\ReplyToSubmission;
use App\Actions\Nimbus\ShowPortalSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nimbus\ListSubmissionIndexRequest;
use App\Http\Requests\Nimbus\StoreSubmissionReplyRequest;
use App\Http\Requests\Nimbus\StoreSubmissionRequest;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SubmissionController extends Controller
{
    private const SUBMISSIONS_PER_PAGE = 10;

    public function index(ListSubmissionIndexRequest $request, ListPortalSubmissions $listPortalSubmissions): View
    {
        $allSubmissions = $listPortalSubmissions->handle($this->portalUser());
        $operationFilter = $request->operationFilter();
        $periodFilter = $request->periodFilter();
        $scopedSubmissions = $this->applyScopeFilters($allSubmissions, $operationFilter, $periodFilter);
        $statusFilter = $request->statusFilter();
        $filteredSubmissions = $this->filterSubmissions($scopedSubmissions, $statusFilter);
        $submissions = $this->paginateSubmissions($filteredSubmissions);

        return view('nimbus.submissions.index', compact(
            'allSubmissions',
            'scopedSubmissions',
            'filteredSubmissions',
            'submissions',
            'statusFilter',
            'operationFilter',
            'periodFilter',
        ));
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

    /**
     * @param  Collection<int, Submission>  $submissions
     */
    protected function filterSubmissions(Collection $submissions, ?string $statusFilter): Collection
    {
        if ($statusFilter === null) {
            return $submissions;
        }

        return $submissions
            ->whereIn('status', $this->statusFilterMap()[$statusFilter] ?? [])
            ->values();
    }

    /**
     * @param  Collection<int, Submission>  $submissions
     */
    protected function applyScopeFilters(Collection $submissions, ?string $operationFilter, string $periodFilter): Collection
    {
        if ($operationFilter !== null) {
            $submissions = $submissions
                ->where('submission_type', $operationFilter)
                ->values();
        }

        if ($periodFilter === 'all') {
            return $submissions;
        }

        $cutoffDate = now()->subDays((int) $periodFilter)->startOfDay();

        return $submissions
            ->filter(fn (Submission $submission): bool => $submission->submitted_at?->greaterThanOrEqualTo($cutoffDate) ?? false)
            ->values();
    }

    /**
     * @param  Collection<int, Submission>  $submissions
     */
    protected function paginateSubmissions(Collection $submissions): LengthAwarePaginator
    {
        $currentPage = Paginator::resolveCurrentPage();
        $currentItems = $submissions
            ->forPage($currentPage, self::SUBMISSIONS_PER_PAGE)
            ->values();

        return new LengthAwarePaginator(
            $currentItems,
            $submissions->count(),
            self::SUBMISSIONS_PER_PAGE,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function statusFilterMap(): array
    {
        return [
            'pending' => [
                Submission::STATUS_PENDING,
                Submission::STATUS_NEEDS_CORRECTION,
            ],
            'under_review' => [Submission::STATUS_UNDER_REVIEW],
            'completed' => [Submission::STATUS_COMPLETED],
            'rejected' => [Submission::STATUS_REJECTED],
        ];
    }
}
